<!-- Source: docs/security.md; SHA256: 686c0b06d983411f2dfeb405c08f6eea0b8a6f983bb25760d3de0dcf05f96d83 -->

<a id="security"></a>
# Seguridad

Esta página describe los controles de seguridad implementados hasta el hito 3 y el código que aplica cada uno. Para comunicar una vulnerabilidad, consulte [SECURITY.md](../../../../SECURITY.md).

<a id="outbound-requests"></a>
## Solicitudes salientes

`src/Http/CurlTransport.php` es el único código que abre una conexión de red. Antes de llamar a `curl_init()`, `OutboundPolicy::assertAllowed()` comprueba la URL frente a una lista de orígenes permitidos. La lista de producción, que `OutboundPolicy::production()` construye a partir de una constante, contiene únicamente `https://api.cloudflare.com` en el puerto 443. Ninguna opción permite modificarla.

La política compara el esquema, el host y el puerto, y rechaza cualquier URL que `parse_url()` de PHP y el analizador de curl puedan interpretar de forma distinta: información de usuario, `@`, barras inversas, fragmentos, espacios en blanco, caracteres de control, bytes no ASCII y puertos que no estén escritos solo con dígitos. Una URL rechazada no provoca resolución de nombres ni abre un socket, y no se copia a la excepción.

`CloudflareClient` valida los componentes de la URL antes de aplicar la política. El identificador de cuenta debe ajustarse a `^[0-9a-f]{32}$`; la ruta de la solicitud debe ajustarse a `^/[A-Za-z0-9/_.-]*$` y no puede contener `..`.

Cada solicitud establece:

- `CURLOPT_PROTOCOLS` exclusivamente en HTTPS.
- `CURLOPT_FOLLOWLOCATION` desactivado y `CURLOPT_MAXREDIRS` en 0.
- `CURLOPT_SSL_VERIFYPEER` activado y `CURLOPT_SSL_VERIFYHOST` en 2.
- Un tiempo máximo de conexión de 5 segundos; el tiempo máximo total procede de «Interactive Request Timeout» para las solicitudes de la interfaz web (10 segundos de forma predeterminada) o de «Collector Request Timeout» para la recopilación en segundo plano (20 segundos de forma predeterminada). Ambas opciones admiten de 5 a 60 segundos.
- `CURLOPT_NOPROXY` en `*`, para ignorar las variables de entorno del proxy.
- Un límite de respuesta de 5 MiB; un cuerpo mayor interrumpe la transferencia.

`tests/Unit/Http/CurlTransportOptionsTest.php` comprueba estas opciones. `tests/Integration/Http/CurlTransportTest.php` ejecuta el transporte real contra servidores locales y verifica que no sigue redirecciones, rechaza certificados autofirmados y cuerpos demasiado grandes, ignora un proxy definido en el entorno y nunca conecta con una URL fuera de la lista permitida.

<a id="api-tokens"></a>
## Tokens de API

Solo se aceptan tokens de API con permisos delimitados. `TokenPolicy::validate()` rechaza como Global API Key una cadena hexadecimal en minúsculas de 37 caracteres, con un mensaje específico; para las demás exige `^[A-Za-z0-9_-]{40,256}$`. La política se aplica al guardar un token y cada vez que se carga.

Cada cuenta conserva su token en una de estas dos ubicaciones.

<a id="token-file"></a>
### Archivo de token

La cuenta almacena la ruta absoluta de un archivo que contiene únicamente el token. `TokenFile::read()` rechaza el archivo salvo que:

- La ruta sea absoluta y no sea un enlace simbólico.
- Sea un archivo normal de un máximo de 4096 bytes, comprobado mediante `fstat()` sobre el descriptor abierto para verificar el archivo que realmente se lee.
- Otros usuarios no puedan leerlo ni escribirlo (`mode & 0006` es cero).

Estas comprobaciones se realizan al leer el token, durante Verify, no al guardar la cuenta. Los usuarios del servidor web y del sondeador pueden ser distintos; una comprobación al guardar desde el servidor web podría aprobar o rechazar el archivo para el usuario equivocado. Si falla alguna comprobación, Verify registra «Token file is missing or insecure».

La ruta es una decisión de confianza de quien tenga el permiso Cloudflare: Configure. Verify y el recolector abren esa ruta como el usuario del servidor web y el usuario del sondeador, respectivamente, y este último a veces es root. Por tanto, un usuario con Configure puede indicar cualquier archivo que esos usuarios puedan leer. Si el archivo supera las comprobaciones anteriores y su contenido, sin espacios en los extremos, coincide con el patrón del token, el complemento envía ese contenido como token Bearer, únicamente a `https://api.cloudflare.com` mediante TLS verificado, porque la lista de destinos no se puede modificar. El contenido nunca llega al navegador ni al registro. Verify sí permite distinguir si el archivo era legible y tenía forma de token («Token file is missing or insecure» frente a una respuesta de Cloudflare), y la línea del registro incluye el código del motivo; por ello, un usuario con Configure puede saber si existe tal archivo. Conceda Configure únicamente a usuarios a quienes confíe los archivos que puede leer el usuario del sondeador.

Para limitar este acceso, establezca Settings > Cloudflare > Token File Directory (vacío de forma predeterminada, lo que permite cualquier ruta). `TokenFile::read()` rechazará entonces, con el motivo `file_outside_directory` y antes de cualquier otra comprobación del sistema de archivos, una ruta cuyo directorio padre no se resuelva dentro de ese directorio, una ruta con segmentos `.` o `..`, o cualquier ruta si el propio directorio configurado no se puede resolver. El directorio padre se resuelve con `realpath()`, por lo que un enlace simbólico a un directorio no permite salir del límite. Esta opción está en la página Settings de Cacti y requiere tanto Settings/Utilities como Cloudflare: Configure; por ello, limita lo que puede alcanzar un usuario con Configure que no tenga Settings/Utilities. Quien pueda escribir dentro del directorio o sustituir uno de sus directorios padres aún puede cambiar lo que se lee.

<a id="encrypted-in-the-cacti-database-vault"></a>
### Cifrado en la base de datos de Cacti (bóveda)

`src/Secret/TokenVault.php` cifra el token con AES-256-GCM mediante `openssl_encrypt()`, usando un nonce aleatorio de 12 bytes y una etiqueta de 16 bytes. Los datos autenticados adicionales son `plugin_cloudflare:account:<account ID>`; por ello, un texto cifrado copiado a la fila de otra cuenta no se puede descifrar. El valor almacenado es `v1:` seguido de la codificación base64 del nonce, la etiqueta y el texto cifrado. Cambiar el identificador de una cuenta exige introducir el token de nuevo.

La clave de 32 bytes se lee del archivo indicado en Settings > Cloudflare > Token Vault Key File (de forma predeterminada, `/etc/cacti/cloudflare.key`). El complemento nunca crea ni escribe este archivo. `TokenVault::fromKeyFile()` solo lo acepta cuando:

- La ruta es absoluta y no es un enlace simbólico.
- Es un archivo normal que otros usuarios no pueden leer ni escribir.
- Contiene exactamente 32 bytes sin codificar, o 64 caracteres hexadecimales con, como máximo, un salto de línea final; se rechazan 32 caracteres hexadecimales, que equivaldrían a una clave de 16 bytes.

Cuando el archivo no existe o se rechaza, la bóveda queda desactivada y el formulario de edición solo ofrece el archivo de token como origen. Si el archivo existe pero se rechaza, se registra una vez por solicitud únicamente el código del motivo. La clave se conserva fuera de las propiedades del objeto, de modo que `var_export()`, las conversiones a `(array)` y `get_object_vars()` no pueden exponerla. El objeto rechaza la serialización y `var_dump()` muestra `[REDACTED]` mediante `__debugInfo()`.

Un volcado de la base de datos por sí solo no revela los tokens de la bóveda. Un atacante que pueda leer tanto la base de datos como el archivo de clave, por ejemplo como usuario del servidor web, sí puede descifrarlos.

<a id="handling-in-the-interface"></a>
### Tratamiento en la interfaz

El formulario de edición nunca devuelve al navegador un token almacenado. Los campos del token siempre están vacíos; dejarlos vacíos al guardar conserva el token actual. La lista solo muestra si hay un token almacenado. La página de edición obtiene el origen y la ruta del archivo mediante `AccountService::credentialDetails()`, que devuelve esos datos y si existe un token, nunca el token ni el texto cifrado, y exige el permiso de configuración. Si un formulario no supera la validación, se vuelve a mostrar conservando los demás campos, pero el token nunca se escribe en la sesión.

<a id="redaction-and-logging"></a>
## Ocultación y registros

Los mensajes de estado de cuenta son frases fijas, como «Cloudflare API is unreachable; try again later». Nunca se copia en ellos texto procedente de las respuestas de error de Cloudflare. Los cambios de estado se anotan en el registro de Cacti con el identificador de fila de la cuenta, el identificador de cuenta de Cloudflare, el estado anterior y el nuevo, y una clase de error o código de motivo; nunca con el token.

`Support\Redactor` sustituye los secretos conocidos, empezando por los más largos, y cualquier valor `Authorization: Bearer` por `[REDACTED]`. El cliente lo aplica a los mensajes de error de Cloudflare y al texto de error de curl antes de incorporarlos a una excepción. También elimina los caracteres de control del texto de curl y lo limita a 200 caracteres.

Las pruebas que manejan un token comprueban que no aparece en registros, mensajes de excepción ni salidas. La prueba de instalación busca el token utilizado en los registros de Cacti, los de Apache, todas las respuestas HTTP guardadas y un volcado completo de la base de datos.

<a id="database-errors"></a>
## Errores de base de datos

Todas las consultas son sentencias preparadas con valores vinculados; los identificadores nunca se construyen a partir de la entrada. Una sentencia fallida lanza `DatabaseException`, cuyo mensaje es exactamente `SQLSTATE[<state>] driver error <code>`. Se descarta el texto del controlador porque MySQL reproduce en él el texto de la sentencia y, con preparaciones emuladas, este incluye los valores vinculados. La página registra ese mensaje y muestra un error fijo: el error genérico de guardado de Cacti al guardar y en acciones masivas, y «The Cloudflare accounts could not be read» en las páginas de lista, edición y confirmación. Ninguna ruta de lectura deja escapar la excepción, porque el manejador de cierre de Cacti desactiva un complemento cuya página termina por una excepción sin controlar.

<a id="access-control"></a>
## Control de acceso

| Permiso | Página | Valor predeterminado |
|---|---|---|
| Cloudflare: Configure | `cloudflare_accounts.php` | Concedido al administrador al instalar |
| Cloudflare: View | `cloudflare.php` | No se concede al instalar |
| Cloudflare: Operator | `cloudflare_operator.php` | Requiere concesión explícita |
| Sites/Devices/Data (permiso de Cacti para `host.php`) | Create Cacti Device y activar Create Graphs | Concesión propia de Cacti |

Configure hereda el acceso de Operator y View, y Operator hereda View, utilizando los permisos actuales del usuario y de sus grupos en cada solicitud. No se modifican los permisos almacenados. Operations tiene la misma visibilidad de todas las cuentas que View; no añade filtrado por dispositivo. Los acuses de recibo requieren POST, un token CSRF válido y una observación reciente que el servidor vuelva a cargar y cuya huella coincida. Son personales para la sesión actual, duran como máximo una hora, caducan cuando cambia la observación y nunca modifican el estado de salud ni suprimen alertas.

`include/auth.php` exige el permiso según el nombre de la página. `AccountService` vuelve a comprobarlo antes de cada modificación, cada Verify y cada consulta de credenciales mediante `api_user_realm_auth('cloudflare_accounts.php')`; en caso contrario lanza `AccessDenied`. La prueba de instalación comprueba que a un usuario que solo tiene Cloudflare: View se le deniega la página de cuentas. Las pruebas BDD verifican que un usuario sin el permiso de configuración no puede modificar una cuenta ni llamar a `credentialDetails()`. Las páginas de visualización (`cloudflare.php`) solo leen. Requieren Cloudflare: View, que Cacti exige según el nombre de la página, y no aceptan acciones POST. El texto de túneles y conectores procede de Cloudflare, por lo que cada valor se muestra mediante `html_escape()`. Los filtros y criterios de ordenación de Tunnels se comparan con listas fijas antes de llegar a una consulta.

Las acciones sobre dispositivos y gráficos necesitan un segundo permiso. Un dispositivo Cacti es un objeto de `host.php`, por lo que Configure por sí solo no debe permitir crearlo. Tanto Create Cacti Device como activar Create Graphs exigen `cloudflare_accounts.php` y `api_user_realm_auth('host.php')` (`includes/cacti_objects.php:34`). El desplegable oculta la acción a quien no tenga ambos (`includes/accounts_page.php:55-62`); el manejador vuelve a rechazar la solicitud antes de cualquier llamada a Cacti (`:234-243`), y `cloudflare_create_device()` lo comprueba por tercera vez (`includes/cacti_objects.php:47`). Si un usuario sin el permiso `host.php` guarda el formulario de cuenta con Create Graphs activado, se descarta ese campo y se conserva el valor almacenado; aparece una advertencia si la opción estaba desactivada (`includes/accounts_page.php:193-209`). Desactivarla solo exige Configure. El recolector crea gráficos sin un usuario conectado, por lo que la comprobación del permiso se realiza al guardar la opción.

`list()` permanece sin comprobación de permisos porque el modelo de lectura `Account` que devuelve no contiene token, texto cifrado, origen del token ni ruta del archivo; una prueba de integración comprueba que un volcado de la lista no incluye ninguno de esos datos.

Guardar y las acciones masivas (Delete, Verify, Enable, Disable) solo aceptan POST; cualquier otro método redirige a la lista. Cacti comprueba los cuerpos POST mediante csrf-magic. Las acciones masivas leen los identificadores seleccionados con `sanitize_unserialize_selected_items()`. Los valores numéricos se leen con `get_filter_request_var()` y `AccountService` valida los campos de texto antes de almacenar nada. Todos los valores que se imprimen en la página pasan por `html_escape()` o `__esc()`. El script de la página es un archivo estático, `js/cloudflare_accounts.js`, cargado con el nonce CSP de Cacti cuando este lo proporciona; el complemento no añade scripts en línea.

<a id="collector"></a>
## Recolector

El descubrimiento de túneles se ejecuta en un proceso PHP independiente, `poller_cloudflare.php`.

- **Solo CLI.** El script responde con 404 y termina cuando `php_sapi_name()` no es `cli`, por lo que no puede ejecutarse mediante HTTP.
- **Inicio.** `cloudflare_poller_bottom()` lo inicia mediante `exec_background()` desde el hook `poller_bottom`, únicamente en el sondeador principal. El binario PHP es el definido por `path_php_binary` en Cacti y la ruta del script está escapada; ningún dato de la solicitud llega a la línea de comandos.
- **Un proceso cada vez.** El recolector obtiene `GET_LOCK('plugin_cloudflare_collector', 0)`. Con un tiempo de espera de 0, otro recolector termina de inmediato, y MySQL libera el bloqueo al terminar la conexión. Cada fila de trabajo también conserva una concesión temporal, y cada escritura para una cuenta comparte una transacción con la comprobación de esa concesión.
- **Ejecución limitada.** El recolector no inicia nuevas cuentas después de 50 segundos y realiza como máximo 200 solicitudes de conexiones por ejecución. Cualquiera de los límites también puede interrumpir una cuenta durante la lectura de sus conexiones, registrándola como `partial`. Cada solicitud tiene un tiempo máximo de conexión de 5 segundos y el Collector Request Timeout (20 segundos de forma predeterminada), por lo que una API lenta de Cloudflare termina la ejecución en la siguiente comprobación sin bloquear el ciclo del sondeador.
- **Comprobación de desactivación.** Antes de cada cuenta lee directamente `plugin_config.status`. `api_plugin_is_enabled()` conserva su respuesta en caché durante el proceso y no detectaría una desactivación durante la ejecución. Una lectura fallida se considera desactivada.
- **Credenciales.** Los tokens se obtienen por cuenta del mismo archivo o bóveda que usa Verify, con las mismas comprobaciones, y se borran de memoria tras usarlos cuando está cargado sodium. Si no se puede leer un token, solo se registra un código de motivo.
- **Registros.** Un fallo fuera del cliente de Cloudflare se registra únicamente mediante el nombre de la clase de excepción, porque un mensaje o una traza pueden contener texto de Cloudflare o un token. Las líneas de ejecución incluyen el identificador numérico de fila de la cuenta, una clase de error y contadores, con el nivel medio de detalle de Cacti.

<a id="cacti-devices-graphs-and-templates"></a>
## Dispositivos, gráficos y plantillas de Cacti

- **Nombre de host.** El dispositivo de la cuenta recibe el nombre `<cf_account_id>.cloudflare.invalid` (`includes/cacti_objects.php:107`). `.invalid` está reservado y nunca se resuelve; la versión SNMP es 0 y no hay método de disponibilidad, por lo que Cacti nunca conecta con ningún destino para este dispositivo. El dispositivo no contiene ningún token ni credencial.
- **Una escritura fija fuera del directorio del complemento.** El complemento copia su archivo `templates/resource/cloudflare_tunnels.xml` a `<base_path>/resource/script_server/cloudflare_tunnels.xml`, porque Cacti 1.2.31 solo lee XML de consultas de datos desde `resource/` (`includes/templates.php:46-52`). Ni el origen ni el destino proceden de la entrada. La función escribe un archivo temporal junto al destino con `fopen()` en modo `xb`, que falla si el nombre ya existe; así nunca sigue un enlace colocado en ese nombre temporal. Establece el modo 0644 y renombra el archivo sobre el destino (`includes/templates.php:140-178`). Si el destino es un enlace simbólico, se sustituye, porque `rename()` cambia el propio enlace y no escribe a través de él. La copia solo se omite cuando el destino es un archivo normal con contenido idéntico (`:151`). Un fallo se registra y devuelve false; nunca lanza una excepción, porque Cacti desactiva un complemento cuya instalación la lance. No se escribe nada más fuera del directorio del complemento y la desinstalación conserva este archivo.
- **Dispositivo creado parcialmente.** Si `api_device_save()` tiene éxito pero el complemento no puede registrar el dispositivo, elimina ese host con `api_device_remove()` y vuelve a leer la tabla de hosts. El identificador es el que acaba de recibir esa misma solicitud, nunca un valor de la petición ni de la base de datos. Si el host aún existe, el registro lo indica por su identificador y el administrador lo elimina en Devices (`includes/cacti_objects.php:146-158`, `:172-188`).
- **Bloqueos.** `GET_LOCK('plugin_cloudflare_device_<account id>')` hace que un segundo Create Cacti Device para la misma cuenta espere y después encuentre el dispositivo, para que una cuenta nunca reciba dos. El bloqueo se libera en un bloque `finally` y un fallo al liberarlo se registra sin sustituir el resultado (`includes/cacti_objects.php:65-82`). `GET_LOCK('plugin_cloudflare_templates')` impide que dos procesos de actualización importen las plantillas simultáneamente (`includes/templates.php:79-94`). MySQL elimina ambos al terminar la conexión.
- **Importación de plantillas.** `import_xml_data()` comunica fallos mediante valores de retorno y `$import_messages`, no con excepciones; por eso el complemento comprueba ambos y después verifica que cada objeto se resuelve por su hash (`includes/templates.php:188-251`). Si una importación falla, solo se registra el nombre de la fase.
- **Servidor de scripts.** `ss_cloudflare.php` lee las tablas del complemento y no realiza llamadas de red. Solo acepta un identificador de dispositivo que coincida con `^[1-9][0-9]{0,9}\z` y un índice de túnel que sea un UUID en minúsculas; vincula ambos en sentencias preparadas y registra los fallos únicamente mediante el nombre de la clase de excepción (`includes/script_server.php:38-84`).

<a id="disabled-plugin"></a>
## Complemento desactivado

Cacti mantiene cargados los permisos de un complemento desactivado y deja activo su hook `config_arrays`. Por ello, el complemento comprueba por sí mismo `api_plugin_is_enabled()`. Mientras está desactivado, se ocultan las entradas de menú y las rutas de navegación, y `cloudflare_accounts.php`, `cloudflare_operator.php` y `cloudflare.php` responden con la página de permiso denegado de Cacti. El recolector consulta directamente `plugin_config.status` y termina sin trabajar. Settings > Cloudflare sigue visible para poder establecer la ruta de la clave antes de activar el complemento. La prueba de instalación comprueba tanto la denegación como la recuperación tras volver a activarlo.

<a id="test-stack"></a>
## Entorno de pruebas

La prueba de instalación ejecuta Cacti contra una API falsa de Cloudflare. Esta responde como `api.cloudflare.com` dentro de la red Docker del entorno, con un certificado firmado por una CA que se crea al iniciar su contenedor. Esa CA es válida durante dos días y está limitada mediante una restricción de nombre a `api.cloudflare.com`. Solo el contenedor web de Cacti la instala en su almacén de certificados del sistema. La prueba comprueba que el contenedor de pasarela no confía en ella y que se rechaza cualquier otro nombre.

El complemento no tiene una opción de CA ni una sustitución del origen. `CurlTransport` no establece opciones de CA, por lo que en producción se utiliza el almacén del sistema operativo, y `OutboundPolicy::production()` permite únicamente `https://api.cloudflare.com`. La CA de pruebas solo existe en ese entorno.

<a id="uninstall"></a>
## Desinstalación

La desinstalación elimina las tablas del complemento, incluidos los textos cifrados almacenados, y borra las opciones `cloudflare_`. No modifica el archivo de clave ni los archivos de token, ni elimina dispositivos, gráficos, fuentes de datos, archivos RRD, plantillas de Cacti o `resource/script_server/cloudflare_tunnels.xml`.

<a id="known-limitations"></a>
## Limitaciones conocidas

- `#[\SensitiveParameter]` oculta los argumentos de token en las trazas solo en PHP 8.2 y posteriores. En PHP 7.4 a 8.1 no tiene efecto, por lo que una traza impresa por PHP podría incluir un argumento de token.
- Los tokens y las claves se borran mediante `sodium_memzero()` solo cuando está cargada la extensión sodium, y PHP puede haber copiado ya la cadena. Considérelo una medida de mejor esfuerzo.
- Las solicitudes salientes ignoran la configuración de proxy HTTP. Un servidor Cacti que solo pueda acceder a Internet mediante un proxy no podrá llegar a la API de Cloudflare.
- No existe una herramienta de rotación de claves. Una clave nueva hace que los tokens existentes de la bóveda fallen con «Stored token cannot be decrypted» hasta que se introduzca cada token de nuevo; el README describe el procedimiento en «Rotating the key». Un comando que vuelva a cifrar todos los textos con una clave nueva es una posible mejora futura.
- Los problemas de permisos de archivos aparecen durante Verify, no al guardar.
- `TokenFile` solo rechaza un enlace simbólico en el último componente de la ruta; no comprueba los directorios padres salvo que esté configurado Token File Directory.
- Cacti desactiva un complemento cuyo código termine con un error fatal real, como agotar la memoria, superar `max_execution_time` o sufrir un error de compilación. Ningún `catch` puede interceptarlos y el complemento no puede evitarlos; un administrador debe volver a activarlo. Las páginas capturan `Throwable` y registran el nombre de la clase, de modo que una excepción normal, como una tabla ausente, muestra un mensaje fijo y no llega al manejador de cierre de Cacti.
- Tres fallos contabilizados de la clase de interrupción sin un listado completo intermedio marcan los túneles activos de una cuenta como `unavailable`, incluso cuando la causa es un límite de tasa prolongado. Los demás fallos no incrementan ni reinician el contador de interrupciones. El siguiente listado completo lo reinicia y restablece los túneles.
- El complemento sobrescribe las modificaciones del administrador en las plantillas importadas cuando una actualización cambia el XML distribuido, y el dispositivo debe permanecer en el sondeador principal. Consulte las limitaciones conocidas del README.
- Verify se ejecuta dentro de la solicitud web. Una operación puede realizar hasta cuatro solicitudes (verificación de cuenta, verificación de usuario, comprobación de túneles y comprobación de zonas), cada una limitada por los tiempos anteriores y con un máximo de un reintento.
