<?php
/**
 * Helpers compartidos entre index.php y cron.php
 *
 * Centraliza las funciones utilitarias que ambos scripts necesitan
 * para evitar duplicación de código.
 */

/**
 * Mapa único de extensiones a tipos MIME.
 * Es la ÚNICA fuente de verdad para extensiones permitidas.
 * El regex de validación y el Content-Type se derivan de aquí.
 */
const MIME_TYPES = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
];

/**
 * Genera el patrón regex de extensiones permitidas a partir de MIME_TYPES.
 * Evita tener que sincronizar manualmente la lista en múltiples lugares.
 *
 * @return string Pipe-separated list (ej: "jpg|jpeg|png|webp|svg")
 */
function allowed_extensions_pattern(): string
{
    return implode('|', array_keys(MIME_TYPES));
}

/**
 * Elimina recursivamente todas las carpetas vacías dentro de un directorio.
 * Recorre de abajo hacia arriba (CHILD_FIRST) para que las subcarpetas
 * se evalúen antes que sus padres.
 *
 * @param string $dir Directorio raíz a limpiar
 * @return int Número de carpetas eliminadas
 */
function cleanup_empty_dirs(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && count(scandir($item->getPathname())) === 2) {
            @rmdir($item->getPathname());
            $deleted++;
        }
    }

    // Verificar si el directorio raíz también quedó vacío
    if (is_dir($dir) && count(scandir($dir)) === 2) {
        @rmdir($dir);
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
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, 2) . ' ' . $units[$i];
}

/**
 * Escribe una línea en un archivo de log con timestamp.
 * Rota el archivo automáticamente si supera el tamaño máximo configurado.
 *
 * Formato de rotación:
 *   audit.log     ← activo
 *   audit.log.1   ← rotación más reciente
 *   audit.log.2
 *   ...
 *   audit.log.N   ← la más antigua (se elimina al rotar de nuevo)
 *
 * @param string $file Ruta al archivo de log
 * @param string $msg Mensaje a registrar
 */
function log_line(string $file, string $msg): void
{
    $max_bytes = ((int) env('LOG_MAX_SIZE_MB', 5)) * 1024 * 1024;
    $keep      = (int) env('LOG_KEEP_FILES', 5);

    // Rotar si el archivo supera el tamaño límite
    if (file_exists($file) && filesize($file) >= $max_bytes) {
        rotate_log($file, $keep);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Rota un archivo de log: renombra .N → .N+1, el actual → .1.
 * Elimina las rotaciones que superen el límite de retención.
 *
 * @param string $file Ruta al archivo activo
 * @param int $keep Número máximo de rotaciones a conservar
 */
function rotate_log(string $file, int $keep): void
{
    // Eliminar la rotación más antigua si existe
    $oldest = $file . '.' . $keep;
    if (file_exists($oldest)) {
        @unlink($oldest);
    }

    // Desplazar: .N-1 → .N, .N-2 → .N-1, ..., .1 → .2
    for ($i = $keep - 1; $i >= 1; $i--) {
        $from = $file . '.' . $i;
        $to   = $file . '.' . ($i + 1);
        if (file_exists($from)) {
            @rename($from, $to);
        }
    }

    // El archivo activo se convierte en .1
    @rename($file, $file . '.1');
}

/**
 * Escribe contenido a disco de forma atómica.
 * Escribe primero a un archivo temporal y luego hace rename() — operación
 * atómica en la mayoría de filesystems. Evita archivos parciales si la
 * escritura se interrumpe (timeout, kill, disco lleno).
 *
 * @param string $path Ruta final del archivo
 * @param string $content Contenido a escribir
 * @return bool true si la escritura fue exitosa
 */
function atomic_write(string $path, string $content): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

    if (file_put_contents($tmp, $content) === false) {
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}
