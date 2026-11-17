<!-- Source: docs/security.md; SHA256: 686c0b06d983411f2dfeb405c08f6eea0b8a6f983bb25760d3de0dcf05f96d83 -->

<a id="security"></a>
# 安全性

本页说明截至里程碑 3 已实现的安全控制，以及执行各项控制的代码。如需报告漏洞，请参阅 [SECURITY.md](../../../../SECURITY.md)。

<a id="outbound-requests"></a>
## 出站请求

`src/Http/CurlTransport.php` 是唯一会建立网络连接的代码。在调用 `curl_init()` 前，`OutboundPolicy::assertAllowed()` 会根据允许的来源列表检查 URL。生产环境列表由 `OutboundPolicy::production()` 从常量构建，且仅包含端口 443 上的 `https://api.cloudflare.com`。没有任何设置可以更改此列表。

该策略比较协议、主机和端口，并拒绝可能被 PHP 的 `parse_url()` 与 curl 解析器以不同方式解释的 URL，包括用户信息、`@`、反斜杠、片段、空白字符、控制字节、非 ASCII 字节，以及不是纯数字的端口。被拒绝的 URL 不会触发名称解析或打开套接字，也不会被复制到异常中。

`CloudflareClient` 在策略检查前验证写入 URL 的内容。账户 ID 必须匹配 `^[0-9a-f]{32}$`；请求路径必须匹配 `^/[A-Za-z0-9/_.-]*$`，且不得包含 `..`。

每个请求都设置以下选项：

- `CURLOPT_PROTOCOLS` 仅允许 HTTPS。
- 关闭 `CURLOPT_FOLLOWLOCATION`，将 `CURLOPT_MAXREDIRS` 设为 0。
- 开启 `CURLOPT_SSL_VERIFYPEER`，将 `CURLOPT_SSL_VERIFYHOST` 设为 2。
- 连接超时为 5 秒；Web 界面请求的总超时由“Interactive Request Timeout”决定（默认 10 秒），后台采集请求则由“Collector Request Timeout”决定（默认 20 秒）。两项设置的允许范围均为 5 至 60 秒。
- 将 `CURLOPT_NOPROXY` 设为 `*`，忽略代理环境变量。
- 响应上限为 5 MiB；超过此大小的响应体会中止传输。

`tests/Unit/Http/CurlTransportOptionsTest.php` 对这些选项进行断言。`tests/Integration/Http/CurlTransportTest.php` 使用真实传输代码访问本地服务器，验证不会跟随重定向、会拒绝自签名证书和过大的响应体、会忽略环境中的代理设置，并且绝不会连接允许列表以外的 URL。

<a id="api-tokens"></a>
## API 令牌

插件仅接受具有明确权限范围的 API 令牌。`TokenPolicy::validate()` 会将 37 个小写十六进制字符组成的字符串识别为 Global API Key，并用专门的消息拒绝；其他输入必须匹配 `^[A-Za-z0-9_-]{40,256}$`。保存令牌时以及每次加载令牌时都会执行此策略。

每个账户将令牌保存在以下两种位置之一。

<a id="token-file"></a>
### 令牌文件

账户保存一个文件的绝对路径，该文件只包含令牌。除非满足以下条件，否则 `TokenFile::read()` 会拒绝读取：

- 路径为绝对路径，且不是符号链接。
- 文件为普通文件，大小不超过 4096 字节；对已打开的句柄使用 `fstat()` 检查，确保检查的是实际读取的文件。
- 其他用户不能读取或写入该文件，即 `mode & 0006` 为零。

这些检查在 Verify 读取令牌时执行，而不是在保存账户时执行。Web 服务器用户和轮询器用户可能不同，因此由 Web 服务器在保存时进行的检查可能针对错误的用户通过或失败。文件未通过检查时，Verify 会记录“Token file is missing or insecure”。

路径由持有 Cloudflare: Configure 权限的用户选择，这是一项信任决定。Verify 和采集器分别以 Web 服务器用户和轮询器用户的身份打开该路径，而轮询器用户有时是 root。因此，Configure 用户可以将账户指向这些用户能够读取的任意文件。如果该文件通过上述检查，且去除首尾空白后的内容符合令牌模式，插件就会将其作为 Bearer 令牌发送；由于出站允许列表无法更改，发送目标仅为通过验证的 TLS 连接上的 `https://api.cloudflare.com`。文件内容不会到达浏览器或日志。不过，Verify 会显示文件是否可读且内容是否具有令牌形式，例如“Token file is missing or insecure”与 Cloudflare 返回结果的差别；日志行也包含原因代码，因此 Configure 用户可以得知此类文件是否存在。只应向可信任其访问轮询器用户可读文件的用户授予 Configure。

要缩小访问范围，可设置 Settings > Cloudflare > Token File Directory。默认值为空，表示允许任意路径。设置后，`TokenFile::read()` 会在任何其他文件系统检查之前，以 `file_outside_directory` 为原因拒绝以下情况：父目录解析结果不在指定目录内、路径包含 `.` 或 `..` 段，或指定目录本身无法解析。父目录通过 `realpath()` 解析，因此指向目录的符号链接无法绕出边界。该设置位于 Cacti 的 Settings 页面，需要同时具备 Settings/Utilities 和 Cloudflare: Configure 权限，因此能够限制没有 Settings/Utilities 权限的 Configure 用户可访问的范围。能够在该目录内写入或替换任一父目录的用户，仍然可以改变实际读取的内容。

<a id="encrypted-in-the-cacti-database-vault"></a>
### 在 Cacti 数据库中加密保存（保险库）

`src/Secret/TokenVault.php` 通过 `openssl_encrypt()` 使用 AES-256-GCM 加密令牌，采用随机的 12 字节 nonce 和 16 字节认证标签。附加认证数据为 `plugin_cloudflare:account:<account ID>`，因此复制到另一账户行的密文无法解密。存储值由 `v1:` 前缀及 nonce、标签和密文的 base64 编码组成。更改账户 ID 时必须重新输入令牌。

32 字节密钥从 Settings > Cloudflare > Token Vault Key File 指定的文件读取，默认路径为 `/etc/cacti/cloudflare.key`。插件从不创建或写入此文件。`TokenVault::fromKeyFile()` 仅在以下条件成立时接受该文件：

- 路径为绝对路径，且不是符号链接。
- 文件为普通文件，且其他用户无法读取或写入。
- 内容恰好为 32 个原始字节，或 64 个十六进制字符且末尾最多有一个换行符；32 个十六进制字符只表示 16 字节密钥，因此会被拒绝。

文件缺失或被拒绝时，保险库会关闭，编辑表单只提供令牌文件作为来源。已存在但被拒绝的文件，每个请求只记录一次原因代码。密钥保存在对象属性之外，因此 `var_export()`、`(array)` 类型转换和 `get_object_vars()` 无法暴露它。对象拒绝序列化，`var_dump()` 则通过 `__debugInfo()` 输出 `[REDACTED]`。

单独获得数据库转储并不能获取保险库令牌。能够同时读取数据库和密钥文件的攻击者，例如拥有 Web 服务器用户权限的攻击者，可以解密令牌。

<a id="handling-in-the-interface"></a>
### 界面中的处理

编辑表单从不将已保存的令牌返回浏览器。令牌字段始终为空；保存时保持为空会保留当前令牌。列表只显示是否已保存令牌。编辑页面通过 `AccountService::credentialDetails()` 读取来源和文件路径；此方法只返回这些信息以及令牌是否存在，绝不返回令牌或密文，并要求配置权限。表单验证失败后会保留其他字段重新显示，但令牌绝不会写入会话。

<a id="redaction-and-logging"></a>
## 敏感信息遮蔽与日志

账户状态消息使用固定语句，例如“Cloudflare API is unreachable; try again later”。Cloudflare 错误响应中的文字不会复制到这些消息中。状态变更写入 Cacti 日志时，会包含账户行 ID、Cloudflare 账户 ID、旧状态、新状态，以及错误类别或原因代码，绝不包含令牌。

`Support\Redactor` 按从长到短的顺序替换已知秘密，并将任何 `Authorization: Bearer` 值替换为 `[REDACTED]`。客户端在将 Cloudflare 错误消息和 curl 错误文字放入异常前先执行此处理。curl 错误文字还会移除控制字节，并截断为 200 个字符。

处理令牌的测试会断言令牌不出现在日志、异常消息或输出中。安装冒烟测试会在 Cacti 日志、Apache 日志、保存的所有 HTTP 响应以及完整数据库转储中搜索所使用的令牌。

<a id="database-errors"></a>
## 数据库错误

所有查询均为绑定参数值的预处理语句，标识符从不根据输入构造。语句执行失败时抛出 `DatabaseException`，其消息严格为 `SQLSTATE[<state>] driver error <code>`。插件丢弃驱动程序的错误文字，因为 MySQL 会在其中回显语句文本，而使用模拟预处理时文本中包含绑定值。页面记录上述消息并显示固定错误：保存及批量操作使用 Cacti 的通用保存错误；列表、编辑和确认页面显示“The Cloudflare accounts could not be read”。任何读取路径都不会让异常逃出，因为 Cacti 的关闭处理器会停用因未处理异常而终止页面的插件。

<a id="access-control"></a>
## 访问控制

| 权限 | 页面 | 默认设置 |
|---|---|---|
| Cloudflare: Configure | `cloudflare_accounts.php` | 安装时授予管理员 |
| Cloudflare: View | `cloudflare.php` | 安装时不授予 |
| Cloudflare: Operator | `cloudflare_operator.php` | 必须显式授予 |
| Sites/Devices/Data（Cacti 对 `host.php` 使用的权限） | Create Cacti Device 和开启 Create Graphs | 由 Cacti 自身授权 |

Configure 继承 Operator 和 View 访问权限，Operator 继承 View；每个请求都会依据当前用户及用户组权限进行判断，不修改已存储的授权。Operations 与 View 一样可查看全部账户，不额外增加按设备过滤。确认操作要求使用 POST、有效的 CSRF 令牌，以及由服务器重新加载且指纹匹配的新鲜观测结果。确认记录仅属于当前会话中的用户，最多持续一小时，在观测结果变化时失效，并且不会改变健康状态或抑制告警。

`include/auth.php` 根据页面名称强制检查权限。`AccountService` 在每次更改、Verify 和凭据查询前，都会再次调用 `api_user_realm_auth('cloudflare_accounts.php')` 检查，否则抛出 `AccessDenied`。安装冒烟测试验证只有 Cloudflare: View 的用户无法访问账户页面。BDD 场景验证没有配置权限的用户既不能更改账户，也不能调用 `credentialDetails()`。查看页面（`cloudflare.php`）只读，需要 Cacti 根据页面名称检查的 Cloudflare: View 权限，不接受 POST 操作。隧道和连接器文字来自 Cloudflare，因此每个值都经 `html_escape()` 输出。Tunnels 的过滤和排序值在进入查询前会根据固定列表验证。

设备和图表操作需要第二项权限。Cacti 设备属于 `host.php` 对象，因此仅有 Configure 不应允许创建设备。Create Cacti Device 和开启 Create Graphs 均要求 `cloudflare_accounts.php` 权限以及 `api_user_realm_auth('host.php')` 通过检查（`includes/cacti_objects.php:34`）。下拉菜单对未同时具备这两项权限的用户隐藏操作（`includes/accounts_page.php:55-62`）；处理器在调用任何 Cacti 接口之前再次拒绝未经授权的请求（`:234-243`）；`cloudflare_create_device()` 还会第三次检查（`includes/cacti_objects.php:47`）。没有 `host.php` 权限的用户若提交开启 Create Graphs 的账户表单，该字段会被丢弃，存储值保持不变；若该选项原本关闭，还会显示警告（`includes/accounts_page.php:193-209`）。关闭此选项只需要 Configure。采集器在没有登录用户的情况下创建图表，因此权限检查发生在保存设置时。

`list()` 不单独检查权限，因为返回的 `Account` 读取模型不含令牌、密文、令牌来源或文件路径；集成测试会确认列表转储中不存在这些信息。

保存和批量操作（Delete、Verify、Enable、Disable）只接受 POST；其他方法会重定向回列表。Cacti 使用 csrf-magic 检查 POST 请求体。批量操作通过 `sanitize_unserialize_selected_items()` 读取所选 ID。数值请求参数使用 `get_filter_request_var()` 读取；文本字段在存储前由 `AccountService` 验证。输出到页面的每个值都经过 `html_escape()` 或 `__esc()`。页面脚本为静态文件 `js/cloudflare_accounts.js`，在 Cacti 提供 CSP nonce 时使用该 nonce 加载；插件不添加内联脚本。

<a id="collector"></a>
## 采集器

隧道发现运行在独立的 PHP 进程 `poller_cloudflare.php` 中。

- **仅限 CLI。** 当 `php_sapi_name()` 不是 `cli` 时，脚本返回 404 并退出，因此无法通过 HTTP 请求运行。
- **启动。** `cloudflare_poller_bottom()` 从 `poller_bottom` 钩子通过 `exec_background()` 启动该脚本，且仅在主轮询器上启动。PHP 二进制文件由 Cacti 的 `path_php_binary` 设置决定，脚本路径会进行转义；请求数据不会进入命令行。
- **同一时间只有一个进程。** 采集器获取 `GET_LOCK('plugin_cloudflare_collector', 0)`。超时为 0，第二个采集器会立即退出，连接结束时 MySQL 会释放锁。每个任务行还保存一个租约，对账户的每次写入都与租约检查处于同一事务中。
- **有界运行。** 采集器在 50 秒后不再开始处理新账户，每次运行最多发出 200 次连接信息请求。任一限制也可能在账户的连接读取过程中停止处理，并记录为 `partial`。每个请求的连接超时为 5 秒，总超时采用 Collector Request Timeout（默认为 20 秒），因此缓慢的 Cloudflare API 会使运行在下一次检查时结束，且不会阻塞轮询周期。
- **停用检查。** 每个账户处理前都直接读取 `plugin_config.status`。`api_plugin_is_enabled()` 会在进程中缓存结果，因此无法发现运行期间发生的停用。读取失败按已停用处理。
- **凭据。** 每个账户的令牌均从与 Verify 相同的文件或保险库解析，采用相同检查；加载 sodium 时会在使用后清除。无法读取的令牌只记录原因代码。
- **日志。** Cloudflare 客户端以外的故障只记录异常类名，不记录其他内容，因为消息或堆栈可能引用 Cloudflare 文字或令牌。运行日志在 Cacti 的中等详细级别记录账户行的数字 ID、错误类别及计数。

<a id="cacti-devices-graphs-and-templates"></a>
## Cacti 设备、图表和模板

- **主机名。** 账户设备的主机名为 `<cf_account_id>.cloudflare.invalid`（`includes/cacti_objects.php:107`）。`.invalid` 是保留域，不会被解析；SNMP 版本为 0，可用性检测方法为无，因此 Cacti 不会因该设备连接任何目标。设备不携带令牌或凭据。
- **插件目录外只有一处固定写入。** 插件将自带的 `templates/resource/cloudflare_tunnels.xml` 复制到 `<base_path>/resource/script_server/cloudflare_tunnels.xml`，因为 Cacti 1.2.31 仅从 `resource/` 读取数据查询 XML（`includes/templates.php:46-52`）。来源和目标均不取自输入。函数在目标旁使用 `fopen()` 的 `xb` 模式写入临时文件；文件名已存在时会失败，因此不会跟随预先放在该临时名称处的链接。随后设置 0644 权限，并通过重命名覆盖目标（`includes/templates.php:140-178`）。目标位置的符号链接会被替换，因为 `rename()` 替换的是链接本身，而不是通过链接写入。仅当目标是内容完全相同的普通文件时才跳过复制（`:151`）。失败会记录日志并返回 false，不会抛出异常，因为 Cacti 会停用安装时抛出异常的插件。插件不会在目录外进行其他写入，卸载时保留该文件。
- **创建不完整的设备。** 如果 `api_device_save()` 成功但插件无法记录设备，会通过 `api_device_remove()` 删除该主机，然后再次读取主机表。使用的主机 ID 仅为同一请求刚刚获得的 ID，绝不来自请求参数或数据库中的旧值。如果主机仍然存在，日志会记录其 ID，由管理员在 Devices 中删除（`includes/cacti_objects.php:146-158`、`:172-188`）。
- **锁。** `GET_LOCK('plugin_cloudflare_device_<account id>')` 让同一账户的第二个 Create Cacti Device 等待，之后找到已有设备，因此一个账户不会获得两个设备。锁在 `finally` 中释放；释放失败会记录日志，但不会替换原结果（`includes/cacti_objects.php:65-82`）。`GET_LOCK('plugin_cloudflare_templates')` 防止两个升级进程同时导入模板（`includes/templates.php:79-94`）。连接结束时 MySQL 会释放这两种锁。
- **模板导入。** `import_xml_data()` 通过返回值和 `$import_messages` 报告失败，而不是通过异常；插件会检查两者，然后验证每个对象均可通过哈希解析（`includes/templates.php:188-251`）。导入失败时只记录阶段名称。
- **脚本服务器。** `ss_cloudflare.php` 只读取插件表，不进行网络调用。设备 ID 必须符合 `^[1-9][0-9]{0,9}\z`，隧道索引必须为小写 UUID；两者均绑定到预处理语句中。失败时只记录异常类名（`includes/script_server.php:38-84`）。

<a id="disabled-plugin"></a>
## 已停用的插件

Cacti 会保留已停用插件的权限定义，并继续启用其 `config_arrays` 钩子，因此插件自行调用 `api_plugin_is_enabled()` 检查。停用期间，菜单入口和面包屑导航会隐藏，`cloudflare_accounts.php`、`cloudflare_operator.php` 和 `cloudflare.php` 显示 Cacti 的权限拒绝页面。采集器直接检查 `plugin_config.status`，并在不执行工作的情况下退出。Settings > Cloudflare 选项卡仍可见，以便在启用插件前设置密钥路径。安装冒烟测试会检查停用时的拒绝访问以及重新启用后的恢复。

<a id="test-stack"></a>
## 测试环境

安装冒烟测试让 Cacti 连接模拟的 Cloudflare API。模拟 API 在测试栈的 Docker 网络内以 `api.cloudflare.com` 响应，使用由容器启动时创建的 CA 签发的证书。此 CA 有效期为两天，并以名称约束限定为 `api.cloudflare.com`。只有 Cacti Web 容器将其安装到系统证书存储。测试会确认网关容器不信任该 CA，且其他名称均被拒绝。

插件不提供 CA 设置或来源覆盖选项。`CurlTransport` 不设置 CA 参数，因此生产环境使用操作系统的证书存储；`OutboundPolicy::production()` 仅允许 `https://api.cloudflare.com`。测试 CA 仅存在于冒烟测试环境。

<a id="uninstall"></a>
## 卸载

卸载会删除插件表，包括已存储的密文，并删除 `cloudflare_` 设置。它不会修改密钥文件或令牌文件，也不会删除 Cacti 设备、图表、数据源、RRD 文件、模板或 `resource/script_server/cloudflare_tunnels.xml`。

<a id="known-limitations"></a>
## 已知限制

- `#[\SensitiveParameter]` 仅在 PHP 8.2 及以上版本中隐藏堆栈里的令牌参数。PHP 7.4 至 8.1 中它不起作用，因此 PHP 自行打印的堆栈可能包含令牌参数。
- 仅在加载 sodium 扩展时才通过 `sodium_memzero()` 清除令牌和密钥，而且 PHP 可能已经复制了字符串。这只能作为尽力而为的措施。
- 出站请求忽略 HTTP 代理设置。只能通过代理访问互联网的 Cacti 服务器无法访问 Cloudflare API。
- 没有密钥轮换工具。换用新密钥会使现有保险库令牌报出“Stored token cannot be decrypted”，直到逐个重新输入令牌；README 的“Rotating the key”章节列出了步骤。使用新密钥重新加密全部密文的换钥命令属于可能的后续改进。
- 文件权限问题在 Verify 时出现，而不是保存时。
- `TokenFile` 仅拒绝路径最后一个组成部分为符号链接的情况；除非设置了 Token File Directory，否则不会检查父目录。
- 当插件因真正的致命错误而终止，例如内存耗尽、超过 `max_execution_time` 或编译错误时，Cacti 会将其停用。任何 `catch` 都无法拦截这些错误，插件也无法阻止；管理员必须重新启用。页面会捕获 `Throwable` 并记录类名，因此缺表等普通异常会显示固定消息，不会进入 Cacti 的关闭处理器。
- 期间没有完整列表的三次计入中断类失败会将账户的活动隧道标记为 `unavailable`，即使原因是持续较长的速率限制。其他失败既不增加也不重置中断计数。下一次完整列表读取会重置计数并恢复这些隧道。
- 当升级改变随插件提供的模板 XML 时，插件会覆盖管理员对已导入模板的修改；设备必须留在主轮询器上。请参阅 README 的已知限制。
- Verify 在 Web 请求内执行。一次 Verify 最多可能发出四个请求：账户验证、用户验证、隧道探测和区域探测。每个请求均受上述超时限制，且最多重试一次。
