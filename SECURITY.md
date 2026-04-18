# Security Policy — CDN Proxy TMDB

## Superficie de ataque

Este CDN expone dos superficies:

1. **Proxy de imágenes** — cualquier cliente puede solicitar una imagen vía `/t/p/{size}/{hash}.{ext}`
2. **Endpoints administrativos** — `GET /get_stats` y `POST /cleaner`, protegidos por API key

## Medidas implementadas

### Validación de paths (anti-SSRF / anti-path traversal)

El path solicitado se valida con un regex estricto antes de cualquier operación:

```php
preg_match("#^/t/p/([a-z0-9]+)/([a-zA-Z0-9_-]+)\.($ext_pattern)$#", $path)
```

> **Nota**: el patrón `$ext_pattern` se genera dinámicamente desde `MIME_TYPES` en `helpers.php`. Actualmente incluye `jpg|jpeg|png|webp|svg`.

Esto garantiza que:

- Sólo se aceptan rutas que comienzan con `/t/p/`
- El tamaño sólo contiene letras minúsculas y números (`w500`, `original`)
- El nombre de archivo sólo contiene caracteres alfanuméricos, guiones y guiones bajos
- La extensión está limitada a `jpg`, `jpeg`, `png`, `webp`, `svg`
- No es posible inyectar `../`, query strings, hosts externos, ni paths arbitrarios

Cualquier path que no coincida recibe `400 Bad Request` sin llegar a TMDB ni tocar el disco.

### Validación de Content-Type (anti-almacenamiento de contenido malicioso)

Después de descargar de TMDB, se verifica que el Content-Type de la respuesta comience con `image/`:

```php
if (!str_starts_with($ct, 'image/')) {
    http_response_code(502);
    exit('Invalid upstream content type');
}
```

Esto previene almacenar HTML, JavaScript, JSON u otro contenido que un atacante pudiera intentar servir desde el CDN para ataques XSS o phishing.

### Autenticación de endpoints administrativos

Los endpoints `/get_stats` y `/cleaner` requieren el header `X-Api-Key` con el valor exacto de la constante `API_SECRET`.

La comparación usa `hash_equals()` para prevenir timing attacks:

```php
if (!hash_equals(API_SECRET, $key)) {
    json_response(['error' => 'Unauthorized'], 401);
}
```

### Anti-hotlink (configurable)

El `.htaccess` incluye reglas para restringir qué dominios pueden referenciar las imágenes del CDN. Cuando se activa, cualquier petición con un `Referer` no autorizado recibe `403 Forbidden`.

Dominios permitidos se configuran como excepciones:

```apache
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?tudominio\.com [NC]
RewriteRule \.(jpg|jpeg|png|webp|svg)$ - [F,NC,L]
```

### CORS

Se envía `Access-Control-Allow-Origin: *` para permitir uso desde cualquier dominio. Configurable vía `CORS_ORIGIN` en `.env` (default `*`). Para restringir, establecer el dominio específico. El header también se aplica desde `.htaccess` para archivos servidos directamente por Apache.

### Caché immutable

Las imágenes se sirven con `Cache-Control: public, max-age=2592000, immutable`. Esto es seguro porque las imágenes de TMDB son inmutables por diseño — si el contenido cambia, el hash del nombre de archivo cambia también.

## Vectores conocidos y mitigaciones

| Vector | Mitigación |
|--------|------------|
| Path traversal (`../../etc/passwd`) | Regex rechaza cualquier carácter fuera de `[a-zA-Z0-9_-]` |
| SSRF (redirigir a host interno) | El path se concatena con `TMDB_IMAGE_HOST` fijo, no se acepta input de host |
| Almacenar contenido no-imagen | Doble validación: header `Content-Type` + inspección binaria con `finfo` |
| Archivos parciales en disco | Escritura atómica: `write .tmp → rename()` — nunca queda un archivo corrupto |
| Enumeración de hashes (agotar cURL) | Negative cache: 404s se recuerdan durante `NEGATIVE_CACHE_TTL` segundos |
| Fuerza bruta en API key | `hash_equals()` previene timing attacks |
| Hotlinking desde sitios no autorizados | Reglas anti-hotlink en `.htaccess` (activar en producción) |
| Consumo de disco excesivo | Endpoint `/cleaner` + `cron.php` para limpieza manual, programada o por antigüedad |
| Ejecuciones concurrentes del cron | `flock()` sobre `cron.lock` — sólo una instancia a la vez |
| Eliminación sin trazabilidad | `logs/audit.log` registra IP, modo y resultado de cada `/cleaner` |
| Credenciales expuestas en repo | `.env` excluido vía `.gitignore`, sólo se commitea `.env.example` sin valores reales |
| MIME sniffing del navegador | Header `X-Content-Type-Options: nosniff` |
| XSS vía SVG malicioso | El CDN sólo almacena SVGs descargados de TMDB (origen confiable), no acepta uploads de usuarios |

## Recomendaciones para producción

1. **Configurar `API_SECRET` en `.env`** — sin esta clave los endpoints admin rechazan todas las peticiones
2. **Activar anti-hotlink** — descomentar las reglas en `.htaccess` y listar sólo los dominios autorizados
3. **HTTPS obligatorio** — configurar redirección HTTP → HTTPS a nivel de servidor
4. **Monitorear disco** — ejecutar `/get_stats` periódicamente y programar limpiezas con `/cleaner`
5. **Rate limiting** — considerar implementar límite de peticiones a nivel de servidor (mod_ratelimit o firewall) para prevenir abuso del proxy
6. **Restringir CORS** — si sólo se usa desde dominios propios, reemplazar `*` por los dominios específicos

## Reportar vulnerabilidades

Si encuentras una vulnerabilidad de seguridad, repórtala directamente a **doothemes@ews.pe** antes de divulgarla públicamente.
