<!-- Source: docs/compatibility.md; SHA256: 4823a4f4a82a0b8bbb36b0a9d34f89a56e61974db5b801cd9399330c7f4e1feb -->

<a id="compatibility"></a>
# Compatibilidad

Esta página recoge las versiones compatibles, las pruebas realizadas y las interfaces externas de las que depende el complemento. Los datos de Cacti proceden de las etiquetas `release/1.2.25` y `release/1.2.31`; los de Cloudflare, de [`research/cloudflare-api-facts.md`](../../../research/cloudflare-api-facts.md).

<a id="summary"></a>
## Resumen

| Componente | Compatible | Probado |
|---|---|---|
| Cacti | De 1.2.25 a 1.2.31 (`compat = 1.2.25` en INFO) | 1.2.31 mediante `make smoke` en CI en cada solicitud de incorporación. 1.2.25 solo manualmente con `make smoke CACTI_REF=release/1.2.25`, cuyos 22 pasos pasaron por última vez el 2026-10-22; los cambios posteriores no se han probado en 1.2.25 |
| PHP | De 7.4 a 8.4 | Pruebas unitarias en 7.4, 8.1, 8.2, 8.3, 8.4; integración en 7.4 y 8.3; smoke en 8.0; `php -l` en 7.4 |
| Base de datos | MariaDB 10.6 y posteriores, MySQL 8.0 y posteriores | CI: MariaDB 10.11, MySQL 8.4. También se ejecutaron localmente las pruebas de integración y BDD el 2026-10-22 con MariaDB 10.6.28 y MySQL 8.0.46; no forman parte de CI |
| Extensiones PHP | `curl`, `openssl`, `pdo_mysql` | Comprobadas por `plugin_cloudflare_check_config()` |
| Cacti 1.3 | No es un objetivo de publicación | Sin probar |

<a id="cacti"></a>
## Cacti

<a id="php-floor"></a>
### Versión mínima de PHP

El complemento mantiene la sintaxis de PHP 7.4. Cacti de 1.2.25 a 1.2.30 no tiene comprobación de plataforma de Composer y funciona con PHP 7.4; INFO declara `compat = 1.2.25`.

Cacti 1.2.31 requiere PHP 8.0 o posterior. Su `composer.json` declara `"php": ">=8.0"`, y `include/vendor/composer/platform_check.php` detiene la ejecución en versiones anteriores. `include/global.php:555` carga esa comprobación mediante el autocargador de Composer. Por tanto, en 1.2.31 el complemento se ejecuta con PHP 8.0 o posterior y la prueba smoke de instalación utiliza la imagen `php:8.0-apache`.

<a id="hooks-and-apis-used"></a>
### Hooks y API utilizados

El complemento no modifica ficheros del núcleo de Cacti. Registra cinco hooks y tres ámbitos de permisos en `plugin_cloudflare_install()`:

| Interfaz | Uso | Referencia (1.2.31) |
|---|---|---|
| Hook `config_arrays` | Añade «Cloudflare Accounts» a Configuration cuando está activado | `include/global_arrays.php` |
| Hook `config_settings` | Añade Settings > Cloudflare y ejecuta migraciones pendientes | `include/global_settings.php` |
| Hook `draw_navigation_text` | Ruta de navegación para `cloudflare_accounts.php` | `lib/functions.php` |
| Hook `poller_bottom` | Inicia el recolector en segundo plano tras cada ciclo, solo en el sondeador principal | `poller.php:943`, `poller.php:956` |
| Hook `auth_alternate_realms` | Resuelve la herencia Configure > Operator > View a partir de las concesiones actuales de usuarios y grupos, sin cambiar las concesiones almacenadas | `include/auth.php` |
| `exec_background()` | Inicia `poller_cloudflare.php` separado de `poller.php`. El complemento pasa los argumentos como cadena, porque los arrays solo se aceptan desde 1.2.31 | `lib/poller.php:132` |
| `api_plugin_register_realm()` | «Cloudflare: View» (`cloudflare.php`), «Cloudflare: Operator» (`cloudflare_operator.php`) y «Cloudflare: Configure» (`cloudflare_accounts.php`) | `lib/plugins.php` |
| `api_plugin_valid_entrypoint()` | Exige que las llamadas de registro procedan de una función cuyo nombre contenga install, upgrade o setup; por ello se mantienen dentro de `plugin_cloudflare_install()` | `lib/plugins.php:1124` |
| `api_plugin_uninstall()` | Cacti elimina hooks, ámbitos y `plugin_config` cuando retorna la función de desinstalación del complemento | `lib/plugins.php:801` |
| `api_plugin_disable_hooks()`, `api_plugin_load_realms()` | Cacti mantiene los ámbitos cargados y `config_arrays` activo con el complemento desactivado; por ello cada punto de entrada comprueba `api_plugin_is_enabled()` | `lib/plugins.php:1103`, `lib/plugins.php:1268` |
| Comprobación de ámbito en `include/auth.php` | Aplica el ámbito de una página según su nombre base | `include/auth.php:183` |
| `$database_sessions` | El complemento reutiliza el identificador PDO de Cacti | `lib/database.php:129` |
| `db_connect_real()` | Abre la conexión con los argumentos de Cacti cuando aún no hay identificador | `include/global.php:363` |

<a id="the-poller_bottom-hook-on-upgrade"></a>
### Hook `poller_bottom` durante la actualización

La versión 0.2.0 añadió `poller_bottom`. `api_plugin_register_hook()` inserta un hook que no sea de configuración con estado 0, desactivado, salvo que el llamador solicite activarlo (`lib/plugins.php:1030`, rama `$enable` en `lib/plugins.php:1048`). Cacti no vuelve a activar un complemento ya activado, por lo que actualizar desde 0.1.0 dejaría el hook apagado y el recolector nunca arrancaría. Por ello `plugin_cloudflare_upgrade()` registra el hook para instalaciones anteriores a 0.2.0 con el estado de activación actual del complemento. Una instalación nueva lo registra con los demás hooks, y Cacti los activa todos cuando se activa el complemento.

El hook solo actúa en el sondeador principal. `poller.php` también ejecuta `poller_bottom` en sondeadores remotos (`poller.php:956`), pero el hook retorna sin hacer nada cuando `poller_id` no es 1. INFO declara `remote_collect:0`.

El núcleo de Cacti nunca llama a la función de actualización de un complemento (solo `cli/audit_database.php` busca una), por lo que `plugin_cloudflare_upgrade()` se ejecuta desde `plugin_cloudflare_check_config()`, desde `config_settings` en `settings.php` y `plugins.php`, y al principio de `cloudflare_accounts.php`, que requiere Cloudflare: Configure. `cloudflare.php` no la ejecuta: pertenece a Cloudflare: View y una actualización migra el esquema, importa plantillas y escribe `resource/script_server/cloudflare_tunnels.xml`. Hasta que un administrador abra una de esas páginas, las páginas de visualización muestran «The Cloudflare tunnels could not be read» si falta el esquema que leen.

Todas las funciones y hooks anteriores existen en `release/1.2.25`. La página solo llama a `CactiSecureHeaders::getNonceAttribute()` si existe esa clase, porque las versiones 1.2 antiguas no la tienen.

<a id="cacti-apis-and-tables-used-for-devices-graphs-and-templates"></a>
### API y tablas de Cacti para dispositivos, gráficos y plantillas

La versión 0.3.0 llama a estas funciones con las firmas de ambas versiones. Las líneas corresponden a las etiquetas `release/1.2.25` y `release/1.2.31`.

| Interfaz | Uso | 1.2.25 | 1.2.31 |
|---|---|---|---|
| `api_user_realm_auth()` | Comprueba el ámbito de `host.php` | `lib/plugins.php:35` | `lib/plugins.php:35` |
| `api_device_save()` | Crea el dispositivo (26 argumentos, `poller_id` 1) | `lib/api_device.php:939` | `lib/api_device.php:939` |
| Método de reindexación None para SNMP 0 | Lo establece `api_device_save()` | `lib/api_device.php:1073-1077` | `lib/api_device.php:1073-1077` |
| `api_device_update_host_template()` | Llamada desde `api_device_save()`; vuelve a añadir las consultas con el método global predeterminado | llamada `:1131`, función `:1226`, `REPLACE` `:1266` | llamada `:1131`, función `:1245`, `REPLACE` `:1285` |
| `api_device_dq_add()` | Restablece None y ejecuta la consulta | `lib/api_device.php:552-578` | `lib/api_device.php:552-578` |
| `api_device_remove()` | Elimina un host creado que el complemento no pudo registrar | `lib/api_device.php:45` | `lib/api_device.php:45` |
| `DATA_QUERY_AUTOINDEX_NONE` | Método de reindexación None | `include/global_constants.php:75` | `include/global_constants.php:75` |
| `run_data_query()` | Reindexa con `$force` true | `lib/data_query.php:25` | `lib/data_query.php:25` |
| Marcado de huérfanos y actualización de títulos en `run_data_query()` | Marca de huérfano; actualización del título con `$force` | `lib/data_query.php:317`, `:356-368` | `lib/data_query.php:317`, `:356-368` |
| `get_data_query_array()` | Lee el XML de consulta | `lib/data_query.php:599`, sin comprobación de ruta, `file_exists()` en `:612` | `lib/data_query.php:599`, comprobación de ruta `:612-620` |
| `cacti_path_is_within()` | Comprobación de ruta de 1.2.31 mediante `realpath()` | no existe | `lib/functions.php:8058` |
| `query_script_host()` | Ejecuta el script como CLI para index, query y num_indexes | `lib/data_query.php:635`, prefijo del servidor de scripts `:646` | `lib/data_query.php:645`, prefijo del servidor de scripts `:656` |
| División por `output_delimiter` | Divide una línea de lista en el primer `!` | `lib/data_query.php:738` | `lib/data_query.php:748` |
| Llamada `get` del servidor de scripts | `<arg_prepend>`, `get`, campo, índice | `lib/utility.php:431` (índice sin comillas) | `lib/utility.php:544` (índice entre comillas) |
| Comprobaciones de inclusión y función del servidor de scripts | Dónde puede estar `ss_cloudflare.php` | sin comprobación de ruta base (`script_server.php:261`, `:278`) | `script_server.php:264-290`, `:327-361` |
| `get_best_data_query_index_type()` | Tipo de índice para un gráfico nuevo | `lib/data_query.php:2285` | `lib/data_query.php:2318` |
| `import_xml_data()` | Importa plantillas por hash | `lib/import.php:25`, retornos de fallo `:39`, `:56`, `:177-227`, `result` `fail` `:901`, `:1209`, `:1477` | `lib/import.php:25`, retornos de fallo `:39`, `:56`, `:177-227`, `result` `fail` `:978`, `:1286`, `:1554` |
| Versión de hash de plantilla `0103` | Prefijo del hash del XML distribuido | `include/global_arrays.php:135-140` (de 1.2.20 a 1.2.25) | `include/global_arrays.php:135-146` (de 1.2.20 a 1.2.31) |
| `create_complete_graph_from_template()` | Crea gráfico y fuente de datos | `lib/template.php:1480` | `lib/template.php:1480` |
| `data_source_exists()` | Permite que los gráficos posteriores del túnel reutilicen su fuente de datos | `lib/template.php:2194`, llamada `:1596`, reutilización `:1615` | `lib/template.php:2194`, llamada `:1594`, reutilización `:1613` |
| `push_out_host()` | Envía una fuente de datos nueva al sondeador | `lib/utility.php:687` | `lib/utility.php:834` |
| `retemplate_graphs()`, `update_replication_crc()` | Se cargan porque la importación las llama | `lib/template.php:873`, `lib/utility.php:29` | `lib/template.php:873`, `lib/utility.php:29` |
| Inclusiones de `host.php` | Bibliotecas cargadas antes de guardar un dispositivo | `host.php:26-39` | `host.php:26-39` |
| `cli/add_device.php`, `cli/add_graphs.php` | Modelo para argumentos del dispositivo y adopción de gráficos existentes | `add_device.php:199`, `add_graphs.php:659-716` | `add_device.php:199`, `add_graphs.php:659-716` |
| Ámbito de `host.php` | Ámbito 3, Sites/Devices/Data | `include/global_arrays.php:1227`, `:1285` | `include/global_arrays.php:1240`, `:1298` |
| `read_config_option()`, `set_config_option()` | Ajustes del hash de plantilla y host ausente | `lib/functions.php:679`, `:419` | `lib/functions.php:680`, `:418` |
| `db_fetch_cell_prepared()` | Lee tablas de Cacti | `lib/database.php:568` | `lib/database.php:665` |
| `raise_message()`, `is_error_message()` | Resultado de creación de dispositivo y de `api_device_save()` | `lib/functions.php:993`, `:890` | `lib/functions.php:996`, `:893` |

Tablas que el complemento lee o escribe en el esquema de Cacti, con la línea `CREATE TABLE` de `cacti.sql` (1.2.25 / 1.2.31). Las lee mediante sentencias preparadas; las escrituras pasan por las funciones anteriores.

| Tabla | Uso | Líneas |
|---|---|---|
| `host` | Existencia y marca `deleted` del dispositivo | 1876 / 1877 |
| `host_snmp_query` | Método de reindexación, escrito por `api_device_dq_add()` | 1986 / 1987 |
| `host_snmp_cache` | Índices de la última reindexación, comprobados antes de crear un gráfico | 1959 / 1960 |
| `graph_local` | Encuentra un gráfico existente para un túnel y plantilla | 1601 / 1602 |
| `graph_templates_item`, `data_template_rrd` | Encuentran la fuente de datos de un gráfico existente | 1778 / 1779, 1557 / 1558 |
| `data_source_profiles` | Perfil predeterminado necesario para importar | 1304 / 1305 |
| `settings` | `cloudflare_templates_sha256` y `cloudflare_missing_host_<account id>`, mediante `set_config_option()` | 2411 / 2412 |
| `host_template`, `snmp_query`, `data_template`, `graph_templates`, `snmp_query_graph` | Resuelven los objetos importados por hash | 2003 / 2004, 2471 / 2471, 1510 / 1511, 1656 / 1657, 2491 / 2491 |

Las diferencias de línea justifican indicar ambas versiones. Todas las funciones existen en ambas. Lo que cambia es la comprobación de ruta de consulta de datos, las comprobaciones de ruta base del servidor de scripts y las comillas del índice en `get`.

<a id="the-1231-resource-path-rule"></a>
### Regla de rutas de recursos de 1.2.31

Desde 1.2.31, `get_data_query_array()` rechaza un XML de consulta que, mediante `realpath()`, no resuelva a una ubicación dentro de `<path_cacti>/resource` (`lib/data_query.php:612-620`, `lib/functions.php:8058`). 1.2.25 lee cualquier fichero existente. El complemento instala el XML en `<base_path>/resource/script_server/cloudflare_tunnels.xml` en ambas versiones, de modo que una plantilla XML sirve para ambas. Un enlace simbólico desde `resource/` al directorio del complemento no supera la comprobación de 1.2.31; por eso se copia el fichero. La ruta del script del sondeador permanece en el directorio del complemento, que el servidor de scripts de 1.2.31 acepta por estar bajo `base_path`.

<a id="the-smoke-on-1225"></a>
### Prueba smoke en 1.2.25

`make smoke CACTI_REF=release/1.2.25` construye la misma imagen PHP 8.0 desde la etiqueta 1.2.25, con nombre `cf-smoke-cacti:1.2.25`, y ejecuta todos los pasos, incluida creación de dispositivos y gráficos y comprobación RRD. Hay 22 pasos (`tests/docker/smoke.sh`). El 2026-10-22 los 22 pasaron en 1.2.31 y 1.2.25 con MariaDB 10.11.10. CI solo extrae 1.2.31, por lo que el resultado de 1.2.25 cubre el código de esa fecha y no se repite para cambios posteriores.

Los pasos de dispositivos y gráficos (5f a 5j) crean el dispositivo por HTTP, ejecutan un sondeo pendiente que reindexa y crea 9 gráficos para los tres túneles de prueba, y comprueban en el RRD de blog `connectors` 1, `connections` 1 y `health` 3. Con un 503 de Cloudflare comprueban que `connectors`, `connections` y `health` son `U`, y que regresan y siguen existiendo 9 gráficos al responder Cloudflare de nuevo. El paso 6 comprueba que desinstalar deja los 9 gráficos. En 1.2.25, `cli/plugin_manage.php` solo activa junto con `--install`; para un complemento instalado, la prueba llama a `api_plugin_enable()`, como `plugins.php`.

No se llaman ayudantes añadidos después de 1.2.25, como `cacti_redact_sensitive()` y `cacti_plugin_path()`. El complemento incluye su propia ocultación de datos sensibles.

<a id="known-issue-poller-output-warnings-on-some-mariadb-releases"></a>
### Problema conocido: advertencias de salida del sondeador en algunas versiones de MariaDB

En MariaDB 10.11.19, 11.4.13 y 11.8.9, el `DELETE` de Cacti sobre la tabla MEMORY `poller_output`, tras el `SELECT` con unión en `process_poller_output()` (`lib/poller.php:539` en 1.2.31), deja filas: en una reproducción eliminó 2 de 3. MariaDB 10.6.28, 10.11.10 y MySQL 8.4 eliminaron las 3. En las versiones afectadas, Cacti registra «There are N Data Sources not returning all data» (`lib/poller.php:857`) y «Poller Output Table not Empty» (`poller.php:608`, que también llama a `admin_email()`) en cada sondeo para cualquier fuente con más de un elemento de sondeo, incluidas las propias de Cacti. Los valores RRD no se ven afectados: la actualización se construye antes del `DELETE` y Cacti elimina las filas restantes en la siguiente pasada. Por ello la prueba de instalación fija MariaDB 10.11.10 y falla ante cualquiera de las advertencias.

<a id="php"></a>
## PHP

`composer.json` establece `config.platform.php = 7.4.33` para resolver las herramientas de desarrollo para PHP 7.4. PHPStan utiliza `phpVersion: 70400`. No hay dependencias Composer en ejecución; `includes/autoload.php` carga `src/` mediante un cargador PSR-4 incluido cuando falta `vendor/`, como ocurre en el archivo de publicación.

`#[\SensitiveParameter]` marca los parámetros de token. PHP 8.2 y posteriores los ocultan de las trazas de pila. PHP 8.0 y 8.1 ignoran el atributo desconocido y PHP 7.4 interpreta la línea como comentario. Véase [security.md](security.md#known-limitations).

<a id="database"></a>
## Base de datos

El esquema utiliza InnoDB, utf8mb4, una clave externa con `ON DELETE CASCADE`, `GET_LOCK()` para migraciones y marcas de tiempo epoch `INT UNSIGNED` ([schema.md](../../../schema.md)). Las pruebas usan el `sql_mode` de sesión de Cacti, que elimina los modos estrictos.

MariaDB y MySQL notifican un fallo de forma distinta. Eliminar una tabla referenciada por otra falla con SQLSTATE `23000` y error 1451 en MariaDB, y con SQLSTATE `HY000` y error 3730 en MySQL (observado en 8.0.46 y 8.4.11). `MigratorTest` acepta ambos.

<a id="cloudflare-api"></a>
## API de Cloudflare

El complemento solo hace solicitudes `GET` de lectura a `https://api.cloudflare.com`. Verify realiza las cuatro primeras desde la interfaz web cuando lo ejecuta un administrador. El recolector realiza las dos últimas.

| Solicitud | Finalidad | Referencia |
|---|---|---|
| `GET /client/v4/accounts/{account_id}/tokens/verify` | Comprobar el estado del token | [Verificación de token](../../../research/cloudflare-api-facts.md#1-token-verification) |
| `GET /client/v4/user/tokens/verify` | Alternativa si el endpoint de cuenta responde con error de autenticación o permisos, como ocurre con tokens de usuario | [Verificación real](../../../research/cloudflare-api-facts.md#live-verification-2026-10-21) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?per_page=1` | Probar el permiso de lectura de túneles | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/zones?account.id={account_id}&per_page=5` | Probar el permiso de lectura de zonas | [Permisos de tokens](../../../research/cloudflare-api-facts.md#3-token-permissions) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?is_deleted=false&per_page=1000&page=N` | Enumerar los túneles de la cuenta, hasta 50 páginas | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections` | Enumerar conectores y conexiones de un túnel | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |

Los permisos necesarios están en [token-permissions.md](token-permissions.md).

La gestión de errores sigue las observaciones reales del fichero de investigación: HTTP 400 con código 6003 o 6111 indica un token mal formado y se clasifica como fallo de autenticación. Las solicitudes web, como Verify, se reintentan como máximo una vez. Un 429 se reintenta tras `retry-after` si es de 2 segundos o menos; tras la espera normal de 1 segundo si falta la cabecera; y no se reintenta si la demora es mayor. El recolector reintenta como máximo dos veces y espera un `retry-after` de hasta 30 segundos; una demora mayor hace fallar la solicitud, y la tarea se ejecuta de nuevo tras esa demora, pero nunca antes de 60 segundos. Cloudflare responde a una solicitud de conexiones de un túnel desconocido con HTTP 401 y código 1001, que el cliente clasifica como `not_found`, no como fallo de autenticación.
