<!-- Source: docs/metrics.md; SHA256: 62af26b17013d9977cbe2898d8822142f0a8e0768d6b35af3193c4e8935dd773 -->

<a id="metrics"></a>
# 指标

插件绘制发现过程已存储的隧道状态。轮询器执行路径不会调用 Cloudflare：`ObservationReader`（`src/Metrics`）读取插件表，为每个隧道的每项指标生成一条观测。观测包含一个值，或包含空值及原因。空值输出为 `U`。

<a id="catalog"></a>
## 目录

`Catalog::all()` 是模板、数据源名称和脚本服务器字段的唯一来源列表。每项指标均为 `GAUGE`，最小值为 0。

| 指标 | 查询字段 | DS 名称 | 单位 | 最大值 | 类别 |
|---|---|---|---|---|---|
| `cloudflare_tunnel_health` | `health` | `health` | 状态 | 3 | 状态 |
| `cloudflare_tunnel_connectors` | `connectors` | `connectors` | 连接器 | U | 状态 |
| `cloudflare_tunnel_connections` | `connections` | `connections` | 连接 | U | 状态 |
| `cloudflare_tunnel_connector_versions` | `versions` | `versions` | 版本 | U | 状态 |
| `cloudflare_collection_age` | `collectAge` | `collect_age` | 秒 | U | 采集 |
| `cloudflare_collection_failures` | `collectFailures` | `collect_fail` | 失败 | U | 采集 |

`versions` 是隧道当前连接器中不同 cloudflared 版本的数量。`collectAge` 是距上次成功读取隧道连接的时间，`collectFailures` 是发现作业的连续失败次数。

<a id="health"></a>
## 健康状态

| Cloudflare 状态 | 值 |
|---|---|
| `healthy` | 3 |
| `degraded` | 2 |
| `down` | 1 |
| `inactive` | 0 |

不在此列表中的状态视为未知，原因为 `malformed`。

<a id="when-a-value-is-printed"></a>
## 何时输出值

缺失值绝不能记录为零。RRD 中的零看起来像真实故障，之后无法区分，因此读取器无法确认的任何值都输出为 `U`。

```
window = 2 * discovery_interval + 60
age    = now - observed_at
```

`now` 是轮询器的时钟。对于健康状态，`observed_at` 为 `last_seen_at`；对于连接指标（`connectors`、`connections`、`versions`），则为 `connections_refreshed_at`。只有满足以下所有条件，状态指标才有值：

- 账户发现作业的 `last_status` 为 `ok` 或 `partial`；
- 隧道的 `lifecycle` 为 `active`；
- 已设置 `observed_at`，且 `-60 <= age <= window`。-60 到 0 之间的时龄视为时钟漂移，按 0 计；
- 对于连接指标，`connections_attempted_at <= connections_refreshed_at`，即最近一次尝试成功。

检查按上述顺序执行，第一个未通过的检查决定原因：

1. 作业状态。没有作业行或状态为 `never` 时，原因为 `never_collected`。除 `ok` 和 `partial` 之外的任何状态均采用作业的 `last_error_class`；未存储该值时采用 `transient`。
2. 生命周期。`unavailable` 或 `deleted` 对应 `not_active`。
3. `observed_at` 为空：`never_collected`。
4. 时龄小于 -60：`clock_skew`。时龄超过窗口：`stale`。
5. 仅连接指标：尝试时间晚于刷新时间时，采用隧道的 `connections_error_class`；未存储该值时采用 `transient`。
6. 存储值本身。`connector_count` 或 `connection_count` 为空时，即使已设置 `connections_refreshed_at`，原因仍为 `never_collected`。Cloudflare 状态不在健康状态表中时，仅健康指标的原因为 `malformed`，其他指标不受影响。`versions` 统计连接器行数，发现过程将这些行与 `connector_count` 一同存储，因此 `connector_count` 为空也会使 `versions` 未知，原因为 `never_collected`。

首先检查作业失败，因为它可以解释其余情况。发现过程在重复故障后将隧道标记为 `unavailable`，因此作业的错误类别是更有用的原因。

默认间隔为 300 秒时，窗口为 660 秒。如果隧道的作业仍显示 `ok`，但已 661 秒未刷新，则输出 `U`，原因为 `stale`。

<a id="collection-metrics"></a>
### 采集指标

`collectAge` 和 `collectFailures` 描述采集器本身，因此在故障期间仍会输出。它们忽略作业状态、`unavailable` 生命周期和窗口。在以下情况下为未知：

- 隧道为 `deleted`（`not_active`）；
- 隧道连接从未刷新，或账户没有作业行（`never_collected`）；
- 对于 `collectAge`，刷新时间比当前时间晚 60 秒以上（`clock_skew`）。

`consecutive_failures` 为空会使 `collectFailures` 未知，原因为 `never_collected`。它们的 `observed_at` 为读取时间。

<a id="reasons"></a>
## 原因

| 原因 | 含义 |
|---|---|
| 一个 `ErrorClass` 值 | 已存储的作业失败或连接读取失败类别 |
| `stale` | 时龄超过窗口 |
| `clock_skew` | 时间在未来 60 秒以上 |
| `not_active` | 隧道为 `unavailable` 或 `deleted` |
| `never_collected` | 尚未存储过成功读取的结果 |
| `unknown_tunnel` | 账户没有与此 ID 完全匹配的隧道 |

`ErrorClass` 的值为 `auth`、`permission`、`not_found`、`rate_limited`、`transient`、`malformed` 和 `config`。

隧道 ID 必须精确匹配。列的排序规则不区分大小写，因此读取器在查询前会拒绝任何不是小写 UUID 的值。

<a id="sampling"></a>
## 采样

Cacti 在每次轮询时采样最新观测。发现过程每个间隔运行一次，因此两次发现之间的 gauge 会重复同一数值。RRD 时间戳为轮询时间，最多可比 `observed_at` 晚一个完整窗口，即 `2 * discovery_interval + 60` 秒。

<a id="schema-version"></a>
## 模式版本

观测携带 `Version::METRIC_SCHEMA`，其值为 1。版本 1 仅提供 gauge，不会发生计数器重置。`type` 字段仍区分 `GAUGE` 和 `COUNTER`，供后续版本使用。重命名指标或 DS 名称，或更改类型或边界，都需要新的模式版本，因为模板和现有 RRD 文件依赖这些定义。
