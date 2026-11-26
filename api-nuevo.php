<?php
/*
 * api.php · único punto de entrada del servidor
 *
 *   POST api.php?accion=login       iniciar sesión
 *   POST api.php?accion=registro    crear cuenta
 *   GET  api.php?accion=logout      cerrar sesión
 *   GET  api.php?accion=coleccion   colección del usuario (JSON)
 *   POST api.php?accion=coleccion   abrir un sobre (periodo=jurasico|cretacico|triasico)
 *   GET  api.php?accion=dinos       catálogo completo (opcional &periodo=jurasico)
 */
session_start();
require __DIR__ . '/conexion-nuevo.php';

const CARTAS_POR_SOBRE = 5;
const ESPERA_SEGUNDOS  = 86400; // tiempo de espera entre sobres (24 h)


/* ---------- Utilidades ---------- */

function ir($url) {
    header('Location: ' . $url);
    exit;
}

function responder($datos, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

function quitarTildes($s) {
    return strtr(mb_strtolower((string)$s, 'UTF-8'),
        ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
}

// Ejecuta una consulta o CALL y devuelve todas las filas (cerrando el cursor,
// necesario con procedimientos almacenados).
function consultar(PDO $pdo, $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->fetchAll();
    $stmt->closeCursor();
    return $filas;
}

function catalogo(PDO $pdo) {
    return consultar($pdo, 'CALL ObtenerDinosaurios()');
}

function coleccionDe(PDO $pdo, $usuario) {
    return consultar($pdo, 'CALL ObtenerColeccion(?)', [$usuario]);
}

function segundosRestantes(PDO $pdo, $usuario) {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(GREATEST(0, ? - TIMESTAMPDIFF(SECOND, ultimo_sobre, NOW())), 0) AS r
         FROM usuarios WHERE username = ?'
    );
    $stmt->execute([ESPERA_SEGUNDOS, $usuario]);
    return (int)$stmt->fetchColumn();
}


/* ---------- Sesión: login, registro y logout ---------- */

function login(PDO $pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ir('index-nuevo.html');

    $login = trim($_POST['email'] ?? '');   // correo o nombre de usuario
    $pass  = $_POST['password'] ?? '';

    try {
        // 2 marcadores (?, ?) → 2 valores. @eror va escrito en el SQL, no en el array.
        $filas = consultar($pdo, 'CALL Inicio2(?, ?, @eror)', [$login, '']);
    } catch (PDOException $e) {
        error_log('Login: ' . $e->getMessage());   // para ver el error real en el log de PHP
        ir('index-nuevo.html?error=login');
    }
    $fila = $filas[0] ?? null;

    if ($fila && password_verify($pass, $fila['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['username'] = $fila['username'];
        ir('cartas-nuevo.html');
    }
    ir('index-nuevo.html?error=login');

}
function registro(PDO $pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ir('index-nuevo.html');

    $usuario    = trim($_POST['username'] ?? '');
    $email      = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $contrasena = $_POST['password'] ?? '';
    $repetida   = $_POST['password_repetida'] ?? '';

    if ($contrasena !== $repetida || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ir('index-nuevo.html?error=registro');
    }

    try {
        $hash = password_hash($contrasena, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare('CALL Registro2(?, ?, ?, @eror)');
        $stmt->execute([$usuario, $email, $hash]);
        $stmt->closeCursor();

        $res    = $pdo->query('SELECT @eror AS eror')->fetch();
        $codigo = isset($res['eror']) ? (int)$res['eror'] : -999;

        if ($codigo === 0) {
            session_regenerate_id(true);
            $_SESSION['username'] = $usuario;
            ir('cartas-nuevo.html');
        }
    } catch (PDOException $e) {
        // por ejemplo, correo duplicado
    }
    ir('index-nuevo.html?error=registro');
}

function logout() {
    $_SESSION = [];
    session_destroy();
    ir('index-nuevo.html');
}


/* ---------- Colección y sobres ---------- */

// Abre un sobre: comprueba la espera y guarda las cartas en una sola transacción.
// La fila del usuario se bloquea (FOR UPDATE) para que dos peticiones a la vez
// no puedan abrir dos sobres.
function abrirSobre(PDO $pdo, $usuario, $periodo) {
    $pool = array_values(array_filter(
        catalogo($pdo),
        fn($d) => quitarTildes($d['periodo']) === $periodo
    ));
    if (!$pool) responder(['error' => 'Periodo no válido'], 400);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT id, COALESCE(GREATEST(0, ? - TIMESTAMPDIFF(SECOND, ultimo_sobre, NOW())), 0) AS restante
         FROM usuarios WHERE username = ? FOR UPDATE'
    );
    $stmt->execute([ESPERA_SEGUNDOS, $usuario]);
    $u = $stmt->fetch();

    if (!$u) {
        $pdo->rollBack();
        responder(['error' => 'Usuario no encontrado'], 401);
    }
    if ((int)$u['restante'] > 0) {
        $pdo->rollBack();
        responder(['error' => 'Aún no puedes abrir otro sobre', 'restante' => (int)$u['restante']], 429);
    }

    shuffle($pool);
    $sacadas = array_slice($pool, 0, CARTAS_POR_SOBRE);

    $ins = $pdo->prepare(
        'INSERT INTO colecciones (usuario_id, dinosaurio_id)
         SELECT ?, id FROM dinosaurios WHERE nombre = ?'
    );
    foreach ($sacadas as $d) {
        $ins->execute([$u['id'], $d['nombre']]);
    }
    $pdo->prepare('UPDATE usuarios SET ultimo_sobre = NOW() WHERE id = ?')->execute([$u['id']]);
    $pdo->commit();

    return $sacadas;
}

function coleccion(PDO $pdo) {
    if (empty($_SESSION['username'])) {
        responder(['error' => 'No has iniciado sesión'], 401);
    }
    $usuario = $_SESSION['username'];
    $sacadas = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sacadas = abrirSobre($pdo, $usuario, quitarTildes($_POST['periodo'] ?? ''));
    }

    responder([
        'usuario'   => $usuario,
        'sacadas'   => $sacadas,
        'coleccion' => coleccionDe($pdo, $usuario),
        'restante'  => segundosRestantes($pdo, $usuario),
    ]);
}


/* ---------- Catálogo ---------- */

function dinos(PDO $pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        responder(['error' => 'Método no permitido'], 405);
    }
    $lista = catalogo($pdo);

    if (!empty($_GET['periodo'])) {
        $periodo = quitarTildes($_GET['periodo']);
        $lista = array_values(array_filter(
            $lista,
            fn($d) => quitarTildes($d['periodo']) === $periodo
        ));
    }
    responder($lista);
}
/* ---------- Tradeo ---------- */

function usuarioId(PDO $pdo) {
    if (empty($_SESSION['username'])) {
        responder(['error' => 'No has iniciado sesión'], 401);
    }
    $f = consultar($pdo, 'SELECT id FROM usuarios WHERE username = ?', [$_SESSION['username']]);
    if (!$f) responder(['error' => 'Usuario no encontrado'], 401);
    return (int)$f[0]['id'];
}

// POST nombre=... -> res: -1 no existe, -2 eres tú, 0 esperando, 1 emparejados
function buscarOponente(PDO $pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder(['error' => 'Método no permitido'], 405);
    }
    $yo     = usuarioId($pdo);
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre === '') responder(['res' => -1]);

    $stmt = $pdo->prepare('CALL BuscarOponente(?, ?, @res)');
    $stmt->execute([$yo, $nombre]);
    $stmt->closeCursor();

    $res = (int)$pdo->query('SELECT @res')->fetchColumn();
    responder(['res' => $res]);
}

// GET -> la negociación abierta en la que estoy, o null
// "3,7,12" -> [3, 7, 12]  (solo enteros positivos)
function idsCartas($texto) {
    $ids = array_map('intval', explode(',', (string)$texto));
    return array_values(array_filter($ids, fn($n) => $n > 0));
}

// ¿Tiene el usuario todas esas cartas?
function tieneCartas(PDO $pdo, $usuarioId, array $ids) {
    $filas = consultar($pdo,
        'SELECT dinosaurio_id, COUNT(*) AS c FROM colecciones
         WHERE usuario_id = ? GROUP BY dinosaurio_id', [$usuarioId]);
    $tiene = [];
    foreach ($filas as $f) $tiene[(int)$f['dinosaurio_id']] = (int)$f['c'];
    foreach (array_count_values($ids) as $id => $necesita) {
        if (($tiene[$id] ?? 0) < $necesita) return false;
    }
    return true;
}

// Mi negociación abierta (o null). Con $bloquear, bloquea la fila.
function negociacionAbierta(PDO $pdo, $yo, $bloquear = false) {
    $sql = "SELECT * FROM negociacion
            WHERE estado = 'abierto' AND ? IN (fk_usuario_A, fk_usuario_B)
            LIMIT 1" . ($bloquear ? ' FOR UPDATE' : '');
    $f = consultar($pdo, $sql, [$yo]);
    return $f[0] ?? null;
}

// GET: estado de mi negociación, con las cartas del rival
function estadoMatchmaking(PDO $pdo) {
    $yo  = usuarioId($pdo);
    $neg = negociacionAbierta($pdo, $yo);
    if (!$neg) responder(['negociacion' => null]);

    $soyA    = (int)$neg['fk_usuario_A'] === $yo;
    $rivalId = (int)($soyA ? $neg['fk_usuario_B'] : $neg['fk_usuario_A']);
    $rival   = consultar($pdo, 'SELECT username FROM usuarios WHERE id = ?', [$rivalId])[0]['username'];

    $unicos = array_values(array_unique(
        idsCartas($soyA ? $neg['coleccion_mesa_B'] : $neg['coleccion_mesa_A'])
    ));
    $cartas = [];
    if ($unicos) {
        $marcas = implode(',', array_fill(0, count($unicos), '?'));
        $cartas = consultar($pdo,
            "SELECT id, nombre, especie, periodo, imagen_url AS imagenUrl, hp
             FROM dinosaurios WHERE id IN ($marcas)", $unicos);
    }

    responder(['negociacion' => [
        'negociacion_id'   => (int)$neg['negociacion_id'],
        'rival_id'         => $rivalId,
        'rival'            => $rival,
        'cartas_rival'     => $cartas,
        'confirmado_mio'   => (int)$neg[$soyA ? 'confirmado_A' : 'confirmado_B'],
        'confirmado_rival' => (int)$neg[$soyA ? 'confirmado_B' : 'confirmado_A'],
    ]]);
}

// POST cartas="3,7": guarda mi mesa y anula las confirmaciones
function guardarMesa(PDO $pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(['error' => 'Método no permitido'], 405);
    $yo  = usuarioId($pdo);
    $neg = negociacionAbierta($pdo, $yo);
    if (!$neg) responder(['error' => 'No estás en ninguna negociación'], 400);

    $col = ((int)$neg['fk_usuario_A'] === $yo) ? 'coleccion_mesa_A' : 'coleccion_mesa_B';
    $ids = implode(',', idsCartas($_POST['cartas'] ?? ''));

    $pdo->prepare("UPDATE negociacion SET $col = ?, confirmado_A = 0, confirmado_B = 0
                   WHERE negociacion_id = ?")
        ->execute([$ids, $neg['negociacion_id']]);
    responder(['ok' => true]);
}

// POST confirmar=1|0: si confirman los dos, se hace el intercambio
function confirmar(PDO $pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(['error' => 'Método no permitido'], 405);
    $yo = usuarioId($pdo);

    $pdo->beginTransaction();   // bloquea la fila para que no se intercambie dos veces
    $neg = negociacionAbierta($pdo, $yo, true);
    if (!$neg) {
        $pdo->rollBack();
        responder(['error' => 'No estás en ninguna negociación'], 400);
    }

    $col   = ((int)$neg['fk_usuario_A'] === $yo) ? 'confirmado_A' : 'confirmado_B';
    $valor = (($_POST['confirmar'] ?? '1') === '1') ? 1 : 0;
    $pdo->prepare("UPDATE negociacion SET $col = ? WHERE negociacion_id = ?")
        ->execute([$valor, $neg['negociacion_id']]);
    $neg[$col] = $valor;

    $hecho = false;
    if ($neg['confirmado_A'] && $neg['confirmado_B']) {
        $a = (int)$neg['fk_usuario_A'];
        $b = (int)$neg['fk_usuario_B'];
        $daA = idsCartas($neg['coleccion_mesa_A']);
        $daB = idsCartas($neg['coleccion_mesa_B']);

        if (!$daA || !$daB || !tieneCartas($pdo, $a, $daA) || !tieneCartas($pdo, $b, $daB)) {
            $pdo->rollBack();
            responder(['error' => 'Los dos deben ofrecer cartas que tengan'], 400);
        }
        $stmt = $pdo->prepare('CALL IntercambiarCartas(?, ?, ?, ?)');
        $stmt->execute([$a, $b, implode(',', $daA), implode(',', $daB)]);
        $stmt->closeCursor();

        $pdo->prepare("UPDATE negociacion SET estado = 'cerrado' WHERE negociacion_id = ?")
            ->execute([$neg['negociacion_id']]);
        $hecho = true;
    }
    $pdo->commit();
    responder(['ok' => true, 'intercambiado' => $hecho]);
}

/* ---------- Router ---------- */

try {
    switch ($_GET['accion'] ?? '') {
        case 'buscar': buscarOponente($pdo);   break;
        case 'estado': estadoMatchmaking($pdo); break;      
        case 'login':     login($pdo);     break;
        case 'registro':  registro($pdo);  break;
        case 'logout':    logout();        break;
        case 'coleccion': coleccion($pdo); break;
        case 'dinos':     dinos($pdo);     break;
        case 'mesa':     guardarMesa($pdo); break;
        case 'confirmar': confirmar($pdo);  break;
        default:          responder(['error' => 'Acción no válida'], 404);
    }
}  catch (PDOException $e) {
    error_log('API: ' . $e->getMessage());
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder(['error' => 'Error en la base de datos', 'detalle' => $e->getMessage()], 500);
}
