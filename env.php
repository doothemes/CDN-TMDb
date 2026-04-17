<?php
/**
 * Loader minimo de variables de entorno.
 *
 * Lee el archivo .env del directorio raiz y carga las variables
 * como constantes PHP accesibles desde index.php y cron.php.
 * No requiere Composer ni dependencias externas.
 *
 * Formato soportado:
 *   CLAVE=valor
 *   # comentarios
 *   lineas vacias (se ignoran)
 */

$env_file = __DIR__ . '/.env';

if (!file_exists($env_file)) {
    exit('Error: archivo .env no encontrado. Copia .env.example a .env y configura los valores.');
}

foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    // Ignorar comentarios
    if (str_starts_with(trim($line), '#')) {
        continue;
    }
    // Separar clave=valor (solo en el primer "=")
    if (strpos($line, '=') === false) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $key   = trim($key);
    $value = trim($value);

    // Registrar como variable de entorno
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
}

/**
 * Obtiene el valor de una variable de entorno.
 * Retorna el valor por defecto si no esta definida.
 *
 * @param string $key Nombre de la variable
 * @param mixed $default Valor por defecto
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}
