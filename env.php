<?php
/**
 * Loader mínimo de variables de entorno.
 *
 * Lee el archivo .env del directorio raíz y carga las variables
 * para ser accedidas vía la función env() desde index.php y cron.php.
 * No requiere Composer ni dependencias externas.
 *
 * Formato soportado:
 *   CLAVE=valor
 *   CLAVE="valor con espacios"
 *   CLAVE='valor con espacios'
 *   # comentarios
 *   líneas vacías (se ignoran)
 */

$env_file = __DIR__ . '/.env';

if (!file_exists($env_file)) {
    // Contexto CLI: mensaje plano para el operador
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Error: archivo .env no encontrado. Copia .env.example a .env y configura los valores." . PHP_EOL);
        exit(1);
    }
    // Contexto web: 500 genérico sin exponer detalles
    @error_log('CDN: archivo .env no encontrado');
    http_response_code(500);
    exit('Server configuration error');
}

foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $trimmed = trim($line);

    // Ignorar comentarios y líneas sin "="
    if ($trimmed === '' || str_starts_with($trimmed, '#') || strpos($trimmed, '=') === false) {
        continue;
    }

    [$key, $value] = explode('=', $trimmed, 2);
    $key   = trim($key);
    $value = trim($value);

    // Quitar comillas envolventes (dobles o simples)
    if (strlen($value) >= 2) {
        $first = $value[0];
        $last  = $value[-1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }
    }

    $_ENV[$key] = $value;
    putenv("{$key}={$value}");
}

/**
 * Obtiene el valor de una variable de entorno.
 * Retorna el valor por defecto si no está definida o está vacía.
 *
 * @param string $key Nombre de la variable
 * @param mixed $default Valor por defecto
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}
