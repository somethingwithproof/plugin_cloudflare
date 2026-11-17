<!-- Source: docs/metrics.md; SHA256: 62af26b17013d9977cbe2898d8822142f0a8e0768d6b35af3193c4e8935dd773 -->

<a id="metrics"></a>
# Métricas

El complemento representa el estado de los túneles que el descubrimiento ya almacena. Ninguna operación de la ruta del sondeador llama a Cloudflare: `ObservationReader` (`src/Metrics`) lee las tablas del complemento y convierte cada túnel en una observación por métrica. Una observación tiene un valor, o un valor nulo y un motivo. Los valores nulos se imprimen como `U`.

<a id="catalog"></a>
## Catálogo

`Catalog::all()` es la lista única de la que proceden las plantillas, los nombres de fuentes de datos y los campos del servidor de scripts. Todas las métricas son de tipo `GAUGE`, con mínimo 0.

| Métrica | Campo de consulta | Nombre DS | Unidad | Máx. | Tipo |
|---|---|---|---|---|---|
| `cloudflare_tunnel_health` | `health` | `health` | estado | 3 | estado |
| `cloudflare_tunnel_connectors` | `connectors` | `connectors` | conectores | U | estado |
| `cloudflare_tunnel_connections` | `connections` | `connections` | conexiones | U | estado |
| `cloudflare_tunnel_connector_versions` | `versions` | `versions` | versiones | U | estado |
| `cloudflare_collection_age` | `collectAge` | `collect_age` | segundos | U | recopilación |
| `cloudflare_collection_failures` | `collectFailures` | `collect_fail` | fallos | U | recopilación |

`versions` es el número de versiones distintas de cloudflared entre los conectores actuales del túnel. `collectAge` es el tiempo transcurrido desde la última lectura correcta de las conexiones del túnel, y `collectFailures` es el número de fallos consecutivos de la tarea de descubrimiento.

<a id="health"></a>
## Salud

| Estado de Cloudflare | Valor |
|---|---|
| `healthy` | 3 |
| `degraded` | 2 |
| `down` | 1 |
| `inactive` | 0 |

Cualquier estado ajeno a esta lista es desconocido, con motivo `malformed`.

<a id="when-a-value-is-printed"></a>
## Cuándo se imprime un valor

Un valor ausente nunca debe registrarse como cero. Un cero en un RRD parece una interrupción real y posteriormente no puede distinguirse de ella, por lo que todo aquello que el lector no pueda garantizar se representa como `U`.

```
window = 2 * discovery_interval + 60
age    = now - observed_at
```

`now` es el reloj del sondeador. `observed_at` es `last_seen_at` para la salud y `connections_refreshed_at` para las métricas de conexión (`connectors`, `connections`, `versions`). Una métrica de estado solo tiene valor si se cumplen todas estas condiciones:

- `last_status` de la tarea de descubrimiento de la cuenta es `ok` o `partial`;
- `lifecycle` del túnel es `active`;
- `observed_at` está definido y `-60 <= age <= window`. Una antigüedad entre -60 y 0 se considera desfase del reloj y cuenta como 0;
- para las métricas de conexión, `connections_attempted_at <= connections_refreshed_at`, por lo que el último intento tuvo éxito.

Las comprobaciones se ejecutan en ese orden y la primera que falla determina el motivo:

1. Estado de la tarea. La ausencia de una fila de tarea o el estado `never` producen `never_collected`. Cualquier estado distinto de `ok` o `partial` produce el `last_error_class` de la tarea, o `transient` si no hay ninguno almacenado.
2. Ciclo de vida. `unavailable` o `deleted` producen `not_active`.
3. `observed_at` es nulo: `never_collected`.
4. Antigüedad inferior a -60: `clock_skew`. Antigüedad superior a la ventana: `stale`.
5. Solo para métricas de conexión: un intento posterior a la actualización produce el `connections_error_class` del túnel, o `transient` si no hay ninguno almacenado.
6. El propio valor almacenado. Un `connector_count` o `connection_count` nulo produce `never_collected`, aunque `connections_refreshed_at` esté definido. Un estado de Cloudflare ajeno a la tabla de salud produce `malformed` solo para la salud; las demás métricas no se ven afectadas. `versions` cuenta las filas de conectores que el descubrimiento almacena junto con `connector_count`, por lo que un `connector_count` nulo también hace que `versions` sea desconocido, con `never_collected`.

El fallo de la tarea se comprueba primero porque explica lo demás. El descubrimiento marca los túneles como `unavailable` tras interrupciones repetidas, por lo que la clase de error de la tarea resulta más útil.

Con el intervalo predeterminado de 300 segundos, la ventana es de 660 segundos. Un túnel cuya tarea sigue indicando `ok` pero que lleva 661 segundos sin actualizarse imprime `U` con motivo `stale`.

<a id="collection-metrics"></a>
### Métricas de recopilación

`collectAge` y `collectFailures` describen al propio recolector, por lo que siguen imprimiéndose durante una interrupción. Ignoran el estado de la tarea, el ciclo de vida `unavailable` y la ventana. Son desconocidas cuando:

- el túnel es `deleted` (`not_active`);
- nunca se han actualizado las conexiones del túnel o la cuenta no tiene fila de tarea (`never_collected`);
- para `collectAge`, la hora de actualización está más de 60 segundos en el futuro (`clock_skew`).

Un `consecutive_failures` nulo hace que `collectFailures` sea desconocido con motivo `never_collected`. Su `observed_at` es la hora de lectura.

<a id="reasons"></a>
## Motivos

| Motivo | Significado |
|---|---|
| un valor de `ErrorClass` | La clase almacenada del fallo de la tarea o de la lectura de conexiones |
| `stale` | Más antiguo que la ventana |
| `clock_skew` | Más de 60 segundos en el futuro |
| `not_active` | El túnel es `unavailable` o `deleted` |
| `never_collected` | Todavía no se ha almacenado ninguna lectura correcta |
| `unknown_tunnel` | La cuenta no tiene un túnel con este ID exacto |

Los valores de `ErrorClass` son `auth`, `permission`, `not_found`, `rate_limited`, `transient`, `malformed` y `config`.

El ID de túnel debe coincidir exactamente. La intercalación de la columna no distingue mayúsculas y minúsculas, por lo que el lector rechaza antes de consultar cualquier valor que no sea un UUID en minúsculas.

<a id="sampling"></a>
## Muestreo

Cacti toma una muestra de la última observación en cada sondeo. El descubrimiento se ejecuta una vez por intervalo, por lo que una métrica de tipo gauge repite el mismo valor entre descubrimientos. La marca de tiempo del RRD es la hora del sondeo, que puede ser hasta una ventana completa, `2 * discovery_interval + 60` segundos, posterior a `observed_at`.

<a id="schema-version"></a>
## Versión del esquema

Las observaciones incluyen `Version::METRIC_SCHEMA`, cuyo valor es 1. La versión 1 solo incluye métricas gauge, por lo que no puede haber reinicios de contadores. El campo `type` sigue diferenciando `GAUGE` de `COUNTER` para versiones futuras. Cambiar el nombre de una métrica o de una DS, o cambiar un tipo o límite, requiere una nueva versión del esquema porque las plantillas y los ficheros RRD existentes dependen de ellos.
