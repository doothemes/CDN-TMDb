# Deuda Técnica — CDN Proxy TMDB

Registro histórico de la deuda técnica del proyecto. Documenta tanto lo que se ha resuelto como lo que queda pendiente, con el racional de cada decisión.

## Criterios de clasificación

| Severidad | Definición |
|-----------|------------|
| 🔴 **Crítico** | Causa bugs, corrupción de datos o inconsistencias — bloqueante |
| 🟠 **Alto** | Afecta seguridad, integridad o escalabilidad — debe atenderse pronto |
| 🟡 **Medio** | Mejora la calidad pero no es urgente — atender cuando sea conveniente |
| 🟢 **Bajo** | Cosmético o nice-to-have — atender si hay tiempo |

---

## ✅ Resuelto en v1.1.1 (patch de auditoría externa)

Hallazgos reportados por auditoría automatizada con GitHub Copilot.

### 🔴 Crítico

#### A-1. Headers de seguridad ausentes en hot path de Apache
**Problema:** Los headers `X-Content-Type-Options`, `Referrer-Policy` y CORS se establecían sólo en `serve_file()` de PHP, pero como el diseño del CDN hace que Apache sirva directamente los archivos cacheados (99%+ del tráfico), esos headers no llegaban al cliente en la práctica.
**Solución:** Movidos a `.htaccess` vía `mod_headers` para aplicar a todas las peticiones.

#### A-2. XSS vía SVG sin Content-Security-Policy
**Problema:** SVGs servidos con `image/svg+xml` sin CSP permitían ejecución de JavaScript embebido si TMDB (o un MITM) servía contenido malicioso.
**Solución:** `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'` aplicado a SVGs desde `.htaccess` (hot path) y `serve_file()` (cold path) como defensa en profundidad.

### 🟠 Alto

#### A-3. SSRF parcial por `CURLOPT_FOLLOWLOCATION` sin restricción
**Problema:** cURL seguía redirects sin validar protocolos. Un redirect a `http://169.254.169.254/` (AWS metadata) o `file:///etc/passwd` sería seguido.
**Solución:** `CURLOPT_PROTOCOLS` y `CURLOPT_REDIR_PROTOCOLS` restringidos a `CURLPROTO_HTTPS`, `CURLOPT_MAXREDIRS => 3`. Aplicado en `index.php` y en `verify_tmdb_availability()`.

#### A-4. Bypass en validación finfo vía `text/plain`
**Problema:** La excepción global para `text/plain` permitía que scripts PHP/HTML/JS detectados como texto plano pasaran la validación si TMDB enviaba `Content-Type: image/*`.
**Solución:** `text/plain` y XML sólo se aceptan cuando la extensión solicitada es `.svg`. Para el resto, sólo `image/*`.

#### A-5. `md5_file()` re-leía el archivo completo en primera petición
**Problema:** Después de `atomic_write($body)`, `serve_file()` hacía `md5_file($file)` leyendo el archivo completo de disco, cuando `$body` ya estaba en memoria. Para imágenes `original` de 10MB, 10MB de I/O innecesario.
**Solución:** `serve_file()` acepta un hash pre-calculado como tercer parámetro. En la ruta de descarga nueva se pasa `md5($body)` directamente.

#### A-6. HEAD requests secuenciales sin límite en cron
**Problema:** Con 10k archivos inactivos, `cron.php` podía tardar 14+ horas haciendo HEADs secuenciales a TMDB.
**Solución:** Nueva variable `MAX_HEAD_REQUESTS_PER_RUN` (default 500) que limita HEADs por ejecución. Los archivos excedentes se difieren a la siguiente corrida. Además, los archivos ya marcados como `.archival` se saltan antes de consumir presupuesto.

### 🟡 Medio

#### A-7. Respuesta 304 incluía `Content-Length` (RFC 7232)
**Problema:** `serve_file()` enviaba `Content-Length` antes de evaluar si la petición era condicional. Un 304 no debe incluir headers específicos del cuerpo.
**Solución:** Reordenados los headers — cache/validation primero, check de 304, luego body headers sólo si es 200.

#### A-8. `atomic_write()` retorno ignorado
**Problema:** Si fallaba la escritura (disco lleno, permisos), el error era silencioso y se intentaba servir desde un archivo inexistente.
**Solución:** Fallback que sirve desde memoria + `error_log()` cuando `atomic_write()` falla.

#### A-9. SECURITY.md desactualizado
**Problema:** Decía que CORS se configuraba editando `serve_file()` y mostraba el regex estático.
**Solución:** Actualizado a `CORS_ORIGIN` en `.env` y nota sobre patrón dinámico.

---

## ✅ Resuelto en v1.1.0

### 🔴 Crítico

#### 1. Código duplicado entre `index.php` y `cron.php`
**Problema:** Las funciones `cleanup_empty_dirs()` y `human_size()` estaban copiadas idénticamente en ambos archivos.
**Solución:** Creado [helpers.php](helpers.php) como módulo compartido.

#### 2. Extensiones definidas en múltiples lugares
**Problema:** La lista de extensiones permitidas existía en tres lugares — una constante `ALLOWED_EXTENSIONS` sin usar, un regex hardcodeado y el mapa `MIME_TYPES`. Cualquier cambio requería actualizar los tres.
**Solución:** `MIME_TYPES` en `helpers.php` es la única fuente de verdad. El regex se genera dinámicamente con `allowed_extensions_pattern()`.

#### 3. Carácter UTF-8 corrupto en `cron.php:108`
**Problema:** El comentario contenía un carácter `���` por una mala codificación.
**Solución:** Reescritura completa del archivo con UTF-8 limpio.

#### 4. Variable `$error` muerta
**Problema:** `$error = curl_error($ch)` se capturaba pero nunca se usaba — los fallos de cURL se silenciaban.
**Solución:** Ahora se escribe a `logs/error.log` via `log_line()`.

#### 5. `file_put_contents` no atómico
**Problema:** Si una escritura se interrumpía (timeout, kill, disco lleno), quedaba un archivo parcial que Apache servía como válido.
**Solución:** Función `atomic_write()` en helpers — escribe a `.tmp` y hace `rename()` (operación atómica en la mayoría de filesystems).

### 🟠 Alto

#### 6. Condiciones de carrera con peticiones concurrentes
**Problema:** Dos clientes pidiendo la misma imagen no-cacheada disparaban dos descargas de TMDB.
**Solución:** Con escritura atómica, si ambos escriben, el último `rename()` gana y el resultado es correcto. El consumo duplicado de cuota es aceptable frente a la complejidad de un lock distribuido.

#### 7. Sin lock file en `cron.php`
**Problema:** Dos ejecuciones del cron solapadas podían colisionar al eliminar archivos.
**Solución:** `flock()` sobre `cron.lock` — si ya hay una instancia corriendo, la nueva aborta.

#### 8. CORS hardcodeado
**Problema:** `Access-Control-Allow-Origin: *` estaba hardcodeado en `serve_file()`.
**Solución:** Variable `CORS_ORIGIN` en `.env` (default `*`).

#### 9. Vulnerable a enumeración de hashes
**Problema:** Un atacante podía enviar miles de hashes aleatorios, cada uno costando un `curl_exec()` con timeout de 15s.
**Solución:** Negative cache — los 404s de TMDB se recuerdan en archivos `.404` durante `NEGATIVE_CACHE_TTL` segundos (default 1 hora).

#### 10. Sin audit log para `/cleaner`
**Problema:** Si alguien obtenía la API key y ejecutaba `{"mode":"all"}`, no quedaba rastro.
**Solución:** Cada ejecución se registra en `logs/audit.log` con IP, modo, días y resultado.

### 🟡 Medio

#### 11. IPs de Googlebot hardcodeadas
**Problema:** El pool de 9 IPs `66.249.66.x` estaba en código. Google rota sus rangos periódicamente.
**Solución:** Variable `GOOGLEBOT_IPS` en `.env` como CSV. El default sigue siendo el pool hardcodeado.

#### 12. Content-Type de TMDB confiado ciegamente
**Problema:** Se confiaba en el header `Content-Type` de TMDB sin verificar el contenido real.
**Solución:** Validación doble — header + inspección binaria con `finfo::buffer()`.

#### 13. `env.php` sin soporte de comillas
**Problema:** `API_SECRET="valor con espacios"` dejaba las comillas literales.
**Solución:** Las comillas envolventes (simples o dobles) se eliminan automáticamente.

#### 14. `env.php` con fallo duro en contexto web
**Problema:** Si faltaba `.env`, se exponía un mensaje plain text al navegador.
**Solución:** Comportamiento diferenciado — CLI imprime a stderr, web responde 500 genérico.

#### 16. Sin headers de hardening
**Problema:** Faltaban `X-Content-Type-Options: nosniff` y `Referrer-Policy`.
**Solución:** Ambos headers añadidos en `serve_file()`.

### 🟢 Bajo

#### 19. Comentarios PHP sin acentos
**Problema:** Los DocBlocks en `index.php`, `cron.php`, `env.php` usaban texto sin acentos ni ñ — inconsistente con los `.md` corregidos.
**Solución:** Reescritura completa de todos los PHP con acentos correctos.

#### 20. `LICENSE.md` → `LICENSE`
**Problema:** GitHub detecta licencias estándar mejor con el nombre convencional.
**Solución:** `git mv LICENSE.md LICENSE`.

#### 22. Pérdida irreversible de imágenes huérfanas
**Problema:** TMDB elimina imágenes de su catálogo periódicamente (contenido retirado, cambios de distribución). Una vez eliminadas del origen, las ejecuciones de `cleaner` o `cron.php` podían borrarlas del CDN sin posibilidad de recuperación.
**Solución:** Protección archival automática. Antes de eliminar, se hace HEAD a TMDB — si responde 404/410, se marca con un sibling `.archival` y se preserva permanentemente. El HEAD solo ocurre durante limpieza (nunca afecta el serving) y una vez marcada, las siguientes rondas la saltan sin re-verificar. El endpoint `/cleaner` acepta `force: true` para casos donde realmente se necesita purgar todo (ej: migración de servidor).

#### 21. Logs sin límite de tamaño
**Problema:** `audit.log` y `error.log` crecían indefinidamente. En entornos con muchos errores de cURL o limpiezas frecuentes, podían llegar a consumir GBs de disco.
**Solución:** Rotación automática en `log_line()` — cuando un archivo supera `LOG_MAX_SIZE_MB` (default 5 MB), se rota a `.1`, la antigua `.1` pasa a `.2`, y así sucesivamente hasta `LOG_KEEP_FILES` (default 5). La rotación más antigua se elimina. Espacio máximo garantizado por log: **25 MB**.

---

## ⏳ Pendiente

### 🟢 Bajo

#### 15. `readfile()` para imágenes grandes
**Estado:** Revisado — se deja como está.
**Racional:** `readfile()` en PHP implementa streaming por dentro. No carga el archivo completo en memoria a menos que haya output buffering activo (no es nuestro caso). Para las imágenes `original` (máx ~10MB en TMDB) el rendimiento es correcto.
**Condición para revisar:** Si se detectan picos de uso de memoria en producción al servir imágenes grandes.

#### 17. Sin tests automatizados
**Estado:** Pendiente.
**Racional:** Requiere decidir framework (PHPUnit vs Pest) y diseñar fixtures que no dependan de TMDB (probablemente mockear cURL). Coste alto, valor marginal para un proyecto de este tamaño.
**Propuesta para v1.2:** Empezar con smoke tests sobre los endpoints usando `curl` + bash.

#### 18. Sin CI/CD
**Estado:** Pendiente.
**Racional:** Sin tests no hay mucho que correr automáticamente. Mínimo viable: GitHub Actions con `php -l` sobre cada archivo para detectar errores de sintaxis en PRs.
**Propuesta para v1.2:** Workflow simple de lint en cada push.

---

## Principios para evitar deuda técnica futura

1. **Fuente única de verdad**: si un valor (extensión, MIME, IP, umbral) vive en más de un lugar, extraerlo a helpers o `.env`.
2. **Escrituras atómicas siempre**: cualquier operación que cree archivos debe ser resistente a interrupciones.
3. **Logs para operaciones sensibles**: toda acción que modifique estado (cleaner, errores upstream) debe quedar registrada.
4. **Configuración fuera del código**: valores que cambian entre entornos (producción, desarrollo) van a `.env`, nunca hardcodeados.
5. **Validación defensiva en bordes**: lo que viene de afuera (TMDB, cliente HTTP) se valida — el doble-check de Content-Type es un buen ejemplo.
6. **Documentar al commitear**: si se añade una característica, el README y SECURITY.md deben reflejarla en el mismo commit.

---

**Última actualización:** 2026-04-17 (v1.1.0)
