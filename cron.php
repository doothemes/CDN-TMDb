<?php
/**
 * Tarea CRON para limpieza automática del CDN
 *
 * Elimina imágenes que no han sido consultadas en un período de tiempo
 * determinado y limpia las carpetas vacías que queden huérfanas.
 *
 * Uso:
 *   php cron.php
 *
 * Crontab (ejemplo: ejecutar todos los días a las 3:00 AM):
 *   0 3 * * * php /ruta/al/cdn.dbmvs/cron.php >> /var/log/cdn-cleanup.log 2>&1
 */

// ─── Protección: sólo ejecutar desde CLI ────────────────────────

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Configuración ──────────────────────────────────────────────

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/helpers.php';

/**
 * Número máximo de días que una imagen puede permanecer en disco
 * sin ser consultada. Si el último acceso supera este límite,
 * la imagen se elimina automáticamente.
 * Configurar en .env: MAX_INACTIVE_DAYS=30
 *
 * El "último acceso" se determina con fileatime() (access time del SO).
 * Si el filesystem no soporta atime, se usa filemtime() como fallback.
 */
define('MAX_INACTIVE_DAYS', (int) env('MAX_INACTIVE_DAYS', 30));

/**
 * Directorio raíz del CDN donde se almacenan las imágenes.
 */
define('STORAGE_DIR', __DIR__);

// ─── Lock file: evitar ejecuciones concurrentes ─────────────────

/**
 * Si ya hay otro proceso de cron en ejecución, abortamos inmediatamente.
 * Previene colisiones al eliminar archivos y estadísticas incorrectas.
 */
$lock_file = fopen(__DIR__ . '/cron.lock', 'c');
if (!$lock_file || !flock($lock_file, LOCK_EX | LOCK_NB)) {
    output("Otro proceso de limpieza ya está en ejecución. Abortando.");
    exit(1);
}

// ─── Ejecución ──────────────────────────────────────────────────

$base = STORAGE_DIR . '/t';

if (!is_dir($base)) {
    output("CDN vacío — nada que limpiar.");
    release_lock($lock_file);
    exit(0);
}

$threshold = time() - (MAX_INACTIVE_DAYS * 86400);

$deleted_files = 0;
$deleted_bytes = 0;
$skipped_files = 0;

output("Iniciando limpieza del CDN...");
output("Eliminando imágenes sin acceso en los últimos " . MAX_INACTIVE_DAYS . " días.");
output("Fecha límite: " . date('Y-m-d H:i:s', $threshold));
output(str_repeat('-', 50));

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    if (!$item->isFile()) {
        continue;
    }

    // Los marcadores de negative cache (.404) se limpian siempre si están expirados
    if (str_ends_with($item->getPathname(), '.404')) {
        if ($item->getMTime() < (time() - 86400)) {
            @unlink($item->getPathname());
        }
        continue;
    }

    // Usar atime (último acceso) si está disponible, sino mtime (última modificación)
    $atime = $item->getATime();
    $mtime = $item->getMTime();
    $last_access = max($atime, $mtime);

    if ($last_access < $threshold) {
        $size = $item->getSize();
        if (@unlink($item->getPathname())) {
            $deleted_files++;
            $deleted_bytes += $size;
        }
    } else {
        $skipped_files++;
    }
}

$deleted_folders = cleanup_empty_dirs($base);

// ─── Reporte ────────────────────────────────────────────────────

output(str_repeat('-', 50));
output("Limpieza completada: " . date('Y-m-d H:i:s'));
output("  Archivos eliminados:  " . $deleted_files . " (" . human_size($deleted_bytes) . " liberados)");
output("  Carpetas eliminadas:  " . $deleted_folders);
output("  Archivos conservados: " . $skipped_files);

release_lock($lock_file);
exit(0);

// ─── Helpers ────────────────────────────────────────────────────

/**
 * Libera el lock y cierra el archivo.
 *
 * @param resource $handle Handle del archivo de lock
 */
function release_lock($handle): void
{
    if ($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink(__DIR__ . '/cron.lock');
    }
}

/**
 * Imprime un mensaje con timestamp para los logs del cron.
 *
 * @param string $msg Mensaje a imprimir
 */
function output(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}
