<!-- Source: docs/metrics.md; SHA256: c6b94acea49d9d6a2ec73a6a35e82dbe005ee48017ba37e3e7960a8486c64c20 -->

<a id="metrics"></a>
# المقاييس

يرسم الملحق حالة الأنفاق التي يخزنها الاكتشاف بالفعل. لا يستدعي أي جزء من مسار الاستطلاع Cloudflare: يقرأ `ObservationReader` في `src/Metrics` جداول الملحق ويحوّل كل نفق إلى ملاحظة واحدة لكل مقياس. تتضمن الملاحظة قيمة، أو قيمة null وسببًا. تُطبع null بصيغة `U`.

<a id="catalog"></a>
## الفهرس

`Catalog::all()` هو القائمة الوحيدة التي تُشتق منها القوالب وأسماء مصادر البيانات وحقول خادم البرامج النصية. كل مقياس من نوع `GAUGE` وحده الأدنى 0.

| المقياس | حقل الاستعلام | اسم DS | الوحدة | الحد الأعلى | الفئة |
|---|---|---|---|---|---|
| `cloudflare_tunnel_health` | `health` | `health` | حالة | 3 | حالة |
| `cloudflare_tunnel_connectors` | `connectors` | `connectors` | موصلات | U | حالة |
| `cloudflare_tunnel_connections` | `connections` | `connections` | اتصالات | U | حالة |
| `cloudflare_tunnel_connector_versions` | `versions` | `versions` | إصدارات | U | حالة |
| `cloudflare_collection_age` | `collectAge` | `collect_age` | ثوانٍ | U | جمع |
| `cloudflare_collection_failures` | `collectFailures` | `collect_fail` | إخفاقات | U | جمع |

`versions` هو عدد إصدارات cloudflared المختلفة بين موصلات النفق الحالية. `collectAge` هو الزمن منذ آخر قراءة ناجحة لاتصالات النفق، و`collectFailures` هو عدد الإخفاقات المتتالية لمهمة الاكتشاف.

<a id="health"></a>
## السلامة

| حالة Cloudflare | القيمة |
|---|---|
| `healthy` | 3 |
| `degraded` | 2 |
| `down` | 1 |
| `inactive` | 0 |

أي حالة خارج هذه القائمة تُعد مجهولة بالسبب `malformed`.

<a id="when-a-value-is-printed"></a>
## متى تُطبع قيمة

يجب ألا تُسجل القيمة المفقودة على أنها صفر مطلقًا. يبدو الصفر في RRD كانقطاع فعلي ولا يمكن تمييزه عنه لاحقًا، لذا يُمثل كل ما لا يستطيع القارئ تأكيده بالقيمة `U`.

```
window = 2 * discovery_interval + 60
age    = now - observed_at
```

`now` هو وقت ساعة أداة الاستطلاع. يساوي `observed_at` الحقل `last_seen_at` للسلامة، و`connections_refreshed_at` لمقاييس الاتصال (`connectors` و`connections` و`versions`). لا تكون لمقياس الحالة قيمة إلا عند تحقق جميع الشروط التالية:

- أن يكون `last_status` لمهمة اكتشاف الحساب هو `ok` أو `partial`؛
- أن يكون `lifecycle` للنفق هو `active`؛
- أن يكون `observed_at` محددًا وأن يتحقق `-60 <= age <= window`. يُعد العمر بين -60 و0 انحرافًا في الساعة ويُحسب صفرًا؛
- لمقاييس الاتصال، أن يتحقق `connections_attempted_at <= connections_refreshed_at`، أي أن المحاولة الأخيرة نجحت.

تُجرى الفحوص بهذا الترتيب، ويحدد أول فحص يفشل السبب:

1. حالة المهمة. غياب صف المهمة أو الحالة `never` يعطي `never_collected`. أي حالة غير `ok` أو `partial` تعطي `last_error_class` للمهمة، أو `transient` إن لم تكن فئة مخزنة.
2. دورة الحياة. تعطي `unavailable` أو `deleted` السبب `not_active`.
3. إذا كان `observed_at` فارغًا: `never_collected`.
4. عمر أقل من -60: `clock_skew`. عمر يتجاوز النافذة: `stale`.
5. لمقاييس الاتصال فقط: المحاولة الأحدث من التحديث تعطي `connections_error_class` للنفق، أو `transient` إن لم تكن فئة مخزنة.
6. القيمة المخزنة نفسها. يعطي `connector_count` أو `connection_count` الفارغ السبب `never_collected` حتى لو كان `connections_refreshed_at` محددًا. تعطي حالة Cloudflare غير المدرجة في جدول السلامة `malformed` للسلامة وحدها، دون التأثير في المقاييس الأخرى. يحصي `versions` قيم الإصدارات المختلفة بين الموصلات المخزنة، وليس عدد صفوف الموصلات. يخزن الاكتشاف هذه الصفوف مع `connector_count`، لذا يجعل `connector_count` الفارغ قيمة `versions` مجهولة أيضًا بالسبب `never_collected`.

يأتي فشل المهمة أولًا لأنه يفسر البقية. يوسم الاكتشاف الأنفاق بالحالة `unavailable` بعد انقطاعات متكررة، ولذلك تكون فئة خطأ المهمة السبب الأكثر فائدة.

مع الفاصل الافتراضي البالغ 300 ثانية تكون النافذة 660 ثانية. النفق الذي ما زالت مهمته تعرض `ok` لكنه لم يُحدث منذ 661 ثانية يطبع `U` بالسبب `stale`.

<a id="collection-metrics"></a>
### مقاييس الجمع

يصف `collectAge` و`collectFailures` أداة الجمع نفسها، لذا يستمران في الظهور أثناء الانقطاع. يتجاهلان حالة المهمة ودورة الحياة `unavailable` والنافذة. ويكونان مجهولين عندما:

- يكون النفق `deleted` (`not_active`)؛
- لا تكون اتصالات النفق قد حُدثت مطلقًا، أو لا يوجد صف مهمة للحساب (`never_collected`)؛
- بالنسبة إلى `collectAge`، يكون وقت التحديث في المستقبل بأكثر من 60 ثانية (`clock_skew`).

يجعل `consecutive_failures` الفارغ قيمة `collectFailures` مجهولة بالسبب `never_collected`. ويكون `observed_at` لهذين المقياسين هو وقت القراءة.

<a id="reasons"></a>
## الأسباب

| السبب | المعنى |
|---|---|
| قيمة من `ErrorClass` | الفئة المخزنة لفشل المهمة أو قراءة الاتصالات |
| `stale` | أقدم من النافذة |
| `clock_skew` | في المستقبل بأكثر من 60 ثانية |
| `not_active` | النفق `unavailable` أو `deleted` |
| `never_collected` | لم تُخزن قراءة ناجحة بعد |
| `unknown_tunnel` | لا يملك الحساب نفقًا بهذا المعرّف المطابق تمامًا |

قيم `ErrorClass` هي `auth` و`permission` و`not_found` و`rate_limited` و`transient` و`malformed` و`config`.

تُطابق معرّفات الأنفاق مطابقة تامة. يتجاهل ترتيب مقارنة العمود حالة الأحرف، لذا يرفض القارئ أي قيمة ليست UUID بأحرف صغيرة قبل إجراء الاستعلام.

<a id="sampling"></a>
## أخذ العينات

يأخذ Cacti عينة من أحدث ملاحظة في كل استطلاع. يعمل الاكتشاف مرة واحدة في كل فاصل، لذا يكرر مقياس gauge القيمة نفسها بين عمليتي اكتشاف. الطابع الزمني في RRD هو وقت الاستطلاع، الذي قد يأتي بعد `observed_at` بمقدار نافذة كاملة، أي `2 * discovery_interval + 60` ثانية.

<a id="schema-version"></a>
## إصدار المخطط

تحمل الملاحظات `Version::METRIC_SCHEMA` وقيمته 1. يضم الإصدار 1 مقاييس gauge فقط، لذا لا يمكن أن تحدث إعادة ضبط للعدادات. يظل الحقل `type` يميز `GAUGE` عن `COUNTER` للإصدارات اللاحقة. تتطلب إعادة تسمية مقياس أو اسم DS، أو تغيير نوع أو حد، إصدارًا جديدًا للمخطط لأن القوالب وملفات RRD الحالية تعتمد عليها.
