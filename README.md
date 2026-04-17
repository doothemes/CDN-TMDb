# CDN Proxy — TMDB Images

Proxy inverso con almacenamiento permanente para imagenes de [The Movie Database](https://www.themoviedb.org/). Descarga las imagenes desde `image.tmdb.org`, las almacena en disco y las sirve desde tu propio dominio.

Despues de la primera descarga, Apache sirve las imagenes como archivos estaticos — PHP no interviene.

## Requisitos

- PHP 8.1+
- Apache con `mod_rewrite` y `mod_expires`
- Extension `curl` habilitada

## Estructura

```
cdn.dbmvs/
├── .htaccess       # Rewrite rules + cache + anti-hotlink
├── index.php       # Proxy + endpoints administrativos
├── .gitignore      # Excluye /t/ del repositorio
└── t/              # Imagenes almacenadas (se crea automaticamente)
    └── p/
        ├── w500/
        ├── w780/
        ├── original/
        └── ...
```

## Uso

Reemplaza el host de TMDB por el de tu CDN:

```
# Antes (directo a TMDB)
https://image.tmdb.org/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg

# Despues (tu CDN)
https://cdn.dbmvs.io/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg
```

```html
<img src="https://cdn.dbmvs.io/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg" alt="Poster">
```

### Flujo de una peticion

```
1ra peticion:
  Cliente → Apache (no existe en disco) → index.php → descarga de TMDB → guarda en /t/p/w500/ → responde

2da peticion en adelante:
  Cliente → Apache (archivo existe) → sirve directo como estatico (PHP no se ejecuta)
```

## .htaccess

El archivo `.htaccess` tiene tres bloques funcionales:

### Rewrite (mod_rewrite)

Controla el flujo principal del CDN. Si el archivo solicitado ya existe fisicamente en disco, Apache lo sirve como estatico sin tocar PHP. Solo cuando el archivo **no existe** (primera peticion de esa imagen) se enruta a `index.php` para descargarlo de TMDB.

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

### Anti-hotlink

Bloquea el uso de las imagenes desde dominios no autorizados. Viene **desactivado por defecto** (lineas comentadas con `###`). Cuando se activa, funciona asi:

1. Si el `Referer` esta vacio → permite (acceso directo, apps, bots)
2. Si el `Referer` coincide con un dominio permitido → permite
3. Si no coincide con ninguno → devuelve `403 Forbidden`

Las condiciones son AND: **todas** deben fallar para que se aplique el bloqueo. Para agregar un dominio permitido, anadir una linea:

```apache
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?tudominio\.com [NC]
```

### Cache de navegador (mod_expires)

Configura cache a nivel de Apache para los archivos estaticos que se sirven sin pasar por PHP. Esto complementa los headers `Cache-Control` que `index.php` envia durante la primera descarga.

```apache
ExpiresByType image/jpeg "access plus 1 year"
ExpiresByType image/png "access plus 1 year"
ExpiresByType image/webp "access plus 1 year"
ExpiresByType image/svg+xml "access plus 1 year"
```

Cache de **1 ano** para todos los tipos de imagen que maneja el CDN. Es seguro porque las imagenes de TMDB son inmutables — si el contenido cambia, el hash del nombre de archivo cambia tambien.

## Configuracion

### Clave secreta

Editar la constante `API_SECRET` en `index.php` antes de desplegar a produccion:

```php
define('API_SECRET', 'tu-clave-secreta-aqui');
```

### Anti-hotlink

El `.htaccess` incluye reglas de proteccion anti-hotlink comentadas. Para activarlas, descomentar las lineas `###` y ajustar los dominios permitidos:

```apache
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?tudominio\.com [NC]
RewriteRule \.(jpg|jpeg|png|webp|svg)$ - [F,NC,L]
```

## Endpoints administrativos

Todos los endpoints requieren el header `X-Api-Key` con el valor de `API_SECRET`.

### GET /get_stats

Devuelve estadisticas de almacenamiento del CDN.

```bash
curl -H "X-Api-Key: tu-clave-secreta" https://cdn.dbmvs.io/get_stats
```

Respuesta:

```json
{
    "folders": 3,
    "files": 1240,
    "size": {
        "bytes": 52428800,
        "human": "50 MB"
    }
}
```

| Campo | Descripcion |
|-------|-------------|
| `folders` | Numero total de carpetas dentro de `/t/` |
| `files` | Numero total de imagenes almacenadas |
| `size.bytes` | Espacio en disco en bytes |
| `size.human` | Espacio en disco en formato legible |

### POST /cleaner

Ejecuta limpieza de imagenes almacenadas. Soporta dos modos:

**Limpieza total** — elimina todas las imagenes y carpetas:

```bash
curl -X POST \
  -H "X-Api-Key: tu-clave-secreta" \
  -d '{"mode": "all"}' \
  https://cdn.dbmvs.io/cleaner
```

**Limpieza por antiguedad** — elimina archivos con mas de X dias desde su descarga:

```bash
curl -X POST \
  -H "X-Api-Key: tu-clave-secreta" \
  -d '{"mode": "older_than", "days": 30}' \
  https://cdn.dbmvs.io/cleaner
```

Respuesta:

```json
{
    "mode": "older_than",
    "days": 30,
    "deleted_files": 87,
    "deleted_folders": 2
}
```

| Campo | Descripcion |
|-------|-------------|
| `mode` | Modo de limpieza ejecutado (`all` o `older_than`) |
| `days` | Dias de antiguedad (solo en modo `older_than`) |
| `deleted_files` | Numero de imagenes eliminadas |
| `deleted_folders` | Numero de carpetas vacias eliminadas |

## Seguridad

- **Validacion de paths**: regex estricto que solo acepta `/t/p/{size}/{hash}.{ext}` — previene path traversal y SSRF
- **Extensiones limitadas**: solo `jpg`, `jpeg`, `png`, `webp`, `svg`
- **Validacion de Content-Type**: verifica que TMDB devuelva `image/*` antes de almacenar
- **Anti-hotlink**: proteccion configurable por dominio via `.htaccess`
- **API protegida**: endpoints admin requieren `X-Api-Key` validado con `hash_equals()` (resistente a timing attacks)
- **CORS**: `Access-Control-Allow-Origin: *` habilitado para uso en multiples dominios

## Headers de cache

| Header | Valor | Proposito |
|--------|-------|-----------|
| `Cache-Control` | `public, max-age=2592000, immutable` | Cache del navegador por 30 dias |
| `ETag` | MD5 del archivo | Validacion condicional (304 Not Modified) |
| `Last-Modified` | Fecha de descarga | Validacion condicional alternativa |
| `X-Cache` | `HIT` | Indica que la imagen se sirvio desde el CDN |
| `Access-Control-Allow-Origin` | `*` | Permite uso cross-origin |
