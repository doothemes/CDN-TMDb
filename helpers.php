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
 * Útil para auditoría de operaciones sensibles y errores.
 *
 * @param string $file Ruta al archivo de log
 * @param string $msg Mensaje a registrar
 */
function log_line(string $file, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
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
