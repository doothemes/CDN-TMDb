<?php
/**
 * CDN Proxy para imagenes de TMDB (The Movie Database)
 *
 * Este script actua como un proxy inverso con almacenamiento permanente.
 * Cuando un navegador solicita una imagen (ej: /t/p/w500/abc.jpg),
 * el script la descarga de image.tmdb.org y la guarda en disco
 * replicando la misma estructura de carpetas de TMDB.
 *
 * En la siguiente peticion de la misma imagen, Apache detecta que
 * el archivo ya existe fisicamente y lo sirve como archivo estatico,
 * sin ejecutar PHP en absoluto. Esto se configura en .htaccess.
 *
 * Flujo:
 *   1ra vez:  Cliente → Apache → PHP → TMDB → guarda en /t/p/w500/abc.jpg → responde
 *   2da vez:  Cliente → Apache → /t/p/w500/abc.jpg (sirve directo, PHP no interviene)
 */

// ─── Configuracion ──────────────────────────────────────────────

/**
 * Clave secreta para proteger los endpoints administrativos (get_stats, cleaner).
 * Se valida contra el header X-Api-Key en cada peticion a estos endpoints.
 * Cambiar este valor en produccion por una clave segura.
 */
define('API_SECRET', 'cambiar-esta-clave-en-produccion');

/**
 * Host origen de las imagenes de TMDB.
 * Todas las imagenes de posters, backdrops, logos, etc. se sirven desde este dominio.
 */
define('TMDB_IMAGE_HOST', 'https://image.tmdb.org');

/**
 * Directorio raiz donde se almacenan las imagenes descargadas.
 * Usamos __DIR__ (el mismo document root) para que Apache pueda
 * servir los archivos directamente sin necesidad de alias o symlinks.
 */
define('STORAGE_DIR', __DIR__);

/**
 * Extensiones de imagen permitidas.
 * Solo se aceptan estos formatos — cualquier otra extension se rechaza
 * con un 400 Bad Request antes de llegar a TMDB.
 */
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'svg']);

/**
 * Mapa de extensiones a tipos MIME.
 * Se usa al servir la imagen para establecer el header Content-Type correcto,
 * asegurando que el navegador interprete el archivo como imagen.
 */
define('MIME_TYPES', [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
]);

// ─── Obtener el path solicitado ─────────────────────────────────

/**
 * Extraemos solo el path de la URL solicitada, descartando query strings.
 * Ejemplo imagen: /t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg
 * Ejemplo admin:  /get_stats o /cleaner
 */
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ─── Endpoints administrativos ──────────────────────────────────

/**
 * Router para endpoints de administracion.
 * Estos endpoints estan protegidos por API_SECRET y permiten
 * consultar estadisticas del CDN y ejecutar limpiezas de cache.
 */
if ($path === '/get_stats' && $method === 'GET') {
    authenticate();
    json_response(get_stats());
}

if ($path === '/cleaner' && $method === 'POST') {
    authenticate();
    json_response(cleaner());
}

// ─── Validar path de imagen ────────────────────────────────────

/**
 * Si no es un endpoint admin, validamos que el path cumpla con el formato
 * de imagenes de TMDB: /t/p/{tamaño}/{nombre_archivo}.{extension}
 *
 * - {tamaño}: letras minusculas y numeros (ej: w500, w780, original)
 * - {nombre_archivo}: alfanumerico, guiones y guiones bajos (el hash de TMDB)
 * - {extension}: solo las permitidas (jpg, jpeg, png, webp, svg)
 *
 * Este regex es la primera linea de defensa contra path traversal y SSRF.
 * Cualquier intento de inyectar ../, hosts, o paths arbitrarios se rechaza aqui.
 */
if (!preg_match('#^/t/p/([a-z0-9]+)/([a-zA-Z0-9_-]+)\.(jpg|jpeg|png|webp|svg)$#', $path, $matches)) {
    http_response_code(400);
    exit('Bad request');
}

// Desglosamos las partes capturadas del regex
$size     = $matches[1]; // Tamaño de imagen (w500, original, etc.)
$filename = $matches[2]; // Hash unico del archivo en TMDB
$ext      = $matches[3]; // Extension del archivo

// ─── Busqueda en disco ──────────────────────────────────────────

/**
 * Construimos la ruta completa donde deberia estar la imagen almacenada.
 * Ejemplo: /var/www/cdn.dbmvs/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg
 */
$file_path = STORAGE_DIR . $path;
$file_dir  = dirname($file_path);

/**
 * Verificamos si la imagen ya esta almacenada en disco.
 * Normalmente .htaccess ya deberia haber servido el archivo antes de llegar aqui,
 * pero esta comprobacion actua como fallback de seguridad por si el rewrite falla
 * o si el servidor no soporta las reglas de reescritura.
 */
if (file_exists($file_path)) {
    serve_file($file_path, $ext);
    exit;
}

// ─── Descarga desde TMDB ────────────────────────────────────────

/**
 * Pool de direcciones IP de Googlebot.
 * Se envia una IP aleatoria en el header X-Forwarded-For para simular
 * que la peticion proviene de un crawler de Google. Esto, combinado
 * con el User-Agent de Googlebot, reduce la probabilidad de rate-limiting
 * o bloqueos por parte del CDN de TMDB.
 */
$googlebot_ips = [
    '66.249.66.1',  '66.249.66.12', '66.249.66.33',
    '66.249.66.45', '66.249.66.68', '66.249.66.79',
    '66.249.66.82', '66.249.66.91', '66.249.66.104',
];

// URL completa de la imagen en el servidor de TMDB
$remote_url = TMDB_IMAGE_HOST . $path;

// Seleccionamos una IP aleatoria del pool para esta peticion
$spoof_ip = $googlebot_ips[array_rand($googlebot_ips)];

/**
 * Configuramos cURL para descargar la imagen de TMDB.
 *
 * - RETURNTRANSFER: devuelve el contenido como string en vez de imprimirlo
 * - FOLLOWLOCATION: sigue redirecciones 3xx (TMDB puede redirigir entre CDNs)
 * - TIMEOUT: maximo 15 segundos para la descarga completa
 * - CONNECTTIMEOUT: maximo 5 segundos para establecer la conexion TCP
 * - USERAGENT: nos identificamos como Googlebot para evitar bloqueos
 * - X-Forwarded-For: IP de Googlebot aleatoria para reforzar la identidad
 * - Accept: prioriza WebP, acepta cualquier imagen como fallback
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

// Ejecutamos la peticion y recopilamos la informacion de respuesta
$body  = curl_exec($ch);                              // Contenido binario de la imagen
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);       // Codigo HTTP (200, 404, etc.)
$ct    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);    // Content-Type devuelto por TMDB
$error = curl_error($ch);                             // Mensaje de error si cURL fallo
curl_close($ch);

/**
 * Si TMDB no devuelve HTTP 200 o cURL fallo, propagamos el error al cliente.
 * Usamos el mismo codigo HTTP que devolvio TMDB (404, 429, 500, etc.)
 * o 502 Bad Gateway si cURL no pudo conectarse en absoluto.
 */
if ($code !== 200 || $body === false) {
    http_response_code($code ?: 502);
    exit('Upstream error');
}

/**
 * Verificamos que TMDB realmente devolvio una imagen y no HTML de error,
 * JSON u otro tipo de contenido inesperado. Solo aceptamos Content-Type
 * que comience con "image/" para evitar almacenar basura en disco.
 */
if (!str_starts_with($ct, 'image/')) {
    http_response_code(502);
    exit('Invalid upstream content type');
}

// ─── Almacenar en disco ─────────────────────────────────────────

/**
 * Creamos la estructura de directorios si no existe.
 * mkdir con recursive=true crea toda la ruta: /t/p/w500/ de una sola vez.
 * Los permisos 0755 permiten lectura publica (necesario para que Apache sirva).
 */
if (!is_dir($file_dir)) {
    mkdir($file_dir, 0755, true);
}

/**
 * Guardamos el contenido binario de la imagen en disco.
 * A partir de este momento, Apache servira este archivo directamente
 * en todas las peticiones futuras, sin pasar por PHP.
 */
file_put_contents($file_path, $body);

// ─── Servir la imagen al cliente ────────────────────────────────

serve_file($file_path, $ext);

// ─── Funciones administrativas ──────────────────────────────────

/**
 * Valida que la peticion incluya el header X-Api-Key con la clave secreta.
 * Si la clave no coincide o no esta presente, responde 401 y termina la ejecucion.
 */
function authenticate(): void
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals(API_SECRET, $key)) {
        json_response(['error' => 'Unauthorized'], 401);
    }
}

/**
 * Envia una respuesta JSON con headers apropiados y termina la ejecucion.
 *
 * @param array $data Datos a serializar como JSON
 * @param int $code Codigo HTTP de respuesta (default: 200)
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
 * Escanea recursivamente el directorio /t/ y devuelve estadisticas del CDN:
 * - Total de carpetas (tamaños como w500, w780, original, etc.)
 * - Total de archivos almacenados
 * - Espacio en disco ocupado (bytes y formato legible)
 *
 * @return array Estadisticas del CDN
 */
function get_stats(): array
{
    $base = STORAGE_DIR . '/t';

    // Si no existe el directorio /t/, el CDN esta vacio
    if (!is_dir($base)) {
        return [
            'folders' => 0,
            'files'   => 0,
            'size'    => ['bytes' => 0, 'human' => '0 B'],
        ];
    }

    $folders = 0;
    $files   = 0;
    $bytes   = 0;

    // RecursiveDirectoryIterator recorre todo el arbol de /t/p/w500/*, /t/p/w780/*, etc.
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            $folders++;
        } else {
            $files++;
            $bytes += $item->getSize();
        }
    }

    return [
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
 * Ejecuta limpieza de imagenes almacenadas en el CDN.
 * Acepta un body JSON con los siguientes modos:
 *
 *   Limpieza total (elimina TODAS las imagenes):
 *   { "mode": "all" }
 *
 *   Limpieza por antiguedad (elimina archivos no modificados en X dias):
 *   { "mode": "older_than", "days": 30 }
 *
 * Despues de eliminar los archivos, tambien elimina las carpetas vacias
 * que hayan quedado huerfanas para mantener el disco limpio.
 *
 * @return array Resultado con el numero de archivos y carpetas eliminados
 */
function cleaner(): array
{
    $base = STORAGE_DIR . '/t';

    // Si no existe el directorio /t/, no hay nada que limpiar
    if (!is_dir($base)) {
        return ['deleted_files' => 0, 'deleted_folders' => 0];
    }

    // Leer y validar el body JSON de la peticion
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $mode  = $input['mode'] ?? '';

    if (!in_array($mode, ['all', 'older_than'], true)) {
        json_response(['error' => 'Invalid mode. Use "all" or "older_than"'], 400);
    }

    // Para el modo older_than, validar que se envie un numero de dias valido
    $days = 0;
    if ($mode === 'older_than') {
        $days = (int)($input['days'] ?? 0);
        if ($days < 1) {
            json_response(['error' => 'Parameter "days" must be a positive integer'], 400);
        }
    }

    // Calcular el timestamp limite: archivos modificados antes de esta fecha se eliminan
    $threshold     = $mode === 'all' ? PHP_INT_MAX : time() - ($days * 86400);
    $deleted_files = 0;

    // Recorrer todos los archivos dentro de /t/ y eliminar los que cumplan la condicion
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST // Hijos primero para poder eliminar carpetas vacias despues
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        // En modo "all" siempre elimina; en "older_than" compara la fecha de modificacion
        if ($mode === 'all' || $item->getMTime() < $threshold) {
            unlink($item->getPathname());
            $deleted_files++;
        }
    }

    // Segunda pasada: eliminar carpetas vacias que quedaron huerfanas
    $deleted_folders = cleanup_empty_dirs($base);

    return [
        'mode'            => $mode,
        'days'            => $mode === 'older_than' ? $days : null,
        'deleted_files'   => $deleted_files,
        'deleted_folders' => $deleted_folders,
    ];
}

/**
 * Elimina recursivamente todas las carpetas vacias dentro de un directorio.
 * Recorre de abajo hacia arriba (CHILD_FIRST) para que las subcarpetas
 * se evaluen antes que sus padres.
 *
 * @param string $dir Directorio raiz a limpiar
 * @return int Numero de carpetas eliminadas
 */
function cleanup_empty_dirs(string $dir): int
{
    $deleted = 0;
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && count(scandir($item->getPathname())) === 2) {
            rmdir($item->getPathname());
            $deleted++;
        }
    }

    // Verificar si el directorio raiz /t/ tambien quedo vacio
    if (is_dir($dir) && count(scandir($dir)) === 2) {
        rmdir($dir);
        $deleted++;
    }

    return $deleted;
}

/**
 * Convierte bytes a formato legible (KB, MB, GB, etc.)
 *
 * @param int $bytes Tamaño en bytes
 * @return string Tamaño formateado (ej: "12.45 MB")
 */
function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float)$bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, 2) . ' ' . $units[$i];
}

// ─── Helpers ────────────────────────────────────────────────────

/**
 * Sirve un archivo de imagen desde disco con headers HTTP optimizados.
 *
 * Establece headers de cache agresivos (30 dias, immutable) ya que las
 * imagenes de TMDB son inmutables por naturaleza — el hash del nombre
 * cambia si el contenido cambia, asi que nunca hay conflictos de cache.
 *
 * Tambien soporta peticiones condicionales (If-None-Match, If-Modified-Since)
 * respondiendo con 304 Not Modified cuando el navegador ya tiene la imagen
 * en su cache local, ahorrando ancho de banda.
 *
 * @param string $file Ruta absoluta al archivo en disco
 * @param string $ext  Extension del archivo (para resolver el MIME type)
 */
function serve_file(string $file, string $ext): void
{
    $mime    = MIME_TYPES[$ext] ?? 'application/octet-stream';
    $size    = filesize($file);
    $etag    = '"' . md5_file($file) . '"';    // Identificador unico basado en contenido
    $lastmod = filemtime($file);                // Timestamp de ultima modificacion

    // Headers de respuesta
    header('Content-Type: ' . $mime);                              // Tipo de contenido
    header('Content-Length: ' . $size);                            // Tamaño en bytes
    header('Cache-Control: public, max-age=2592000, immutable');   // Cache 30 dias, no revalidar
    header('ETag: ' . $etag);                                      // Hash para validacion condicional
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmod) . ' GMT');  // Fecha de modificacion
    header('X-Cache: HIT');                                        // Indicador de que salio del CDN
    header('Access-Control-Allow-Origin: *');                       // CORS abierto para uso en cualquier dominio

    /**
     * Peticiones condicionales: si el navegador envia If-None-Match (ETag)
     * o If-Modified-Since y la imagen no ha cambiado, respondemos 304
     * sin enviar el cuerpo — el navegador usa su copia local.
     */
    if (
        (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
        (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastmod)
    ) {
        http_response_code(304);
        return;
    }

    // Enviamos el contenido binario de la imagen al cliente
    readfile($file);
}
