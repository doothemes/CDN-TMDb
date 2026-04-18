<?php
/**
 * Rotación de API_SECRET desde CLI
 *
 * Genera un nuevo token criptográficamente seguro, actualiza .env de forma
 * atómica preservando el resto del archivo, y muestra el nuevo valor.
 *
 * Uso:
 *   php rotate-token.php
 *
 * Seguridad:
 *   - Sólo ejecutable desde CLI — Apache nunca puede invocar este script
 *   - Escritura atómica: tmp + rename() previene .env corrupto si se interrumpe
 *   - Preserva comentarios, líneas vacías y demás variables del archivo
 *   - Registra la rotación en logs/audit.log con el timestamp
 */

// ─── Protección: sólo ejecutar desde CLI ────────────────────────

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/helpers.php';

define('ENV_FILE', __DIR__ . '/.env');
define('LOG_DIR',  __DIR__ . '/logs');

// ─── Verificar .env ─────────────────────────────────────────────

if (!file_exists(ENV_FILE)) {
    fwrite(STDERR, "Error: .env no encontrado en " . ENV_FILE . PHP_EOL);
    exit(1);
}

if (!is_writable(ENV_FILE)) {
    fwrite(STDERR, "Error: .env no es escribible. Verifica permisos." . PHP_EOL);
    exit(1);
}

// ─── Generar nuevo token ────────────────────────────────────────

/**
 * 32 bytes aleatorios → 64 caracteres hex → 256 bits de entropía.
 * random_bytes() es criptográficamente seguro (CSPRNG).
 */
$new_token = bin2hex(random_bytes(32));
$old_token = env('API_SECRET', '');

// ─── Reescribir .env preservando todo lo demás ──────────────────

$lines   = file(ENV_FILE, FILE_IGNORE_NEW_LINES);
$updated = false;

foreach ($lines as $i => $line) {
    $trimmed = trim($line);

    // Saltar comentarios y líneas vacías
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        continue;
    }

    // Detectar la línea de API_SECRET (con o sin comillas, sin importar espacios)
    if (preg_match('/^\s*API_SECRET\s*=/', $line)) {
        $lines[$i] = 'API_SECRET=' . $new_token;
        $updated   = true;
        break;
    }
}

// Si no existía la línea, la añadimos al final
if (!$updated) {
    $lines[] = '';
    $lines[] = '# Rotado automáticamente el ' . date('Y-m-d H:i:s');
    $lines[] = 'API_SECRET=' . $new_token;
}

$content = implode(PHP_EOL, $lines) . PHP_EOL;

// Escritura atómica: .tmp → rename
if (!atomic_write(ENV_FILE, $content)) {
    fwrite(STDERR, "Error: no se pudo escribir el archivo .env" . PHP_EOL);
    exit(1);
}

// ─── Log de auditoría ───────────────────────────────────────────

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}
$old_preview = $old_token !== '' ? substr($old_token, 0, 8) . '...' : '(vacío)';
$new_preview = substr($new_token, 0, 8) . '...';
log_line(LOG_DIR . '/audit.log', "rotate-token old={$old_preview} new={$new_preview}");

// ─── Reporte al operador ────────────────────────────────────────

echo PHP_EOL;
echo "✓ Token rotado correctamente" . PHP_EOL;
echo str_repeat('-', 70) . PHP_EOL;
echo "Fecha:      " . date('Y-m-d H:i:s') . PHP_EOL;
echo "Anterior:   " . $old_preview . PHP_EOL;
echo "Nuevo:      " . $new_token . PHP_EOL;
echo str_repeat('-', 70) . PHP_EOL;
echo PHP_EOL;
echo "Actualiza las integraciones que usan este token." . PHP_EOL;
echo "El token anterior ya no es válido." . PHP_EOL;
echo PHP_EOL;

exit(0);
