# Security Policy — CDN Proxy TMDB

## Superficie de ataque

Este CDN expone dos superficies:

1. **Proxy de imagenes** — cualquier cliente puede solicitar una imagen via `/t/p/{size}/{hash}.{ext}`
2. **Endpoints administrativos** — `GET /get_stats` y `POST /cleaner`, protegidos por API key

## Medidas implementadas

### Validacion de paths (anti-SSRF / anti-path traversal)

El path solicitado se valida con un regex estricto antes de cualquier operacion:

```php
preg_match('#^/t/p/([a-z0-9]+)/([a-zA-Z0-9_-]+)\.(jpg|jpeg|png|webp|svg)$#', $path)
```

Esto garantiza que:

- Solo se aceptan rutas que comienzan con `/t/p/`
- El tamaño solo contiene letras minusculas y numeros (`w500`, `original`)
- El nombre de archivo solo contiene caracteres alfanumericos, guiones y guiones bajos
- La extension esta limitada a `jpg`, `jpeg`, `png`, `webp`, `svg`
- No es posible inyectar `../`, query strings, hosts externos, ni paths arbitrarios

Cualquier path que no coincida recibe `400 Bad Request` sin llegar a TMDB ni tocar el disco.

### Validacion de Content-Type (anti-almacenamiento de contenido malicioso)

Despues de descargar de TMDB, se verifica que el Content-Type de la respuesta comience con `image/`:

```php
if (!str_starts_with($ct, 'image/')) {
    http_response_code(502);
    exit('Invalid upstream content type');
}
```

Esto previene almacenar HTML, JavaScript, JSON u otro contenido que un atacante pudiera intentar servir desde el CDN para ataques XSS o phishing.

### Autenticacion de endpoints administrativos

Los endpoints `/get_stats` y `/cleaner` requieren el header `X-Api-Key` con el valor exacto de la constante `API_SECRET`.

La comparacion usa `hash_equals()` para prevenir timing attacks:

```php
if (!hash_equals(API_SECRET, $key)) {
    json_response(['error' => 'Unauthorized'], 401);
}
```

### Anti-hotlink (configurable)

El `.htaccess` incluye reglas para restringir que dominios pueden referenciar las imagenes del CDN. Cuando se activa, cualquier peticion con un `Referer` no autorizado recibe `403 Forbidden`.

Dominios permitidos se configuran como excepciones:

```apache
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?tudominio\.com [NC]
RewriteRule \.(jpg|jpeg|png|webp|svg)$ - [F,NC,L]
```

### CORS

Se envia `Access-Control-Allow-Origin: *` para permitir uso desde cualquier dominio. Si se requiere restringir, modificar el header en la funcion `serve_file()` de `index.php`.

### Cache immutable

Las imagenes se sirven con `Cache-Control: public, max-age=2592000, immutable`. Esto es seguro porque las imagenes de TMDB son inmutables por diseño — si el contenido cambia, el hash del nombre de archivo cambia tambien.

## Vectores conocidos y mitigaciones

| Vector | Mitigacion |
|--------|------------|
| Path traversal (`../../etc/passwd`) | Regex rechaza cualquier caracter fuera de `[a-zA-Z0-9_-]` |
| SSRF (redirigir a host interno) | El path se concatena con `TMDB_IMAGE_HOST` fijo, no se acepta input de host |
| Almacenar contenido no-imagen | Validacion de `Content-Type: image/*` antes de escribir a disco |
| Fuerza bruta en API key | `hash_equals()` previene timing attacks |
| Hotlinking desde sitios no autorizados | Reglas anti-hotlink en `.htaccess` (activar en produccion) |
| Consumo de disco excesivo | Endpoint `/cleaner` + `cron.php` para limpieza manual, programada o por antiguedad |
| Credenciales expuestas en repo | `.env` excluido via `.gitignore`, solo se commitea `.env.example` sin valores reales |
| XSS via SVG malicioso | El CDN solo almacena SVGs descargados de TMDB (origen confiable), no acepta uploads de usuarios |

## Recomendaciones para produccion

1. **Configurar `API_SECRET` en `.env`** — sin esta clave los endpoints admin rechazan todas las peticiones
2. **Activar anti-hotlink** — descomentar las reglas en `.htaccess` y listar solo los dominios autorizados
3. **HTTPS obligatorio** — configurar redireccion HTTP → HTTPS a nivel de servidor
4. **Monitorear disco** — ejecutar `/get_stats` periodicamente y programar limpiezas con `/cleaner`
5. **Rate limiting** — considerar implementar limite de peticiones a nivel de servidor (mod_ratelimit o firewall) para prevenir abuso del proxy
6. **Restringir CORS** — si solo se usa desde dominios propios, reemplazar `*` por los dominios especificos

## Reportar vulnerabilidades

Si encuentras una vulnerabilidad de seguridad, reportala directamente a **emeza@ews.pe** antes de divulgarla publicamente.
