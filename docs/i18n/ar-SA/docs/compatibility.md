<!-- Source: docs/compatibility.md; SHA256: 4823a4f4a82a0b8bbb36b0a9d34f89a56e61974db5b801cd9399330c7f4e1feb -->

<a id="compatibility"></a>
# التوافق

تسجل هذه الصفحة الإصدارات التي يدعمها الملحق وما يُختبر والواجهات الخارجية التي يعتمد عليها. تأتي معلومات Cacti من الوسمين `release/1.2.25` و`release/1.2.31`؛ ومعلومات Cloudflare من [`research/cloudflare-api-facts.md`](../../../research/cloudflare-api-facts.md).

<a id="summary"></a>
## الملخص

| المكون | المدعوم | المختبَر |
|---|---|---|
| Cacti | من 1.2.25 إلى 1.2.31 (`compat = 1.2.25` في INFO) | الإصدار 1.2.31 عبر `make smoke` في CI لكل طلب سحب. الإصدار 1.2.25 يدويًا فقط عبر `make smoke CACTI_REF=release/1.2.25`، الذي نجح آخر مرة في خطواته الـ22 بتاريخ 2026-10-22؛ التغييرات اللاحقة لم تُختبر على 1.2.25 |
| PHP | من 7.4 إلى 8.4 | اختبارات الوحدة على 7.4 و8.1 و8.2 و8.3 و8.4؛ التكامل على 7.4 و8.3؛ اختبار smoke على 8.0؛ و`php -l` على 7.4 |
| قاعدة البيانات | MariaDB 10.6 وما بعده، MySQL 8.0 وما بعده | CI: MariaDB 10.11 وMySQL 8.4. شُغلت اختبارات التكامل وBDD محليًا أيضًا بتاريخ 2026-10-22 مع MariaDB 10.6.28 وMySQL 8.0.46؛ ليست جزءًا من CI |
| امتدادات PHP | `curl` و`openssl` و`pdo_mysql` | يفحصها `plugin_cloudflare_check_config()` |
| Cacti 1.3 | ليس هدفًا للإصدار | غير مختبَر |

<a id="cacti"></a>
## Cacti

<a id="php-floor"></a>
### الحد الأدنى لـ PHP

يحافظ الملحق على صياغة PHP 7.4. لا تملك إصدارات Cacti من 1.2.25 إلى 1.2.30 فحص منصة Composer وتعمل على PHP 7.4، ويعلن INFO القيمة `compat = 1.2.25`.

يتطلب Cacti 1.2.31 الإصدار PHP 8.0 أو أحدث. يعلن `composer.json` الخاص به `"php": ">=8.0"`، ويوقف `include/vendor/composer/platform_check.php` العمل على الإصدارات الأقدم. يحمّل `include/global.php:555` الفحص عبر محمّل Composer التلقائي. لذلك يعمل الملحق على 1.2.31 باستخدام PHP 8.0 أو أحدث، ويستخدم اختبار التثبيت الصورة `php:8.0-apache`.

<a id="hooks-and-apis-used"></a>
### الخطافات وواجهات API المستخدمة

لا يغير الملحق أي ملف من نواة Cacti. يسجل خمسة خطافات وثلاثة نطاقات صلاحية في `plugin_cloudflare_install()`:

| الواجهة | الاستخدام | المرجع (1.2.31) |
|---|---|---|
| الخطاف `config_arrays` | يضيف Cloudflare Accounts ضمن Configuration عند التمكين | `include/global_arrays.php` |
| الخطاف `config_settings` | يضيف تبويب Settings > Cloudflare ويشغّل الترحيلات المعلقة | `include/global_settings.php` |
| الخطاف `draw_navigation_text` | مسار التنقل لصفحة `cloudflare_accounts.php` | `lib/functions.php` |
| الخطاف `poller_bottom` | يبدأ أداة الجمع الخلفية بعد كل دورة، على أداة الاستطلاع الرئيسية فقط | `poller.php:943`, `poller.php:956` |
| الخطاف `auth_alternate_realms` | يحل وراثة Configure > Operator > View من أذونات المستخدم والمجموعات الحالية دون تعديل الأذونات المخزنة | `include/auth.php` |
| `exec_background()` | يبدأ `poller_cloudflare.php` منفصلًا عن `poller.php`. يمرر الملحق الوسائط كسلسلة لأن المصفوفات لم تُقبل إلا بدءًا من 1.2.31 | `lib/poller.php:132` |
| `api_plugin_register_realm()` | Cloudflare: View (`cloudflare.php`) وCloudflare: Operator (`cloudflare_operator.php`) وCloudflare: Configure (`cloudflare_accounts.php`) | `lib/plugins.php` |
| `api_plugin_valid_entrypoint()` | يتطلب استدعاءات التسجيل من دالة يحتوي اسمها install أو upgrade أو setup؛ لذلك يبقى التسجيل داخل `plugin_cloudflare_install()` | `lib/plugins.php:1124` |
| `api_plugin_uninstall()` | يزيل Cacti الخطافات والنطاقات و`plugin_config` بعد عودة دالة إزالة الملحق | `lib/plugins.php:801` |
| `api_plugin_disable_hooks()` و`api_plugin_load_realms()` | يبقي Cacti النطاقات محملة والخطاف `config_arrays` نشطًا عند التعطيل، لذا يفحص كل مدخل `api_plugin_is_enabled()` | `lib/plugins.php:1103`, `lib/plugins.php:1268` |
| فحص النطاق في `include/auth.php` | يفرض نطاق الصفحة بحسب اسم ملفها الأساسي | `include/auth.php:183` |
| `$database_sessions` | يعيد الملحق استخدام مقبض PDO الخاص بـ Cacti | `lib/database.php:129` |
| `db_connect_real()` | يفتح اتصال Cacti بوسائطه نفسها إذا لم يوجد مقبض بعد | `include/global.php:363` |

<a id="the-poller_bottom-hook-on-upgrade"></a>
### الخطاف `poller_bottom` عند الترقية

أضاف الإصدار 0.2.0 الخطاف `poller_bottom`. يُدخل `api_plugin_register_hook()` الخطاف غير الخاص بالضبط بالحالة 0، أي معطلًا، ما لم يطلب المستدعي تمكينه (`lib/plugins.php:1030`، وفرع `$enable` في `lib/plugins.php:1048`). لا يعيد Cacti تمكين ملحق مفعّل، لذا كانت الترقية من 0.1.0 ستترك الخطاف الجديد معطلًا ولن تبدأ أداة الجمع. لذلك يسجل `plugin_cloudflare_upgrade()` الخطاف للتثبيت الأقدم من 0.2.0 بحالة تمكين الملحق الحالية. يسجله التثبيت الجديد مع بقية الخطافات، ويمكّن Cacti جميع خطافات الملحق عند تمكينه.

يعمل الخطاف على أداة الاستطلاع الرئيسية فقط. يشغّل `poller.php` الخطاف `poller_bottom` أيضًا على أدوات الاستطلاع البعيدة (`poller.php:956`)، لكنه يعود دون إجراء إذا لم يكن `poller_id` هو 1. يعلن INFO القيمة `remote_collect:0`.

لا تستدعي نواة Cacti دالة ترقية الملحق مطلقًا (يبحث عنها `cli/audit_database.php` فقط)، لذا يعمل `plugin_cloudflare_upgrade()` من `plugin_cloudflare_check_config()` ومن خطاف `config_settings` على `settings.php` و`plugins.php`، ومن أعلى `cloudflare_accounts.php` الذي يتطلب Cloudflare: Configure. لا يشغله `cloudflare.php`: هذه الصفحة في نطاق Cloudflare: View، والترقية ترحّل المخطط وتستورد القوالب وتكتب `resource/script_server/cloudflare_tunnels.xml`. حتى يفتح مسؤول إحدى تلك الصفحات، تعرض صفحات المشاهدة “The Cloudflare tunnels could not be read” عند غياب المخطط الذي تقرؤه.

توجد كل دوال Cacti والخطافات أعلاه في `release/1.2.25`. تستدعي الصفحة `CactiSecureHeaders::getNonceAttribute()` فقط عند وجود الصنف، لأن إصدارات 1.2 الأقدم لا تملكه.

<a id="cacti-apis-and-tables-used-for-devices-graphs-and-templates"></a>
### واجهات Cacti وجداوله للأجهزة والرسوم والقوالب

يستدعي الإصدار 0.3.0 الدوال التالية بتواقيع متوافقة مع الإصدارين. أرقام الأسطر من الوسمين `release/1.2.25` و`release/1.2.31`.

| الواجهة | الاستخدام | 1.2.25 | 1.2.31 |
|---|---|---|---|
| `api_user_realm_auth()` | يفحص نطاق `host.php` | `lib/plugins.php:35` | `lib/plugins.php:35` |
| `api_device_save()` | ينشئ الجهاز (26 وسيطًا، `poller_id` يساوي 1) | `lib/api_device.php:939` | `lib/api_device.php:939` |
| طريقة إعادة الفهرسة None لإصدار SNMP 0 | يضبطها `api_device_save()` | `lib/api_device.php:1073-1077` | `lib/api_device.php:1073-1077` |
| `api_device_update_host_template()` | يُستدعى من `api_device_save()` ويعيد إضافة استعلامات القالب بالطريقة الافتراضية العامة | استدعاء `:1131`، دالة `:1226`، `REPLACE` `:1266` | استدعاء `:1131`، دالة `:1245`، `REPLACE` `:1285` |
| `api_device_dq_add()` | يضبط إعادة الفهرسة على None مجددًا ثم يشغّل الاستعلام | `lib/api_device.php:552-578` | `lib/api_device.php:552-578` |
| `api_device_remove()` | يزيل مضيفًا أنشأه الملحق ولم يستطع تسجيله | `lib/api_device.php:45` | `lib/api_device.php:45` |
| `DATA_QUERY_AUTOINDEX_NONE` | طريقة إعادة الفهرسة None | `include/global_constants.php:75` | `include/global_constants.php:75` |
| `run_data_query()` | يعيد الفهرسة مع `$force` true | `lib/data_query.php:25` | `lib/data_query.php:25` |
| تعليم الأيتام وتحديث العناوين في `run_data_query()` | علامة اليتم؛ تحديث العنوان مع `$force` | `lib/data_query.php:317`, `:356-368` | `lib/data_query.php:317`, `:356-368` |
| `get_data_query_array()` | يقرأ XML استعلام البيانات | `lib/data_query.php:599`، بلا فحص مسار، `file_exists()` عند `:612` | `lib/data_query.php:599`، فحص المسار `:612-620` |
| `cacti_path_is_within()` | فحص المسار في 1.2.31 باستخدام `realpath()` | غير موجود | `lib/functions.php:8058` |
| `query_script_host()` | يشغّل البرنامج كـ CLI لعمليات index وquery وnum_indexes | `lib/data_query.php:635`، بادئة خادم البرامج `:646` | `lib/data_query.php:645`، بادئة خادم البرامج `:656` |
| التقسيم بـ `output_delimiter` | يقسم سطر القائمة عند أول `!` | `lib/data_query.php:738` | `lib/data_query.php:748` |
| استدعاء `get` لخادم البرامج | `<arg_prepend>` و`get` والحقل والفهرس | `lib/utility.php:431` (الفهرس بلا اقتباس) | `lib/utility.php:544` (الفهرس مقتبس) |
| فحوص التضمين والدوال في خادم البرامج | موضع `ss_cloudflare.php` المسموح | دون فحص مسار أساسي (`script_server.php:261`, `:278`) | `script_server.php:264-290`, `:327-361` |
| `get_best_data_query_index_type()` | نوع فهرس الرسم الجديد | `lib/data_query.php:2285` | `lib/data_query.php:2318` |
| `import_xml_data()` | يستورد القوالب بالهاش | `lib/import.php:25`، عودة الفشل `:39`, `:56`, `:177-227`، `result` `fail` `:901`, `:1209`, `:1477` | `lib/import.php:25`، عودة الفشل `:39`, `:56`, `:177-227`، `result` `fail` `:978`, `:1286`, `:1554` |
| إصدار هاش القالب `0103` | بادئة هاش XML الموزع | `include/global_arrays.php:135-140` (1.2.20 إلى 1.2.25) | `include/global_arrays.php:135-146` (1.2.20 إلى 1.2.31) |
| `create_complete_graph_from_template()` | ينشئ الرسم ومصدر بياناته | `lib/template.php:1480` | `lib/template.php:1480` |
| `data_source_exists()` | يسمح لرسوم النفق اللاحقة بإعادة استخدام مصدر بياناته | `lib/template.php:2194`، استدعاء `:1596`، إعادة استخدام `:1615` | `lib/template.php:2194`، استدعاء `:1594`، إعادة استخدام `:1613` |
| `push_out_host()` | يدفع مصدر بيانات جديدًا إلى أداة الاستطلاع | `lib/utility.php:687` | `lib/utility.php:834` |
| `retemplate_graphs()` و`update_replication_crc()` | يُحمّلان لأن الاستيراد يستدعيهما | `lib/template.php:873`, `lib/utility.php:29` | `lib/template.php:873`, `lib/utility.php:29` |
| تضمينات `host.php` | المكتبات المحملة قبل حفظ الجهاز | `host.php:26-39` | `host.php:26-39` |
| `cli/add_device.php` و`cli/add_graphs.php` | نموذج لوسائط الجهاز وتبني الرسوم القائمة | `add_device.php:199`, `add_graphs.php:659-716` | `add_device.php:199`, `add_graphs.php:659-716` |
| نطاق `host.php` | النطاق 3، Sites/Devices/Data | `include/global_arrays.php:1227`, `:1285` | `include/global_arrays.php:1240`, `:1298` |
| `read_config_option()` و`set_config_option()` | إعدادات هاش القالب والمضيف المفقود | `lib/functions.php:679`, `:419` | `lib/functions.php:680`, `:418` |
| `db_fetch_cell_prepared()` | يقرأ جداول Cacti | `lib/database.php:568` | `lib/database.php:665` |
| `raise_message()` و`is_error_message()` | نتيجة إنشاء الجهاز و`api_device_save()` | `lib/functions.php:993`, `:890` | `lib/functions.php:996`, `:893` |

الجداول التي يقرؤها الملحق أو يكتبها في مخطط Cacti، مع سطر `CREATE TABLE` في `cacti.sql` (1.2.25 / 1.2.31). يقرؤها بعبارات محضرة، وتتم الكتابة عبر دوال Cacti أعلاه.

| الجدول | الاستخدام | الأسطر |
|---|---|---|
| `host` | وجود الجهاز وعلامة `deleted` | 1876 / 1877 |
| `host_snmp_query` | طريقة إعادة الفهرسة التي يكتبها `api_device_dq_add()` | 1986 / 1987 |
| `host_snmp_cache` | فهارس آخر إعادة فهرسة، تُفحص قبل إنشاء الرسم | 1959 / 1960 |
| `graph_local` | يجد رسمًا قائمًا لنفق وقالب | 1601 / 1602 |
| `graph_templates_item` و`data_template_rrd` | يجدان مصدر بيانات رسم قائم | 1778 / 1779, 1557 / 1558 |
| `data_source_profiles` | الملف التعريفي الافتراضي اللازم للاستيراد | 1304 / 1305 |
| `settings` | `cloudflare_templates_sha256` و`cloudflare_missing_host_<account id>` عبر `set_config_option()` | 2411 / 2412 |
| `host_template` و`snmp_query` و`data_template` و`graph_templates` و`snmp_query_graph` | تحل الكائنات المستوردة بالهاش | 2003 / 2004, 2471 / 2471, 1510 / 1511, 1656 / 1657, 2491 / 2491 |

اختلاف أرقام الأسطر بين الإصدارين هو سبب إدراج كليهما. توجد كل الدوال أعلاه في الإصدارين. يختلف السلوك في فحص مسار استعلام البيانات وفحوص المسار الأساسي لخادم البرامج واقتباس الفهرس في استدعاء `get`.

<a id="the-1231-resource-path-rule"></a>
### قاعدة مسار الموارد في 1.2.31

بدءًا من 1.2.31 يرفض `get_data_query_array()` ملف XML لاستعلام البيانات إذا لم يُحل عبر `realpath()` إلى مكان داخل `<path_cacti>/resource` (`lib/data_query.php:612-620`، `lib/functions.php:8058`). يقرأ 1.2.25 أي ملف موجود. يثبت الملحق XML في `<base_path>/resource/script_server/cloudflare_tunnels.xml` في كليهما، فيعمل قالب واحد على الإصدارين. يفشل الرابط الرمزي من `resource/` إلى مجلد الملحق في فحص 1.2.31، لذا ينسخ الملحق الملف ولا يربطه. يبقى مسار برنامج الاستطلاع داخل مجلد الملحق ويقبله خادم 1.2.31 لأنه تحت `base_path`.

<a id="the-smoke-on-1225"></a>
### اختبار smoke على 1.2.25

يبني `make smoke CACTI_REF=release/1.2.25` صورة PHP 8.0 نفسها من وسم 1.2.25، باسم `cf-smoke-cacti:1.2.25`، ويشغّل جميع خطوات الاختبار، بما فيها إنشاء الجهاز والرسوم وفحوص RRD. يتضمن الاختبار 22 خطوة (`tests/docker/smoke.sh`). نجحت كلها بتاريخ 2026-10-22 على 1.2.31 و1.2.25 مع MariaDB 10.11.10. تفحص مهمة CI الإصدار 1.2.31 وحده، لذا تغطي نتيجة 1.2.25 كود ذلك التاريخ ولا تُكرر للتغييرات اللاحقة.

تنشئ خطوات الجهاز والرسوم (5f إلى 5j) الجهاز عبر HTTP، وتشغّل استطلاعًا مستحقًا يعيد الفهرسة وينشئ 9 رسوم للأنفاق التجريبية الثلاثة، وتفحص RRD الخاص بـ blog للتأكد من `connectors` 1 و`connections` 1 و`health` 3. عند Cloudflare 503 تتحقق من أن `connectors` و`connections` و`health` هي `U`، ثم من عودة القيم وبقاء عدد الرسوم 9 حين تستجيب Cloudflare مجددًا. تفحص الخطوة 6 أن الإزالة تبقي الرسوم التسعة. لا يمكّن `cli/plugin_manage.php` في 1.2.25 الملحق إلا مع `--install`، لذا يستدعي الاختبار للملحق المثبت `api_plugin_enable()` كما يفعل `plugins.php`.

لا تُستدعى المساعدات المضافة بعد 1.2.25 مثل `cacti_redact_sensitive()` و`cacti_plugin_path()`. يضم الملحق آليته الخاصة لحجب البيانات الحساسة.

<a id="known-issue-poller-output-warnings-on-some-mariadb-releases"></a>
### مشكلة معروفة: تحذيرات خرج الاستطلاع على بعض إصدارات MariaDB

في MariaDB 10.11.19 و11.4.13 و11.8.9، تترك عملية `DELETE` التي ينفذها Cacti على جدول MEMORY المسمى `poller_output` بعد `SELECT` ذي الربط في `process_poller_output()` (`lib/poller.php:539` في 1.2.31) بعض الصفوف: في إعادة إنتاج حذفت صفين من ثلاثة. حذفت MariaDB 10.6.28 و10.11.10 وMySQL 8.4 الصفوف الثلاثة. في الإصدارات المتأثرة يسجل Cacti “There are N Data Sources not returning all data” (`lib/poller.php:857`) و“Poller Output Table not Empty” (`poller.php:608`، ويستدعي `admin_email()` أيضًا) في كل استطلاع لأي مصدر ذي أكثر من عنصر استطلاع، بما فيه مصادر Cacti نفسها وليس الملحق وحده. لا تتأثر قيم RRD: يُبنى التحديث قبل `DELETE` ويحذف Cacti البقايا في المرور التالي. لذلك يثبت اختبار التثبيت MariaDB 10.11.10 ويفشل عند أي من التحذيرين.

<a id="php"></a>
## PHP

يضبط `composer.json` القيمة `config.platform.php = 7.4.33` لكي تُحل أدوات التطوير لـ PHP 7.4. يعمل PHPStan مع `phpVersion: 70400`. ليس للملحق اعتماديات Composer أثناء التشغيل؛ يحمّل `includes/autoload.php` المجلد `src/` بمحمّل PSR-4 مرفق عند غياب `vendor/`، كما في أرشيف الإصدار.

يعلّم `#[\SensitiveParameter]` معاملات الرموز. يخفي PHP 8.2 وما بعده هذه الوسائط من تتبعات المكدس. يتجاهل PHP 8.0 و8.1 السمة غير المعروفة، ويقرأ PHP 7.4 السطر كتعليق. راجع [security.md](security.md#known-limitations).

<a id="database"></a>
## قاعدة البيانات

يستخدم المخطط InnoDB وutf8mb4 ومفتاحًا أجنبيًا مع `ON DELETE CASCADE` و`GET_LOCK()` للترحيلات وطوابع epoch من نوع `INT UNSIGNED` ([schema.md](../../../schema.md)). تعمل الاختبارات بوضع جلسة Cacti `sql_mode` الذي يزيل الأوضاع الصارمة.

تبلغ MariaDB وMySQL عن حالة فشل واحدة بشكل مختلف. يفشل إسقاط جدول يشير إليه جدول آخر بـ SQLSTATE `23000` والخطأ 1451 في MariaDB، وبـ SQLSTATE `HY000` والخطأ 3730 في MySQL (لوحظ في 8.0.46 و8.4.11). يقبل `MigratorTest` كليهما.

<a id="cloudflare-api"></a>
## Cloudflare API

لا يرسل الملحق إلا طلبات `GET` للقراءة إلى `https://api.cloudflare.com`. ينفذ Verify الطلبات الأربعة الأولى من واجهة الويب عندما يشغله المسؤول، وتنفذ أداة الجمع الطلبين الأخيرين.

| الطلب | الغرض | المرجع |
|---|---|---|
| `GET /client/v4/accounts/{account_id}/tokens/verify` | فحص حالة الرمز | [التحقق من الرمز](../../../research/cloudflare-api-facts.md#1-token-verification) |
| `GET /client/v4/user/tokens/verify` | بديل عند خطأ المصادقة أو الإذن من نقطة الحساب، كما يحدث لرموز المستخدمين | [التحقق الفعلي](../../../research/cloudflare-api-facts.md#live-verification-2026-10-21) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?per_page=1` | اختبار إذن قراءة الأنفاق | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/zones?account.id={account_id}&per_page=5` | اختبار إذن قراءة المناطق | [أذونات الرموز](../../../research/cloudflare-api-facts.md#3-token-permissions) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?is_deleted=false&per_page=1000&page=N` | سرد أنفاق الحساب، حتى 50 صفحة | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections` | سرد موصلات نفق واحد واتصالاته | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |

أذونات الرموز المطلوبة في [token-permissions.md](token-permissions.md).

تتبع معالجة الأخطاء الملاحظات الفعلية في ملف البحث: HTTP 400 مع رمز الخطأ 6003 أو 6111 يعني رمزًا سيئ التنسيق ويُصنف فشل مصادقة. تُعاد محاولة طلبات الويب مثل Verify مرة واحدة كحد أقصى. عند 429 تكون الإعادة بعد `retry-after` إذا لم يتجاوز ثانيتين، أو بعد التراجع المعتاد لثانية واحدة عند غياب الترويسة، ولا تحدث إذا كان التأخير أطول. تعيد أداة الجمع المحاولة مرتين كحد أقصى وتقبل `retry-after` حتى 30 ثانية؛ يؤدي التأخير الأطول إلى فشل الطلب، وتعود المهمة بعده لكن ليس قبل 60 ثانية. تجيب Cloudflare على طلب اتصالات نفق مجهول بـ HTTP 401 ورمز 1001، ويصنفه العميل `not_found` لا فشل مصادقة.
