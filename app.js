/* ==========================================================
   DinoFusion Cards · app.js
   Un solo archivo para las dos páginas:
     - index.html  (<body class="login">) → pestañas de login / registro
     - cartas.html (<body class="app">)   → carrusel, colección, sobres y filtros
   ========================================================== */
'use strict';

const API = 'api-nuevo.php?accion=';

const PERIODOS = {
    triasico:  { n: 'Triásico',  r: '252–201 M.a.', e: '🌵' },
    jurasico:  { n: 'Jurásico',  r: '201–145 M.a.', e: '🌿' },
    cretacico: { n: 'Cretácico', r: '145–66 M.a.',  e: '🌋' },
};

/* ---------- Utilidades ---------- */
const $ = id => document.getElementById(id);
const norm = s => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));
const fmt = n => Number(n).toLocaleString('es-ES', { maximumFractionDigits: 2 });


/* ==========================================================
   1. PÁGINA DE ACCESO (index.html)
   ========================================================== */
function iniciarAcceso() {
    const btnLogin = $('btn-login');
    const btnRegistro = $('btn-registro');
    const formLogin = $('form-login');
    const formRegistro = $('form-registro');

    function mostrarLogin() {
        formLogin.classList.remove('oculto');
        formRegistro.classList.add('oculto');
        btnLogin.classList.add('activa');
        btnRegistro.classList.remove('activa');
    }

    function mostrarRegistro() {
        formRegistro.classList.remove('oculto');
        formLogin.classList.add('oculto');
        btnRegistro.classList.add('activa');
        btnLogin.classList.remove('activa');
    }

    btnLogin.addEventListener('click', mostrarLogin);
    btnRegistro.addEventListener('click', mostrarRegistro);

    formRegistro.addEventListener('submit', event => {
        const coinciden = $('registro-password').value === $('registro-repetir').value;
        $('label-no-coinciden').classList.toggle('oculto', coinciden);
        if (!coinciden) event.preventDefault();
    });

    // Errores que devuelve api.php en la URL (?error=login / ?error=registro)
    const error = new URLSearchParams(location.search).get('error');
    if (error === 'login') {
        alert('Usuario o contraseña incorrectos');
    } else if (error === 'registro') {
        mostrarRegistro();
        alert('No se pudo crear la cuenta (usuario o correo ya existen, o datos no válidos)');
    }
    // Limpia la URL para que el aviso no vuelva a salir al recargar
    if (error) history.replaceState(null, '', location.pathname);
}


/* ==========================================================
   2. CARRUSEL DE NOVEDADES
   ========================================================== */
function iniciarCarrusel() {
    const carrusel = $('carrusel');
    const diapositivas = [...carrusel.querySelectorAll('.diapositiva')];
    const contenedorPuntos = carrusel.querySelector('.puntos');
    const INTERVALO = 5000; // milisegundos entre diapositivas

    let actual = 0;
    let temporizador = null;

    const puntos = diapositivas.map((_, i) => {
        const punto = document.createElement('button');
        punto.type = 'button';
        punto.className = 'punto';
        punto.setAttribute('aria-label', `Ir a la novedad ${i + 1}`);
        punto.addEventListener('click', () => { ir(i); iniciar(); });
        contenedorPuntos.appendChild(punto);
        return punto;
    });

    function ir(indice) {
        actual = (indice + diapositivas.length) % diapositivas.length;
        diapositivas.forEach((d, i) => {
            d.classList.toggle('activa', i === actual);
            d.setAttribute('aria-hidden', i !== actual);
        });
        puntos.forEach((p, i) => p.classList.toggle('activo', i === actual));
    }

    function detener() {
        clearInterval(temporizador);
    }

    function iniciar() {
        // Respeta a quien tiene desactivadas las animaciones
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        detener();
        temporizador = setInterval(() => ir(actual + 1), INTERVALO);
    }

    carrusel.querySelector('.anterior').addEventListener('click', () => { ir(actual - 1); iniciar(); });
    carrusel.querySelector('.siguiente').addEventListener('click', () => { ir(actual + 1); iniciar(); });

    // Pausa mientras el ratón o el teclado están dentro
    carrusel.addEventListener('mouseenter', detener);
    carrusel.addEventListener('mouseleave', iniciar);
    carrusel.addEventListener('focusin', detener);
    carrusel.addEventListener('focusout', iniciar);

    ir(0);
    iniciar();
}


/* ==========================================================
   3. COLECCIÓN Y SOBRES
   ========================================================== */
let coleccion = [];
let coleccionMini = [];
let restante = 0;                 // segundos hasta poder abrir otro sobre
let intervaloContador = null;
let temporizadorReveladas = null;
let temporizadorLimpieza = null;

function pintarContador() {
    const el = $('contador-sobre');
    const central = document.querySelector('.central');
    if (restante > 0) {
        const h = String(Math.floor(restante / 3600)).padStart(2, '0');
        const m = String(Math.floor(restante % 3600 / 60)).padStart(2, '0');
        const s = String(restante % 60).padStart(2, '0');
        el.textContent = `⏳ Próximo sobre en ${h}:${m}:${s}`;
        central.classList.add('bloqueado');
    } else {
        el.textContent = '✅ ¡Sobre disponible!';
        central.classList.remove('bloqueado');
    }
}

function iniciarContador(segundos) {
    clearInterval(intervaloContador);
    restante = segundos || 0;
    pintarContador();
    if (restante > 0) {
        intervaloContador = setInterval(() => {
            restante--;
            if (restante <= 0) clearInterval(intervaloContador);
            pintarContador();
        }, 1000);
    }
}

function avisar(texto) {
    $('contador-sobre').textContent = `⚠️ ${texto}`;
}

function stat(label, v) {
    return `<div class="stat"><span>${label}</span><div class="bar"><i style="width:${v}%"></i></div><b>${v}</b></div>`;
}

function htmlCarta(d) {
    const per = norm(d.periodo);
    const p = PERIODOS[per] || { n: d.periodo, r: '', e: '🦴' };
    return `<article class="card ${esc(per)}" aria-label="${esc(d.nombre)}, ${esc(p.n)}">
      <div class="inner">
        <div class="top">
          <div><h2>${esc(d.nombre)}</h2><small>${esc(d.especie)}</small></div>
          <div class="hp">❤️ ${d.hp}</div>
        </div>
        <div class="art">
          <div class="ph" aria-hidden="true">🦖</div>
          <img src="${esc(d.imagenUrl)}" alt="${esc(d.nombre)}" loading="lazy" onerror="this.remove()">
          <div class="ribbon">${p.e} ${esc(p.n)} <i>${esc(p.r)}</i></div>
          ${d.copias > 1 ? `<div class="copias">×${d.copias}</div>` : ''}
        </div>
        ${d.rol ? `<div class="role">${esc(d.rol)}</div>` : ''}
        <div class="stats">
          ${stat('⚡ Vigor', d.vigor)}${stat('⚔️ Ataque', d.ataque)}${stat('🛡️ Defensa', d.defensa)}${stat('💨 Agilidad', d.agilidad)}
        </div>
        <div class="size">
          <div><strong>${fmt(d.altura)} m</strong>Altura</div>
          <div><strong>${fmt(d.largo)} m</strong>Largo</div>
          <div><strong>${fmt(d.peso)} t</strong>Peso</div>
        </div>
        ${d.frase ? `<p class="tag">${esc(d.frase)}</p>` : ''}
      </div>
    </article>`;
}

function filtrar(lista) {
    const texto = norm($('filtro-nombre').value.trim());
    const periodos = [...document.querySelectorAll('input[name="periodo"]:checked')].map(c => c.value);
    return lista.filter(d =>
        (!texto || norm(d.nombre).includes(texto)) &&
        (!periodos.length || periodos.includes(norm(d.periodo)))
    );
}

function renderColeccion() {
    const visibles = filtrar(coleccion);
    $('grid').innerHTML = visibles.map(htmlCarta).join('');
    $('contador-cartas').textContent = visibles.length === coleccion.length
        ? coleccion.length
        : `${visibles.length}/${coleccion.length}`;
}

// Llamada a api.php?accion=coleccion (GET = ver colección, POST = abrir sobre)
async function pedir(opciones) {
    let res;
    try {
        res = await fetch(API + 'coleccion', opciones);
    } catch {
        avisar('No se pudo conectar con el servidor.');
        return null;
    }
    if (res.status === 401) { location.href = 'index-nuevo.html'; return null; }

    let datos = null;
    try { datos = await res.json(); } catch { /* respuesta sin JSON */ }

    if (!res.ok && res.status !== 429) {
        avisar((datos && datos.error) || 'No se pudo completar la acción.');
        return null;
    }
    return datos;
}

async function cargarColeccion() {
    const data = await pedir();
    if (!data) return;
    coleccion = data.coleccion;
    $('nombre-usuario').textContent = data.usuario;
    iniciarContador(data.restante);
    renderColeccion();
    RenderColeccionTrade();
}

async function abrirSobre(periodo) {
    if (restante > 0) return;

    const data = await pedir({
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ periodo })
    });
    if (!data) return;
    iniciarContador(data.restante);
    if (data.error) return;

    coleccion = data.coleccion;
    const revelado = $('carta-revelada-info');

    clearTimeout(temporizadorReveladas);
    clearTimeout(temporizadorLimpieza);
    revelado.classList.remove('oculto');
    revelado.innerHTML = data.sacadas.map(htmlCarta).join('');

    // A los 5 s el abanico se desvanece y después se vacía
    temporizadorReveladas = setTimeout(() => {
        revelado.classList.add('oculto');
        temporizadorLimpieza = setTimeout(() => {
            revelado.innerHTML = '';
            revelado.classList.remove('oculto');
        }, 600);
    }, 5000);

    renderColeccion();
    RenderColeccionTrade();
    const scroll = document.querySelector('.scroll');
    scroll.scrollTop = scroll.scrollHeight;
}

function iniciarColeccion() {
    const central = document.querySelector('.central');

    central.addEventListener('click', e => {
        const pack = e.target.closest('.pack-wrap');
        if (pack) abrirSobre(pack.dataset.periodo);
    });

    // Los sobres tienen role="button": también se abren con Enter o espacio
    central.addEventListener('keydown', e => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const pack = e.target.closest('.pack-wrap');
        if (!pack) return;
        e.preventDefault();
        abrirSobre(pack.dataset.periodo);
    });

    cargarColeccion();
}


/* ==========================================================
   4. PANEL DE FILTROS
   ========================================================== */
function iniciarFiltros() {
    const btnFiltros = $('boton');
    const panel = $('panel-filtros');

    btnFiltros.addEventListener('click', () => {
        panel.classList.remove('oculto');
        btnFiltros.classList.add('oculto');
    });

    $('aplicar-filtros').addEventListener('click', () => {
        renderColeccion();
        panel.classList.add('oculto');
        btnFiltros.classList.remove('oculto');
    });

    $('limpiar-filtros').addEventListener('click', () => {
        $('filtro-nombre').value = '';
        panel.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = false);
        renderColeccion();
    });
}


/* ===========================================================
    5. TRADE
   =========================================================== */
   
function htmlCartaMini(d) {
    const per = norm(d.periodo);
    const p = PERIODOS[per] || { n: d.periodo, r: '', e: '🦴' };
    return `<article class="card mini ${esc(per)}" title="${esc(d.nombre)} · ${esc(p.n)} · ❤️ ${d.hp}" aria-label="${esc(d.nombre)}, ${esc(p.n)}">
      <div class="inner">
        <div class="art">
          <div class="ph" aria-hidden="true">🦖</div>
          <img src="${esc(d.imagenUrl)}" alt="${esc(d.nombre)}" loading="lazy" onerror="this.remove()">
          ${d.copias > 1 ? `<div class="copias">×${d.copias}</div>` : ''}
        </div>
        <div class="mini-name">${esc(d.nombre)}</div>
        <div class="mini-hp">❤️ ${d.hp}</div>
      </div>
    </article>`;
}

function RenderColeccionTrade() {
    const visibles = filtrar(coleccion);
    $('grid-mini').innerHTML = visibles.map(htmlCartaMini).join('');
}




/* ==========================================================
   ARRANQUE: cada página activa solo lo suyo
   ========================================================== */
if (document.body.classList.contains('login')) {
    iniciarAcceso();
} else if (document.body.classList.contains('app')) {
    iniciarCarrusel();
    iniciarFiltros();
    iniciarColeccion();
}