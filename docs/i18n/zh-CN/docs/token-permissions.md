<!-- Source: docs/token-permissions.md; SHA256: 419e51268ec44ef5eb3576233cf0172d4ead2c3a75e4530fc533ad2228cc73ea -->

<a id="api-token-permissions"></a>
# API 令牌权限

插件仅接受限定作用域的 API 令牌，拒绝全局 API 密钥，也不会要求提供账户电子邮件地址。所有请求均为读取操作，无需任何写入权限。以下权限名称采用 Cloudflare 文档中的标签，记录见 [research/cloudflare-api-facts.md](../../../research/cloudflare-api-facts.md#3-token-permissions)。

<a id="milestones-1-and-2"></a>
## 里程碑 1 和 2

| 权限 | 作用域 | 是否必需 | 用途 |
|---|---|---|---|
| `Cloudflare Tunnel Read` | 账户 | 是，也可使用以下等效权限之一 | Verify 中的隧道探测及隧道发现 |
| `Zone Read` | 区域 | 否 | Verify 中的区域探测。目前没有其他用途 |

Cloudflare 的隧道端点接受以下任一权限，对应的 Write 权限也满足要求：

- `Cloudflare Tunnel Read`
- `Cloudflare One Connectors Read`
- `Cloudflare One Connector: cloudflared Read`

Cloudflare API 参考将区域权限称为 `Zone Zone Read`，权限页面称为 `Zone Read`。两者指同一权限。

<a id="what-discovery-calls"></a>
### 发现过程调用的接口

采集器发出两个请求，均为 `GET`，且均需要隧道读取权限：

- `/accounts/{account_id}/cfd_tunnel`：列出账户的隧道
- `/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections`：读取一个隧道的连接器

发现过程只需要 Verify 已检查的隧道读取权限。如果 Cloudflare 在一次运行中拒绝列出隧道，该运行将以 `auth` 或 `permission` 失败。只有完全无法读取 Cloudflare 的失败（`transient`、`rate_limited`、`auth` 或 `permission`）才增加中断计数。其他失败既不增加也不重置该计数。期间没有完整列表的三次计入中断会将账户的活动隧道标记为 `unavailable`。完整列表会重置计数并恢复隧道，即使该运行随后读取连接失败。

令牌验证（`/tokens/verify`）无需权限。账户所有和用户所有的令牌均可使用。用户令牌访问账户验证端点时会收到 HTTP 401，随后插件会改用用户端点重试。

<a id="what-verify-reports"></a>
### Verify 报告的结果

Verify 将结果记录在账户上，并显示在编辑页面的 Connection Status 下：

- **ok**：令牌处于活动状态，且隧道探测成功。
- **permission_denied**：令牌处于活动状态，但隧道探测被拒绝，或两个令牌验证端点均返回 HTTP 403。请添加上述隧道权限之一。
- **Permission: tunnel_read** 和 **Permission: zone_read**：最近一次执行到两个探测的 Verify 中，各探测是否获准。更改账户 ID、令牌、令牌存储方式或令牌文件会清除这些结果。

缺少区域权限不会导致 Verify 失败。

<a id="milestone-4-analytics-planned"></a>
## 里程碑 4：分析（计划中）

分析数据采集尚未实现。系统会保存“Collect Analytics”设置，但目前没有任何组件读取它。分析功能发布后，预计需要：

| 权限 | 作用域 |
|---|---|
| `Account Analytics Read` | 账户 |
| `Analytics Read` | 区域 |

Cloudflare 的 GraphQL 指南为令牌配置 Account Analytics Read，权限页面则将 Analytics Read 列在区域权限下。区域级 `httpRequestsAdaptiveGroups` 查询究竟必须使用其中哪一个，尚未验证。

<a id="creating-the-token"></a>
## 创建令牌

在 Cloudflare 控制面板中创建自定义 API 令牌，可以是用户令牌，也可以是账户令牌。授予 Account > Cloudflare Tunnel > Read，并可选授予 Zone > Zone > Read。将账户资源限制为要监控的账户；如果添加了 Zone Read，也将区域资源限制为该账户的区域。按照 README 中的说明存储令牌。
