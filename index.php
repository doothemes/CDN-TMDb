<?php
/**
 * CDN Proxy para imágenes de TMDB (The Movie Database)
 *
 * Proxy inverso con almacenamiento permanente. Cuando un navegador solicita
 * una imagen (ej: /t/p/w500/abc.jpg), el script la descarga de image.tmdb.org
 * y la guarda en disco replicando la estructura de carpetas de TMDB.
 *
 * En la siguiente petición de la misma imagen, Apache detecta que el archivo
 * ya existe físicamente y lo sirve como archivo estático, sin ejecutar PHP
 * en absoluto. Esto se configura en .htaccess.
 *
 * Flujo:
 *   1ra vez:  Cliente → Apache → PHP → TMDB → guarda en /t/p/w500/abc.jpg → responde
 *   2da vez:  Cliente → Apache → /t/p/w500/abc.jpg (sirve directo, PHP no interviene)
 */

// ─── Configuración ──────────────────────────────────────────────

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/helpers.php';

/**
 * Clave secreta para proteger los endpoints administrativos (get_stats, cleaner).
 * Se valida contra el header X-Api-Key en cada petición a estos endpoints.
 * Configurar en .env: API_SECRET=tu-clave-secreta
 */
define('API_SECRET', env('API_SECRET', ''));

/**
 * Host origen de las imágenes de TMDB.
 */
define('TMDB_IMAGE_HOST', 'https://image.tmdb.org');

/**
 * Directorio raíz donde se almacenan las imágenes descargadas.
 */
define('STORAGE_DIR', __DIR__);

/**
 * Directorio donde se guardan los logs (errores y auditoría).
 * Se crea automáticamente si no existe.
 */
define('LOG_DIR', __DIR__ . '/logs');

/**
 * Origen permitido para CORS. Default "*" para uso abierto.
 * En producción, restringir a dominios específicos vía .env.
 */
define('CORS_ORIGIN', env('CORS_ORIGIN', '*'));

/**
 * TTL del negative cache: tiempo en segundos que se recuerda un 404 de TMDB
 * para evitar golpear repetidamente el upstream con hashes inválidos.
 */
define('NEGATIVE_CACHE_TTL', (int) env('NEGATIVE_CACHE_TTL', 3600));

/**
 * Pool de IPs de Googlebot desde .env (CSV).
 * Si no está configurado en .env, usa un pool por defecto de IPs conocidas.
 */
define('GOOGLEBOT_IPS', array_filter(array_map('trim', explode(',', env('GOOGLEBOT_IPS', '66.249.66.1,66.249.66.12,66.249.66.33,66.249.66.45,66.249.66.68,66.249.66.79,66.249.66.82,66.249.66.91,66.249.66.104')))));

// ─── Obtener el path solicitado ─────────────────────────────────

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ─── Endpoints administrativos ──────────────────────────────────

if ($path === '/get_stats' && $method === 'GET') {
    authenticate();
    json_response(get_stats());
}

if ($path === '/cleaner' && $method === 'POST') {
    authenticate();
    json_response(cleaner());
}

// ─── Validar path de imagen ─────────────────────────────────────

/**
 * El patrón de extensiones se deriva de MIME_TYPES (helpers.php) —
 * fuente única de verdad.
 */
$ext_pattern = allowed_extensions_pattern();

if (!preg_match("#^/t/p/([a-z0-9]+)/([a-zA-Z0-9_-]+)\.($ext_pattern)$#", $path, $matches)) {
    http_response_code(400);
    exit('Bad request');
}

$size     = $matches[1];
$filename = $matches[2];
$ext      = $matches[3];

// ─── Búsqueda en disco ──────────────────────────────────────────

$file_path = STORAGE_DIR . $path;
$file_dir  = dirname($file_path);

// Si ya está en disco (fallback por si .htaccess no lo atrapó)
if (file_exists($file_path)) {
    serve_file($file_path, $ext);
    exit;
}

// ─── Negative cache: evitar golpear TMDB con hashes inválidos ───

/**
 * Si una petición anterior falló (404 de TMDB), marcamos el path como
 * inexistente por NEGATIVE_CACHE_TTL segundos. Esto evita que un atacante
 * agote recursos del servidor generando miles de hashes aleatorios.
 */
$negative_marker = $file_path . '.404';
if (file_exists($negative_marker) && (time() - filemtime($negative_marker)) < NEGATIVE_CACHE_TTL) {
    http_response_code(404);
    exit('Not found');
}

// ─── Descarga desde TMDB ────────────────────────────────────────

$remote_url = TMDB_IMAGE_HOST . $path;
$spoof_ip   = GOOGLEBOT_IPS[array_rand(GOOGLEBOT_IPS)];

/**
 * Configuramos cURL para descargar la imagen de TMDB con identidad de Googlebot.
 * El User-Agent + X-Forwarded-For simulan un crawler de Google, reduciendo
 * la probabilidad de rate-limiting por parte del CDN de TMDB.
 */
$ch = curl_init($remote_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    CURLOPT_HTTPHEADER     => [
        'Accept: image/webp,image/*,*/*;q=0.8',
        'X-Forwarded-For: ' . $spoof_ip,
    ],
]);

$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

// Si cURL falló a nivel de conexión, loggear para diagnóstico
if ($body === false) {
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0755, true);
    log_line(LOG_DIR . '/error.log', "cURL error fetching {$remote_url}: {$err}");
    http_response_code(502);
    exit('Upstream error');
}
curl_close($ch);

// Si TMDB devolvió error, marcar en negative cache y propagar el código
if ($code !== 200) {
    if ($code === 404) {
        if (!is_dir($file_dir)) @mkdir($file_dir, 0755, true);
        @file_put_contents($negative_marker, '');
    }
    http_response_code($code ?: 502);
    exit('Upstream error');
}

/**
 * Verificación doble de Content-Type:
 *   1. Header de la respuesta (lo que dijo TMDB)
 *   2. Inspección binaria real (finfo sobre los bytes descargados)
 *
 * Ambos deben ser "image/*". Esto previene que un upstream comprometido
 * o MITM envíe contenido malicioso con un header falsificado.
 */
if (!str_starts_with($ct, 'image/')) {
    http_response_code(502);
    exit('Invalid upstream content type');
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$detected = $finfo->buffer($body);
if (!str_starts_with($detected, 'image/') && $detected !== 'text/plain') {
    // text/plain se permite porque finfo detecta SVG como text/plain a veces
    http_response_code(502);
    exit('Invalid upstream content');
}

// ─── Almacenar en disco de forma atómica ────────────────────────

if (!is_dir($file_dir)) {
    mkdir($file_dir, 0755, true);
}

/**
 * Escritura atómica: primero a archivo .tmp con sufijo aleatorio, luego rename().
 * Si dos peticiones concurrentes descargan la misma imagen, una de las dos
 * ganará el rename final sin corromper el archivo.
 */
atomic_write($file_path, $body);

// Si existía un marcador 404 anterior, eliminarlo (la imagen ahora existe)
@unlink($negative_marker);

// ─── Servir la imagen al cliente ────────────────────────────────

serve_file($file_path, $ext);

// ─── Funciones administrativas ──────────────────────────────────

/**
 * Valida que la petición incluya el header X-Api-Key con la clave secreta.
 * Si la clave no coincide o no está presente, responde 401 y termina.
 */
function authenticate(): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (API_SECRET === '' || !hash_equals(API_SECRET, $key)) {
        json_response(['error' => 'Unauthorized'], 401);
    }
}

/**
 * Envía una respuesta JSON con headers apropiados y termina la ejecución.
 *
 * @param array $data Datos a serializar como JSON
 * @param int $code Código HTTP de respuesta (default: 200)
 */
function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    exit(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * GET /get_stats
 *
 * Escanea recursivamente el directorio /t/ y devuelve estadísticas del CDN:
 * - Total de carpetas
 * - Total de archivos almacenados
 * - Espacio en disco ocupado (bytes y formato legible)
 *
 * @return array Estadísticas del CDN
 */
function get_stats(): array
{
    $base = STORAGE_DIR . '/t';

    if (!is_dir($base)) {
        return [
            'version' => CDN_VERSION,
            'folders' => 0,
            'files'   => 0,
            'size'    => ['bytes' => 0, 'human' => '0 B'],
        ];
    }

    $folders = 0;
    $files   = 0;
    $bytes   = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        // Ignorar marcadores de negative cache en el conteo
        if (str_ends_with($item->getPathname(), '.404')) {
            continue;
        }
        if ($item->isDir()) {
            $folders++;
        } else {
            $files++;
            $bytes += $item->getSize();
        }
    }

    return [
        'version' => CDN_VERSION,
        'folders' => $folders,
        'files'   => $files,
        'size'    => [
            'bytes' => $bytes,
            'human' => human_size($bytes),
        ],
    ];
}

/**
 * POST /cleaner
 *
 * Ejecuta limpieza de imágenes almacenadas. Soporta dos modos:
 *   { "mode": "all" } — elimina todas las imágenes
 *   { "mode": "older_than", "days": 30 } — elimina archivos con más de X días
 *
 * Todas las ejecuciones se registran en logs/audit.log para auditoría.
 *
 * @return array Resultado con archivos y carpetas eliminados
 */
function cleaner(): array
{
    $base = STORAGE_DIR . '/t';

    if (!is_dir($base)) {
        return ['deleted_files' => 0, 'deleted_folders' => 0];
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $mode  = $input['mode'] ?? '';

    if (!in_array($mode, ['all', 'older_than'], true)) {
        json_response(['error' => 'Invalid mode. Use "all" or "older_than"'], 400);
    }

    $days = 0;
    if ($mode === 'older_than') {
        $days = (int) ($input['days'] ?? 0);
        if ($days < 1) {
            json_response(['error' => 'Parameter "days" must be a positive integer'], 400);
        }
    }

    $threshold     = $mode === 'all' ? PHP_INT_MAX : time() - ($days * 86400);
    $deleted_files = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if ($mode === 'all' || $item->getMTime() < $threshold) {
            @unlink($item->getPathname());
            $deleted_files++;
        }
    }

    $deleted_folders = cleanup_empty_dirs($base);

    // Log de auditoría
    if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0755, true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    log_line(LOG_DIR . '/audit.log', "cleaner ip={$ip} mode={$mode} days={$days} deleted_files={$deleted_files} deleted_folders={$deleted_folders}");

    return [
        'mode'            => $mode,
        'days'            => $mode === 'older_than' ? $days : null,
        'deleted_files'   => $deleted_files,
        'deleted_folders' => $deleted_folders,
    ];
}

// ─── Servir archivos ────────────────────────────────────────────

/**
 * Sirve un archivo de imagen desde disco con headers HTTP optimizados.
 *
 * Establece cache agresivo (30 días, immutable) porque las imágenes de TMDB
 * son inmutables por naturaleza — el hash del nombre cambia si el contenido cambia.
 *
 * Soporta peticiones condicionales (If-None-Match, If-Modified-Since)
 * respondiendo con 304 Not Modified cuando el cliente ya tiene la imagen.
 *
 * @param string $file Ruta absoluta al archivo en disco
 * @param string $ext  Extensión del archivo
 */
function serve_file(string $file, string $ext): void
{
    $mime    = MIME_TYPES[$ext] ?? 'application/octet-stream';
    $size    = filesize($file);
    $etag    = '"' . md5_file($file) . '"';
    $lastmod = filemtime($file);

    // Headers de contenido
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=2592000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmod) . ' GMT');
    header('X-Cache: HIT');

    // CORS configurable
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);

    // Hardening: previene MIME sniffing y referrer leaking
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer-when-downgrade');

    // Peticiones condicionales: 304 si el cliente ya tiene la imagen
    if (
        (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
        (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastmod)
    ) {
        http_response_code(304);
        return;
    }

    readfile($file);
}
