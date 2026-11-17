<!-- Source: docs/compatibility.md; SHA256: 4823a4f4a82a0b8bbb36b0a9d34f89a56e61974db5b801cd9399330c7f4e1feb -->

<a id="compatibility"></a>
# 兼容性

本页记录插件支持的版本、测试范围及依赖的外部接口。Cacti 信息来自 `release/1.2.25` 和 `release/1.2.31` 标签；Cloudflare 信息来自 [`research/cloudflare-api-facts.md`](../../../research/cloudflare-api-facts.md)。

<a id="summary"></a>
## 概要

| 组件 | 支持范围 | 测试范围 |
|---|---|---|
| Cacti | 1.2.25 至 1.2.31（INFO 中 `compat = 1.2.25`） | 每个拉取请求的 CI 通过 `make smoke` 测试 1.2.31。1.2.25 仅手动运行 `make smoke CACTI_REF=release/1.2.25`，最近于 2026-10-22 通过全部 22 步；此后的变更未在 1.2.25 测试 |
| PHP | 7.4 至 8.4 | 单元测试：7.4、8.1、8.2、8.3、8.4；集成测试：7.4 和 8.3；冒烟测试：8.0；`php -l`：7.4 |
| 数据库 | MariaDB 10.6 及以后，MySQL 8.0 及以后 | CI：MariaDB 10.11、MySQL 8.4。2026-10-22 还在本地 MariaDB 10.6.28 和 MySQL 8.0.46 上运行集成测试和 BDD；不属于 CI |
| PHP 扩展 | `curl`、`openssl`、`pdo_mysql` | 由 `plugin_cloudflare_check_config()` 检查 |
| Cacti 1.3 | 非发布目标 | 未测试 |

<a id="cacti"></a>
## Cacti

<a id="php-floor"></a>
### PHP 最低版本

插件保持 PHP 7.4 语法。Cacti 1.2.25 至 1.2.30 没有 Composer 平台检查，可在 PHP 7.4 运行；INFO 声明 `compat = 1.2.25`。

Cacti 1.2.31 要求 PHP 8.0 或更新版本。其 `composer.json` 声明 `"php": ">=8.0"`，`include/vendor/composer/platform_check.php` 会在旧版本停止运行。`include/global.php:555` 通过 Composer 自动加载器载入该检查。因此在 1.2.31 上插件运行于 PHP 8.0 或更新版本，安装冒烟测试使用 `php:8.0-apache` 镜像。

<a id="hooks-and-apis-used"></a>
### 使用的钩子和 API

插件不更改 Cacti 核心文件。在 `plugin_cloudflare_install()` 中注册五个钩子和三个权限域：

| 接口 | 用途 | 参考位置（1.2.31） |
|---|---|---|
| `config_arrays` 钩子 | 启用时在 Configuration 下添加 Cloudflare Accounts | `include/global_arrays.php` |
| `config_settings` 钩子 | 添加 Settings > Cloudflare 选项卡并运行待执行迁移 | `include/global_settings.php` |
| `draw_navigation_text` 钩子 | `cloudflare_accounts.php` 的导航路径 | `lib/functions.php` |
| `poller_bottom` 钩子 | 每个轮询周期后仅在主轮询器启动后台采集器 | `poller.php:943`, `poller.php:956` |
| `auth_alternate_realms` 钩子 | 根据当前用户及用户组的授权解析 Configure > Operator > View 继承，不更改存储的授权 | `include/auth.php` |
| `exec_background()` | 将 `poller_cloudflare.php` 与 `poller.php` 分离启动。参数以字符串传递，因为从 1.2.31 才接受数组 | `lib/poller.php:132` |
| `api_plugin_register_realm()` | Cloudflare: View（`cloudflare.php`）、Cloudflare: Operator（`cloudflare_operator.php`）和 Cloudflare: Configure（`cloudflare_accounts.php`） | `lib/plugins.php` |
| `api_plugin_valid_entrypoint()` | 要求注册调用来自名称含 install、upgrade 或 setup 的函数，因此注册保留在 `plugin_cloudflare_install()` 内 | `lib/plugins.php:1124` |
| `api_plugin_uninstall()` | 插件卸载函数返回后，Cacti 删除钩子、权限域和 `plugin_config` | `lib/plugins.php:801` |
| `api_plugin_disable_hooks()`、`api_plugin_load_realms()` | 插件禁用时 Cacti 仍加载权限域并保持 `config_arrays` 活动，因此每个入口检查 `api_plugin_is_enabled()` | `lib/plugins.php:1103`, `lib/plugins.php:1268` |
| `include/auth.php` 中的权限域检查 | 按页面基本文件名执行权限检查 | `include/auth.php:183` |
| `$database_sessions` | 复用 Cacti 的 PDO 句柄 | `lib/database.php:129` |
| `db_connect_real()` | 尚无句柄时使用 Cacti 的参数建立连接 | `include/global.php:363` |

<a id="the-poller_bottom-hook-on-upgrade"></a>
### 升级时的 `poller_bottom` 钩子

0.2.0 添加了 `poller_bottom` 钩子。`api_plugin_register_hook()` 插入非配置钩子时状态为 0，即禁用，除非调用者要求启用（`lib/plugins.php:1030`，`$enable` 分支位于 `lib/plugins.php:1048`）。Cacti 不会重新启用已启用的插件，因此从 0.1.0 升级会让新钩子保持关闭，采集器永远无法启动。对于旧于 0.2.0 的安装，`plugin_cloudflare_upgrade()` 因而按插件当前启用状态注册该钩子。新安装与其他钩子一起注册，插件启用时 Cacti 启用其全部钩子。

钩子仅在主轮询器执行。`poller.php` 也在远程轮询器运行 `poller_bottom`（`poller.php:956`），但 `poller_id` 不为 1 时钩子直接返回。INFO 声明 `remote_collect:0`。

Cacti 核心从不调用插件升级函数（仅 `cli/audit_database.php` 查找一个），因此 `plugin_cloudflare_upgrade()` 从 `plugin_cloudflare_check_config()`、`settings.php` 和 `plugins.php` 的 `config_settings` 钩子，以及要求 Cloudflare: Configure 的 `cloudflare_accounts.php` 顶部运行。`cloudflare.php` 不运行它：该页面属于 Cloudflare: View，而升级会迁移模式、导入模板并写入 `resource/script_server/cloudflare_tunnels.xml`。管理员打开上述页面前，若所需模式缺失，查看页面会显示“The Cloudflare tunnels could not be read”。

以上每个 Cacti 函数和钩子均存在于 `release/1.2.25`。页面仅在类存在时调用 `CactiSecureHeaders::getNonceAttribute()`，因为较早的 1.2 版本没有此类。

<a id="cacti-apis-and-tables-used-for-devices-graphs-and-templates"></a>
### 设备、图表和模板使用的 Cacti API 与表

0.3.0 调用以下函数，签名兼容两个版本。行号来自 `release/1.2.25` 和 `release/1.2.31` 标签。

| 接口 | 用途 | 1.2.25 | 1.2.31 |
|---|---|---|---|
| `api_user_realm_auth()` | 检查 `host.php` 权限域 | `lib/plugins.php:35` | `lib/plugins.php:35` |
| `api_device_save()` | 创建设备（26 个参数，`poller_id` 为 1） | `lib/api_device.php:939` | `lib/api_device.php:939` |
| SNMP 版本 0 的重新索引方式 None | 由 `api_device_save()` 设置 | `lib/api_device.php:1073-1077` | `lib/api_device.php:1073-1077` |
| `api_device_update_host_template()` | 从 `api_device_save()` 调用；按全局默认方式重新添加模板查询 | 调用 `:1131`，函数 `:1226`，`REPLACE` `:1266` | 调用 `:1131`，函数 `:1245`，`REPLACE` `:1285` |
| `api_device_dq_add()` | 再次将重新索引方式设为 None，然后运行查询 | `lib/api_device.php:552-578` | `lib/api_device.php:552-578` |
| `api_device_remove()` | 删除插件已创建但无法记录的主机 | `lib/api_device.php:45` | `lib/api_device.php:45` |
| `DATA_QUERY_AUTOINDEX_NONE` | 重新索引方式 None | `include/global_constants.php:75` | `include/global_constants.php:75` |
| `run_data_query()` | 以 `$force` true 重新索引 | `lib/data_query.php:25` | `lib/data_query.php:25` |
| `run_data_query()` 中的孤立标记及标题刷新 | 孤立标记；`$force` 标题刷新 | `lib/data_query.php:317`, `:356-368` | `lib/data_query.php:317`, `:356-368` |
| `get_data_query_array()` | 读取数据查询 XML | `lib/data_query.php:599`，无路径检查，`file_exists()` 在 `:612` | `lib/data_query.php:599`，路径检查 `:612-620` |
| `cacti_path_is_within()` | 1.2.31 通过 `realpath()` 检查路径 | 不存在 | `lib/functions.php:8058` |
| `query_script_host()` | 将脚本作为 CLI 脚本执行 index、query 和 num_indexes | `lib/data_query.php:635`，脚本服务器前缀 `:646` | `lib/data_query.php:645`，脚本服务器前缀 `:656` |
| `output_delimiter` 拆分 | 在首个 `!` 拆分列表行 | `lib/data_query.php:738` | `lib/data_query.php:748` |
| 脚本服务器 `get` 调用 | `<arg_prepend>`、`get`、字段、索引 | `lib/utility.php:431`（索引未加引号） | `lib/utility.php:544`（索引加引号） |
| 脚本服务器包含文件和函数检查 | `ss_cloudflare.php` 可放置的位置 | 无基本路径检查（`script_server.php:261`, `:278`） | `script_server.php:264-290`, `:327-361` |
| `get_best_data_query_index_type()` | 新图表的索引类型 | `lib/data_query.php:2285` | `lib/data_query.php:2318` |
| `import_xml_data()` | 按哈希导入模板 | `lib/import.php:25`，失败返回 `:39`, `:56`, `:177-227`，`result` `fail` `:901`, `:1209`, `:1477` | `lib/import.php:25`，失败返回 `:39`, `:56`, `:177-227`，`result` `fail` `:978`, `:1286`, `:1554` |
| 模板哈希版本 `0103` | 随附 XML 的哈希前缀 | `include/global_arrays.php:135-140`（1.2.20 至 1.2.25） | `include/global_arrays.php:135-146`（1.2.20 至 1.2.31） |
| `create_complete_graph_from_template()` | 创建图表及其数据源 | `lib/template.php:1480` | `lib/template.php:1480` |
| `data_source_exists()` | 让隧道的后续图表复用数据源 | `lib/template.php:2194`，调用 `:1596`，复用 `:1615` | `lib/template.php:2194`，调用 `:1594`，复用 `:1613` |
| `push_out_host()` | 将新数据源推送到轮询器 | `lib/utility.php:687` | `lib/utility.php:834` |
| `retemplate_graphs()`、`update_replication_crc()` | 导入会调用，因此需加载 | `lib/template.php:873`, `lib/utility.php:29` | `lib/template.php:873`, `lib/utility.php:29` |
| `host.php` 的包含文件 | 保存设备前加载的库 | `host.php:26-39` | `host.php:26-39` |
| `cli/add_device.php`、`cli/add_graphs.php` | 设备参数和采用现有图表的参考实现 | `add_device.php:199`, `add_graphs.php:659-716` | `add_device.php:199`, `add_graphs.php:659-716` |
| `host.php` 的权限域 | 权限域 3，Sites/Devices/Data | `include/global_arrays.php:1227`, `:1285` | `include/global_arrays.php:1240`, `:1298` |
| `read_config_option()`、`set_config_option()` | 模板哈希与主机缺失设置 | `lib/functions.php:679`, `:419` | `lib/functions.php:680`, `:418` |
| `db_fetch_cell_prepared()` | 读取 Cacti 表 | `lib/database.php:568` | `lib/database.php:665` |
| `raise_message()`、`is_error_message()` | 创建设备和 `api_device_save()` 的结果 | `lib/functions.php:993`, `:890` | `lib/functions.php:996`, `:893` |

插件在 Cacti 模式中读写的表，以及 `cacti.sql` 中的 `CREATE TABLE` 行号（1.2.25 / 1.2.31）如下。读取使用预处理语句，写入通过上述 Cacti 函数执行。

| 表 | 用途 | 行号 |
|---|---|---|
| `host` | 设备存在性及 `deleted` 标记 | 1876 / 1877 |
| `host_snmp_query` | 由 `api_device_dq_add()` 写入的重新索引方式 | 1986 / 1987 |
| `host_snmp_cache` | 上次重新索引的索引，创建图表前检查 | 1959 / 1960 |
| `graph_local` | 查找隧道和模板的现有图表 | 1601 / 1602 |
| `graph_templates_item`、`data_template_rrd` | 查找现有图表的数据源 | 1778 / 1779, 1557 / 1558 |
| `data_source_profiles` | 导入所需的默认配置文件 | 1304 / 1305 |
| `settings` | 通过 `set_config_option()` 操作 `cloudflare_templates_sha256` 和 `cloudflare_missing_host_<account id>` | 2411 / 2412 |
| `host_template`、`snmp_query`、`data_template`、`graph_templates`、`snmp_query_graph` | 按哈希解析已导入对象 | 2003 / 2004, 2471 / 2471, 1510 / 1511, 1656 / 1657, 2491 / 2491 |

两个版本行号不同，因此同时列出。以上所有函数均存在于两个版本。行为差异在数据查询路径检查、脚本服务器基本路径检查和 `get` 调用中索引的引号处理。

<a id="the-1231-resource-path-rule"></a>
### 1.2.31 的资源路径规则

从 1.2.31 开始，`get_data_query_array()` 拒绝经 `realpath()` 解析后不位于 `<path_cacti>/resource` 内的数据查询 XML（`lib/data_query.php:612-620`、`lib/functions.php:8058`）。1.2.25 读取任何已存在文件。插件在两者上均将 XML 安装到 `<base_path>/resource/script_server/cloudflare_tunnels.xml`，因此同一模板 XML 兼容两者。从 `resource/` 指向插件目录的符号链接无法通过 1.2.31 检查，因此插件复制文件而不链接。轮询器脚本路径仍在插件目录中，1.2.31 脚本服务器接受它，因为位于 `base_path` 下。

<a id="the-smoke-on-1225"></a>
### 1.2.25 的冒烟测试

`make smoke CACTI_REF=release/1.2.25` 从 1.2.25 标签构建同样的 PHP 8.0 镜像，标记为 `cf-smoke-cacti:1.2.25`，运行所有冒烟步骤，包括创建设备、创建图表和 RRD 检查。共有 22 步（`tests/docker/smoke.sh`）。2026-10-22，在 MariaDB 10.11.10 上，1.2.31 和 1.2.25 均通过全部 22 步。CI 冒烟作业仅检出 1.2.31，因此 1.2.25 结果只覆盖该日期代码，后续变更未重复测试。

设备和图表步骤（5f 至 5j）通过 HTTP 创建设备，运行一次到期轮询以重新索引并为三个测试隧道创建 9 个图表，检查 blog 的 RRD 中 `connectors` 为 1、`connections` 为 1、`health` 为 3。Cloudflare 返回 503 时，检查 `connectors`、`connections` 和 `health` 均为 `U`；恢复响应后，检查值恢复且图表数仍为 9。步骤 6 检查卸载保留 9 个图表。1.2.25 的 `cli/plugin_manage.php` 仅与 `--install` 一起才能启用插件，因此对已安装插件，冒烟测试像 `plugins.php` 一样调用 `api_plugin_enable()`。

不调用 1.2.25 之后添加的辅助函数，如 `cacti_redact_sensitive()` 和 `cacti_plugin_path()`。插件自带敏感信息脱敏功能。

<a id="known-issue-poller-output-warnings-on-some-mariadb-releases"></a>
### 已知问题：部分 MariaDB 版本的轮询器输出警告

在 MariaDB 10.11.19、11.4.13 和 11.8.9 中，Cacti 在 `process_poller_output()` 的联接 `SELECT` 后对 MEMORY 表 `poller_output` 执行 `DELETE`（1.2.31 的 `lib/poller.php:539`），会留下部分行；复现时 3 行仅删除 2 行。MariaDB 10.6.28、10.11.10 和 MySQL 8.4 删除了全部 3 行。受影响版本中，任何具有多个轮询项的数据源（包括 Cacti 自身的数据源，并非仅此插件）在每次轮询都会记录“There are N Data Sources not returning all data”（`lib/poller.php:857`）和“Poller Output Table not Empty”（`poller.php:608`，还调用 `admin_email()`）。RRD 值不受影响：更新在 `DELETE` 前构建，Cacti 在下一次处理时删除剩余行。因此安装冒烟测试固定 MariaDB 10.11.10，并在任一警告出现时失败。

<a id="php"></a>
## PHP

`composer.json` 设置 `config.platform.php = 7.4.33`，使开发工具按 PHP 7.4 解析依赖。PHPStan 使用 `phpVersion: 70400`。插件无运行时 Composer 依赖；`vendor/` 缺失时（发布包即如此），`includes/autoload.php` 使用内置 PSR-4 加载器加载 `src/`。

`#[\SensitiveParameter]` 标记令牌参数。PHP 8.2 及以后会从堆栈跟踪隐藏这些参数；PHP 8.0 和 8.1 忽略未知属性，PHP 7.4 将该行视为注释。见 [security.md](security.md#known-limitations)。

<a id="database"></a>
## 数据库

模式使用 InnoDB、utf8mb4、带 `ON DELETE CASCADE` 的外键、用于迁移的 `GET_LOCK()` 和 `INT UNSIGNED` epoch 时间戳（[schema.md](../../../schema.md)）。测试采用 Cacti 会话 `sql_mode`，其中移除了严格模式。

MariaDB 与 MySQL 对一种失败的报告不同。删除被另一张表引用的表时，MariaDB 返回 SQLSTATE `23000` 和错误 1451，MySQL 返回 SQLSTATE `HY000` 和错误 3730（见于 8.0.46 和 8.4.11）。`MigratorTest` 接受两者。

<a id="cloudflare-api"></a>
## Cloudflare API

插件仅向 `https://api.cloudflare.com` 发出只读 `GET` 请求。管理员从 Web 界面运行 Verify 时执行前四个请求，采集器执行后两个。

| 请求 | 目的 | 参考 |
|---|---|---|
| `GET /client/v4/accounts/{account_id}/tokens/verify` | 检查令牌状态 | [令牌验证](../../../research/cloudflare-api-facts.md#1-token-verification) |
| `GET /client/v4/user/tokens/verify` | 账户端点返回认证或权限错误时的回退，用户令牌即如此 | [在线验证](../../../research/cloudflare-api-facts.md#live-verification-2026-10-21) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?per_page=1` | 探测隧道读取权限 | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/zones?account.id={account_id}&per_page=5` | 探测区域读取权限 | [令牌权限](../../../research/cloudflare-api-facts.md#3-token-permissions) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?is_deleted=false&per_page=1000&page=N` | 列出账户隧道，最多 50 页 | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections` | 列出一个隧道的连接器和连接 | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |

所需权限见 [token-permissions.md](token-permissions.md)。

错误处理遵循研究文件中的实际观测：HTTP 400 搭配错误代码 6003 或 6111 表示令牌格式错误，归类为认证失败。Verify 等 Web 请求最多重试一次。429 响应在 `retry-after` 不超过 2 秒时按其等待后重试；缺少该头时按常规 1 秒退避；延迟更长则不重试。采集器最多重试两次，接受最长 30 秒的 `retry-after`；更长延迟使请求失败，作业在该延迟后重新运行，但最早不低于 60 秒。对于未知隧道的连接请求，Cloudflare 返回 HTTP 401 和错误代码 1001，客户端归类为 `not_found`，而非认证失败。
