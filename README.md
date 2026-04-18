# CDN Proxy — TMDB Images

> 🇪🇸 **Español** · [🇬🇧 English](README.en.md)

Proxy inverso con almacenamiento permanente para imágenes de [The Movie Database](https://www.themoviedb.org/). Descarga las imágenes desde `image.tmdb.org`, las almacena en disco y las sirve desde tu propio dominio.

Después de la primera descarga, Apache sirve las imágenes como archivos estáticos — PHP no interviene.

## Requisitos

- PHP 8.1+
- Apache con `mod_rewrite` y `mod_expires`
- Extensión `curl` habilitada

## Estructura

```
cdn.dbmvs/
├── .htaccess       # Rewrite rules + cache + anti-hotlink
├── .env.example    # Template de configuración
├── .env            # Variables de entorno (no se commitea)
├── .gitattributes  # Normaliza line endings y controla exports
├── .gitignore      # Excluye /t/, /logs/, .env y cron.lock
├── env.php         # Loader de variables de entorno
├── helpers.php     # Funciones compartidas entre index.php y cron.php
├── index.php       # Proxy + endpoints administrativos
├── cron.php        # Tarea programada para limpieza automática
├── logs/           # Logs de auditoría y errores (se crea automáticamente)
│   ├── audit.log   # Registro de operaciones sensibles (cleaner)
│   ├── audit.log.1 # Rotaciones antiguas (hasta LOG_KEEP_FILES)
│   ├── error.log   # Errores de cURL y fallos upstream
│   └── error.log.1 # Rotaciones antiguas
└── t/              # Imágenes almacenadas (se crea automáticamente)
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

# Después (tu CDN)
https://cdn.dbmvs.io/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg
```

```html
<img src="https://cdn.dbmvs.io/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg" alt="Poster">
```

### Flujo de una petición

```
1ra petición:
  Cliente → Apache (no existe en disco) → index.php → descarga de TMDB → guarda en /t/p/w500/ → responde

2da petición en adelante:
  Cliente → Apache (archivo existe) → sirve directo como estático (PHP no se ejecuta)
```

## .htaccess

El archivo `.htaccess` tiene tres bloques funcionales:

### Rewrite (mod_rewrite)

Controla el flujo principal del CDN. Si el archivo solicitado ya existe físicamente en disco, Apache lo sirve como estático sin tocar PHP. Sólo cuando el archivo **no existe** (primera petición de esa imagen) se enruta a `index.php` para descargarlo de TMDB.

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

### Anti-hotlink

Bloquea el uso de las imágenes desde dominios no autorizados. Viene **desactivado por defecto** (líneas comentadas con `###`). Cuando se activa, funciona así:

1. Si el `Referer` está vacío → permite (acceso directo, apps, bots)
2. Si el `Referer` coincide con un dominio permitido → permite
3. Si no coincide con ninguno → devuelve `403 Forbidden`

Las condiciones son AND: **todas** deben fallar para que se aplique el bloqueo. Para agregar un dominio permitido, añadir una línea:

```apache
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?tudominio\.com [NC]
```

### Caché de navegador (mod_expires)

Configura caché a nivel de Apache para los archivos estáticos que se sirven sin pasar por PHP. Esto complementa los headers `Cache-Control` que `index.php` envía durante la primera descarga.

```apache
ExpiresByType image/jpeg "access plus 1 year"
ExpiresByType image/png "access plus 1 year"
ExpiresByType image/webp "access plus 1 year"
ExpiresByType image/svg+xml "access plus 1 year"
```

Caché de **1 año** para todos los tipos de imagen que maneja el CDN. Es seguro porque las imágenes de TMDB son inmutables — si el contenido cambia, el hash del nombre de archivo cambia también.

## Instalación

1. Clonar o descargar el repositorio en el document root del dominio
2. Copiar el template de configuración:
   ```bash
   cp .env.example .env
   ```
3. Editar `.env` con los valores de producción:
   ```env
   API_SECRET=tu-clave-secreta-aqui
   MAX_INACTIVE_DAYS=30
   ```
4. Verificar que Apache tenga `mod_rewrite` y `mod_expires` habilitados
5. (Opcional) Programar `cron.php` en crontab para limpieza automática

## Configuración (.env)

Toda la configuración se gestiona desde el archivo `.env` en la raíz del proyecto. El archivo `env.php` se encarga de leer las variables y exponerlas vía la función `env()`.

| Variable | Descripción | Default |
|----------|-------------|---------|
| `API_SECRET` | Clave secreta para endpoints administrativos (`get_stats`, `cleaner`) | *(vacío)* |
| `MAX_INACTIVE_DAYS` | Días máximos sin acceso antes de que el cron elimine una imagen | `30` |
| `CORS_ORIGIN` | Valor del header `Access-Control-Allow-Origin` | `*` |
| `NEGATIVE_CACHE_TTL` | Segundos que se recuerda un 404 de TMDB para evitar repetir peticiones inválidas | `3600` |
| `GOOGLEBOT_IPS` | Pool de IPs de Googlebot (CSV) para rotar en `X-Forwarded-For` | *(9 IPs hardcodeadas)* |
| `LOG_MAX_SIZE_MB` | Tamaño máximo en MB antes de rotar un log automáticamente | `5` |
| `LOG_KEEP_FILES` | Número de rotaciones antiguas que se conservan | `5` |

### Anti-hotlink

El `.htaccess` incluye reglas de protección anti-hotlink comentadas. Para activarlas, descomentar las líneas `###` y ajustar los dominios permitidos:

```apache
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?tudominio\.com [NC]
RewriteRule \.(jpg|jpeg|png|webp|svg)$ - [F,NC,L]
```

## Endpoints administrativos

Todos los endpoints requieren el header `X-Api-Key` con el valor de `API_SECRET`.

### GET /get_stats

Devuelve estadísticas de almacenamiento del CDN.

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

| Campo | Descripción |
|-------|-------------|
| `folders` | Número total de carpetas dentro de `/t/` |
| `files` | Número total de imágenes almacenadas |
| `size.bytes` | Espacio en disco en bytes |
| `size.human` | Espacio en disco en formato legible |

### POST /cleaner

Ejecuta limpieza de imágenes almacenadas. Soporta dos modos:

**Limpieza total** — elimina todas las imágenes y carpetas:

```bash
curl -X POST \
  -H "X-Api-Key: tu-clave-secreta" \
  -d '{"mode": "all"}' \
  https://cdn.dbmvs.io/cleaner
```

**Limpieza por antigüedad** — elimina archivos con más de X días desde su descarga:

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

| Campo | Descripción |
|-------|-------------|
| `mode` | Modo de limpieza ejecutado (`all` o `older_than`) |
| `days` | Días de antigüedad (sólo en modo `older_than`) |
| `deleted_files` | Número de imágenes eliminadas |
| `deleted_folders` | Número de carpetas vacías eliminadas |

## Tarea CRON (cron.php)

Script de limpieza automática que se ejecuta desde la línea de comandos. Elimina imágenes que no han sido consultadas en un período de tiempo configurable y limpia las carpetas vacías que queden huérfanas.

### Configuración

El umbral de inactividad se configura en `.env`:

```env
MAX_INACTIVE_DAYS=30
```

El script usa `fileatime()` (último acceso del sistema operativo) para determinar cuándo fue la última vez que se consultó una imagen. Si el filesystem no soporta atime, usa `filemtime()` (fecha de descarga) como fallback.

### Ejecución manual

```bash
php /ruta/al/cdn.dbmvs/cron.php
```

### Programar en crontab

```bash
# Ejecutar todos los días a las 3:00 AM
0 3 * * * php /ruta/al/cdn.dbmvs/cron.php >> /var/log/cdn-cleanup.log 2>&1

# Ejecutar cada lunes a las 2:00 AM
0 2 * * 1 php /ruta/al/cdn.dbmvs/cron.php >> /var/log/cdn-cleanup.log 2>&1
```

### Salida del reporte

Cada ejecución genera un reporte con timestamp para los logs:

```
[03:00:01] Iniciando limpieza del CDN...
[03:00:01] Eliminando imágenes sin acceso en los últimos 30 días.
[03:00:01] Fecha límite: 2026-03-18 03:00:01
[03:00:01] --------------------------------------------------
[03:00:01] --------------------------------------------------
[03:00:01] Limpieza completada: 2026-04-17 03:00:01
[03:00:01]   Archivos eliminados:  87 (124.5 MB liberados)
[03:00:01]   Carpetas eliminadas:  2
[03:00:01]   Archivos conservados: 953
```

| Campo | Descripción |
|-------|-------------|
| Archivos eliminados | Imágenes que superaron el umbral de inactividad (con espacio liberado) |
| Carpetas eliminadas | Directorios vacíos que quedaron huérfanos tras la limpieza |
| Archivos conservados | Imágenes que aún están dentro del período de actividad |

### Seguridad

- Sólo se puede ejecutar desde CLI (`php_sapi_name() === 'cli'`). Si se intenta acceder vía web, devuelve `403 Forbidden`
- No requiere API key porque el acceso al servidor ya implica autorización

## Identidad de Googlebot

Para evitar rate-limiting y bloqueos por parte del CDN de TMDB, cada petición a `image.tmdb.org` se envía simulando ser un crawler de Google. Esta estrategia combina:

- **User-Agent de Googlebot**: `Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)`
- **Pool de 9 IPs de Googlebot** (`66.249.66.x`) rotadas aleatoriamente en cada petición
- **Header `X-Forwarded-For`** con la IP seleccionada — respetado por muchos CDNs y proxies reversos como IP de origen

TMDB, al igual que otros servicios, otorga tratamiento preferencial a los crawlers de Google (whitelisted) para permitir indexación. Aprovechar esa identidad reduce drásticamente la probabilidad de ser rate-limited durante descargas masivas de imágenes.

## Logs

El CDN mantiene dos logs separados en `/logs/`:

| Archivo | Contenido |
|---------|-----------|
| `audit.log` | Ejecuciones de `POST /cleaner` con IP, modo y resultado |
| `error.log` | Fallos de cURL al descargar de TMDB (timeouts, DNS, etc.) |

### Rotación automática

Cuando un log alcanza `LOG_MAX_SIZE_MB` (default 5 MB), se rota automáticamente:

```
audit.log      ← archivo activo
audit.log.1    ← rotación más reciente
audit.log.2
...
audit.log.5    ← la más antigua
```

Al rotar de nuevo, `audit.log.5` se elimina, el resto se desplaza y el activo pasa a `.1`. El límite de rotaciones se configura con `LOG_KEEP_FILES`.

Con defaults (5 MB × 5 rotaciones), el espacio máximo por log es **25 MB**.

## Seguridad

- **Validación de paths**: regex estricto que sólo acepta `/t/p/{size}/{hash}.{ext}` — previene path traversal y SSRF
- **Extensiones limitadas**: derivadas de `MIME_TYPES` en `helpers.php` (fuente única)
- **Doble validación de contenido**: header `Content-Type` + inspección binaria con `finfo`
- **Escritura atómica**: `file_put_contents` a `.tmp` + `rename()` evita archivos parciales
- **Negative cache**: 404s de TMDB se recuerdan para prevenir ataques de enumeración de hashes
- **Lock file en cron**: previene ejecuciones concurrentes del limpiador
- **Audit log**: cada ejecución de `/cleaner` se registra con IP, modo y resultado
- **Anti-hotlink**: protección configurable por dominio vía `.htaccess`
- **API protegida**: endpoints admin requieren `X-Api-Key` validado con `hash_equals()` (resistente a timing attacks)
- **CORS configurable**: `Access-Control-Allow-Origin` vía `.env`
- **Hardening**: `X-Content-Type-Options: nosniff` y `Referrer-Policy`

## Headers de caché

| Header | Valor | Propósito |
|--------|-------|-----------|
| `Cache-Control` | `public, max-age=2592000, immutable` | Caché del navegador por 30 días |
| `ETag` | MD5 del archivo | Validación condicional (304 Not Modified) |
| `Last-Modified` | Fecha de descarga | Validación condicional alternativa |
| `X-Cache` | `HIT` | Indica que la imagen se sirvió desde el CDN |
| `Access-Control-Allow-Origin` | `*` | Permite uso cross-origin |

## ¿Por qué un CDN propio?

Desplegar tu propio CDN para imágenes de TMDB en lugar de consumirlas directamente desde `image.tmdb.org` ofrece ventajas significativas:

### 1. Control sobre la disponibilidad
TMDB puede aplicar rate-limiting, cambiar sus URLs, o restringir acceso por región. Un CDN propio desacopla tu plataforma de esas decisiones — tus imágenes ya están en tu infraestructura.

### 2. Rendimiento y caché del navegador
Servir desde `cdn.tudominio.com` permite headers agresivos (`Cache-Control: immutable`, 1 año) y caché compartido entre tus propios sitios. Cada dominio tiene su propio bucket de caché en el navegador — usar tu CDN unifica las peticiones.

### 3. Independencia del origen
Si TMDB cae, tiene mantenimiento o bloquea tu IP, tu plataforma sigue funcionando porque las imágenes ya están en disco.

### 4. Anti-hotlinking y control de uso
Puedes restringir qué dominios usan tus imágenes vía `Referer`. No es posible con el CDN público de TMDB.

### 5. Evasión de bloqueos geográficos
Algunos países o ISPs bloquean `image.tmdb.org`. Servir desde tu dominio bypasa esos bloqueos.

### 6. Ahorro de ancho de banda en múltiples sitios
Cuando tienes varios sitios (dbmvs.com, wovie.co, doothemes.com) consumiendo las mismas imágenes, un CDN centralizado evita que cada sitio descargue la misma imagen de TMDB. Una sola descarga sirve a todos.

### 7. Cumplimiento de términos de TMDB
TMDB tiene límites de peticiones por API key. Con el CDN, una imagen se descarga **una sola vez** sin importar cuántas veces se solicite después. Esto reduce drásticamente la cuota consumida.

### 8. Logs y analítica
Tienes visibilidad completa de qué imágenes se solicitan, desde qué dominios y con qué frecuencia. Imposible con el CDN de terceros.

### 9. Optimización futura
Con las imágenes en disco propio, puedes añadir procesamiento: conversión a WebP/AVIF, redimensionado dinámico, compresión agresiva, watermarks — sin tocar el origen.

### 10. Costos predecibles
El CDN de TMDB es gratuito pero no garantizado. Un servidor propio tiene costos fijos y predecibles, sin sorpresas por cuotas excedidas o términos que cambian.

### 11. Resiliencia ante cambios de política
TMDB podría, en cualquier momento, requerir autenticación para imágenes, cobrar por tráfico, o restringir uso comercial. Tener las imágenes ya descargadas protege tu negocio de esos cambios.
