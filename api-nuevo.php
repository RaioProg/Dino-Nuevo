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
        // Inicio2 devuelve username y password_hash; la contraseña se comprueba aquí
        // con password_verify (su 2.º parámetro y el OUT no se usan).
        $filas = consultar($pdo, 'CALL Inicio2(?, ?, @eror)', [$login, '']);
    } catch (PDOException $e) {
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


/* ---------- Router ---------- */

try {
    switch ($_GET['accion'] ?? '') {
        case 'login':     login($pdo);     break;
        case 'registro':  registro($pdo);  break;
        case 'logout':    logout();        break;
        case 'coleccion': coleccion($pdo); break;
        case 'dinos':     dinos($pdo);     break;
        default:          responder(['error' => 'Acción no válida'], 404);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    responder(['error' => 'Error en la base de datos'], 500);
}
