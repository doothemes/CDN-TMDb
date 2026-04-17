<?php
/**
 * Tarea CRON para limpieza automatica del CDN
 *
 * Elimina imagenes que no han sido consultadas en un periodo de tiempo
 * determinado y limpia las carpetas vacias que queden huerfanas.
 *
 * Uso:
 *   php cron.php
 *
 * Crontab (ejemplo: ejecutar todos los dias a las 3:00 AM):
 *   0 3 * * * php /ruta/al/cdn.dbmvs/cron.php >> /var/log/cdn-cleanup.log 2>&1
 */

// ─── Proteccion: solo ejecutar desde CLI ────────────────────────

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// ─── Configuracion ──────────────────────────────────────────────

/**
 * Numero maximo de dias que una imagen puede permanecer en disco
 * sin ser consultada. Si el ultimo acceso supera este limite,
 * la imagen se elimina automaticamente.
 *
 * El "ultimo acceso" se determina con fileatime() (access time del SO).
 * Para que funcione correctamente, el filesystem del servidor debe tener
 * habilitado el registro de atime (es el comportamiento por defecto en
 * la mayoria de servidores Linux con ext4).
 *
 * Si el SO no soporta atime, se usa filemtime() como fallback
 * (fecha de descarga/modificacion).
 */
define('MAX_INACTIVE_DAYS', 30);

/**
 * Directorio raiz del CDN donde se almacenan las imagenes.
 */
define('STORAGE_DIR', __DIR__);

// ─── Ejecucion ──────────────────────────────────────────────────

$base = STORAGE_DIR . '/t';

// Si no existe el directorio /t/, no hay nada que limpiar
if (!is_dir($base)) {
    output("CDN vacio — nada que limpiar.");
    exit(0);
}

// Timestamp limite: archivos accedidos antes de esta fecha se eliminan
$threshold = time() - (MAX_INACTIVE_DAYS * 86400);

$deleted_files   = 0;
$deleted_bytes   = 0;
$skipped_files   = 0;

output("Iniciando limpieza del CDN...");
output("Eliminando imagenes sin acceso en los ultimos " . MAX_INACTIVE_DAYS . " dias.");
output("Fecha limite: " . date('Y-m-d H:i:s', $threshold));
output(str_repeat('-', 50));

// Recorrer todos los archivos dentro de /t/
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $item) {
    if (!$item->isFile()) {
        continue;
    }

    // Usar atime (ultimo acceso) si esta disponible, sino mtime (ultima modificacion)
    $atime = $item->getATime();
    $mtime = $item->getMTime();
    $last_access = ($atime > $mtime) ? $atime : $mtime;

    if ($last_access < $threshold) {
        $size = $item->getSize();
        unlink($item->getPathname());
        $deleted_files++;
        $deleted_bytes += $size;
    } else {
        $skipped_files++;
    }
}

// Limpiar carpetas vacias
$deleted_folders = cleanup_empty_dirs($base);

// ─── Reporte ────────────────────────────────────────────────────

output(str_repeat('-', 50));
output("Limpieza completada: " . date('Y-m-d H:i:s'));
output("  Archivos eliminados:  " . $deleted_files . " (" . human_size($deleted_bytes) . " liberados)");
output("  Carpetas eliminadas:  " . $deleted_folders);
output("  Archivos conservados: " . $skipped_files);

exit(0);

// ─── Helpers ─────────────────────────────────────────────���──────

/**
 * Elimina recursivamente carpetas vacias dentro del directorio dado.
 * Recorre de abajo hacia arriba (CHILD_FIRST) para que las subcarpetas
 * se evaluen antes que sus padres.
 *
 * @param string $dir Directorio raiz a limpiar
 * @return int Numero de carpetas eliminadas
 */
function cleanup_empty_dirs(string $dir): int
{
    $deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
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
 * Convierte bytes a formato legible.
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

/**
 * Imprime un mensaje con timestamp para los logs del cron.
 *
 * @param string $msg Mensaje a imprimir
 */
function output(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}
