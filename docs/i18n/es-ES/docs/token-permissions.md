<!-- Source: docs/token-permissions.md; SHA256: a6b754c0fe728f30e24edbf5da2236c10a28452cddb4ae8eb64c7791436cb574 -->

<a id="api-token-permissions"></a>
# Permisos del token de API

El complemento solo acepta tokens de API con ámbito limitado. Rechaza las claves de API globales y nunca solicita el correo electrónico de la cuenta. Todas sus solicitudes son de lectura; no necesita ningún permiso de escritura. Los nombres siguientes son las etiquetas de la documentación de Cloudflare, recogidas en [research/cloudflare-api-facts.md](../../../research/cloudflare-api-facts.md#3-token-permissions).

<a id="milestones-1-and-2"></a>
## Hitos 1 y 2

| Permiso | Ámbito | Obligatorio | Uso |
|---|---|---|---|
| `Cloudflare Tunnel Read` | Cuenta | Sí, o uno de los equivalentes siguientes | La prueba de túneles de Verify y el descubrimiento de túneles |
| `Zone Read` | Zona | No | La prueba de zonas de Verify. Todavía no se utiliza para nada más |

Cloudflare acepta cualquiera de estos permisos para los endpoints de túneles; los permisos Write correspondientes también sirven:

- `Cloudflare Tunnel Read`
- `Cloudflare One Connectors Read`
- `Cloudflare One Connector: cloudflared Read`

La referencia de la API de Cloudflare denomina el permiso de zonas `Zone Zone Read`; la página de permisos lo denomina `Zone Read`. Ambos nombres corresponden al mismo permiso.

<a id="what-discovery-calls"></a>
### Solicitudes del descubrimiento

El recolector realiza dos solicitudes, ambas `GET`, y ambas requieren el permiso de lectura de túneles:

- `/accounts/{account_id}/cfd_tunnel`, para enumerar los túneles de la cuenta
- `/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections`, para leer los conectores de un túnel

El descubrimiento no necesita más permisos que el de lectura de túneles que Verify ya comprueba. Si Cloudflare rechaza la enumeración durante una ejecución, esta falla con `auth` o `permission`; tres ejecuciones fallidas consecutivas marcan los túneles activos de la cuenta como `unavailable` hasta que una enumeración vuelva a completarse correctamente.

La verificación del token (`/tokens/verify`) no necesita permisos. Funcionan tanto los tokens de cuenta como los de usuario. Un token de usuario recibe HTTP 401 del endpoint de verificación de cuenta y el complemento vuelve a intentarlo contra el endpoint de usuario.

<a id="what-verify-reports"></a>
### Resultados de Verify

Verify guarda el resultado en la cuenta y lo muestra en la página de edición, en Connection Status:

- **ok**: el token está activo y la prueba de túneles se ha completado correctamente.
- **permission_denied**: el token está activo, pero se ha rechazado la prueba de túneles, o ambos endpoints de verificación han respondido HTTP 403. Añada uno de los permisos de túneles anteriores.
- **Permission: tunnel_read** y **Permission: zone_read**: indican si se autorizó cada prueba en la última ejecución de Verify que alcanzó ambas. Al cambiar el ID de cuenta, el token, su almacenamiento o el fichero del token, se borran estos resultados.

La ausencia del permiso de zonas no hace fallar Verify.

<a id="milestone-4-analytics-planned"></a>
## Hito 4: analítica (previsto)

La recopilación de analítica no está implementada. Se guarda el ajuste «Collect Analytics», pero todavía no lo lee ningún componente. Se prevé que, cuando esté disponible, necesite:

| Permiso | Ámbito |
|---|---|
| `Account Analytics Read` | Cuenta |
| `Analytics Read` | Zona |

La guía de GraphQL de Cloudflare configura el token con Account Analytics Read, y la página de permisos incluye Analytics Read entre los permisos de zona. No se ha verificado cuál de los dos necesita estrictamente una consulta `httpRequestsAdaptiveGroups` a nivel de zona.

<a id="creating-the-token"></a>
## Creación del token

Cree un token de API personalizado en el panel de Cloudflare, de usuario o de cuenta. Asígnele Account > Cloudflare Tunnel > Read y, opcionalmente, Zone > Zone > Read. Limite los recursos de cuenta a la cuenta que vaya a supervisar y, si ha añadido Zone Read, los recursos de zona a las zonas de esa cuenta. Guarde el token como se describe en el README.
