<!-- Source: docs/compatibility.md; SHA256: 4823a4f4a82a0b8bbb36b0a9d34f89a56e61974db5b801cd9399330c7f4e1feb -->

<a id="compatibility"></a>
# संगतता

यह पृष्ठ समर्थित संस्करण, परीक्षण का दायरा और वे बाहरी इंटरफ़ेस दर्ज करता है जिन पर प्लगइन निर्भर है। Cacti के तथ्य उसके `release/1.2.25` और `release/1.2.31` tags से हैं; Cloudflare के तथ्य [`research/cloudflare-api-facts.md`](../../../research/cloudflare-api-facts.md) से हैं।

<a id="summary"></a>
## सारांश

| घटक | समर्थित | जाँचा गया |
|---|---|---|
| Cacti | 1.2.25 से 1.2.31 (INFO में `compat = 1.2.25`) | हर pull request की CI में `make smoke` से 1.2.31। 1.2.25 केवल मैनुअल `make smoke CACTI_REF=release/1.2.25` से, जिसके सभी 22 चरण अंतिम बार 2026-10-22 को पास हुए; बाद के बदलाव 1.2.25 पर नहीं जाँचे गए |
| PHP | 7.4 से 8.4 | unit परीक्षण 7.4, 8.1, 8.2, 8.3, 8.4 पर; integration 7.4 और 8.3 पर; smoke 8.0 पर; `php -l` 7.4 पर |
| डेटाबेस | MariaDB 10.6 और नए, MySQL 8.0 और नए | CI: MariaDB 10.11, MySQL 8.4। integration और BDD 2026-10-22 को MariaDB 10.6.28 और MySQL 8.0.46 पर स्थानीय रूप से भी चले; CI का भाग नहीं |
| PHP extensions | `curl`, `openssl`, `pdo_mysql` | `plugin_cloudflare_check_config()` जाँचता है |
| Cacti 1.3 | रिलीज़ लक्ष्य नहीं | परीक्षण नहीं हुआ |

<a id="cacti"></a>
## Cacti

<a id="php-floor"></a>
### PHP का न्यूनतम संस्करण

प्लगइन PHP 7.4 syntax रखता है। Cacti 1.2.25 से 1.2.30 में Composer platform check नहीं है और वे PHP 7.4 पर चलते हैं; INFO में `compat = 1.2.25` है।

Cacti 1.2.31 को PHP 8.0 या नया चाहिए। उसके `composer.json` में `"php": ">=8.0"` है और `include/vendor/composer/platform_check.php` पुराने संस्करण पर रुकता है। `include/global.php:555` Composer autoloader से यह जाँच लोड करता है। इसलिए 1.2.31 पर प्लगइन PHP 8.0 या नए पर चलता है और इंस्टॉल smoke परीक्षण `php:8.0-apache` image उपयोग करता है।

<a id="hooks-and-apis-used"></a>
### उपयोग किए hooks और API

प्लगइन Cacti की कोर फ़ाइलें नहीं बदलता। `plugin_cloudflare_install()` में पाँच hooks और तीन अनुमति क्षेत्र पंजीकृत करता है:

| इंटरफ़ेस | उपयोग | संदर्भ (1.2.31) |
|---|---|---|
| `config_arrays` hook | सक्षम होने पर Configuration में Cloudflare Accounts जोड़ता है | `include/global_arrays.php` |
| `config_settings` hook | Settings > Cloudflare टैब जोड़ता है और लंबित migrations चलाता है | `include/global_settings.php` |
| `draw_navigation_text` hook | `cloudflare_accounts.php` के लिए breadcrumb | `lib/functions.php` |
| `poller_bottom` hook | हर चक्र के बाद केवल मुख्य पोलर पर पृष्ठभूमि संग्राहक शुरू करता है | `poller.php:943`, `poller.php:956` |
| `auth_alternate_realms` hook | संग्रहीत अनुमतियाँ बदले बिना वर्तमान उपयोगकर्ता और समूह अनुमतियों से Configure > Operator > View विरासत निर्धारित करता है | `include/auth.php` |
| `exec_background()` | `poller_cloudflare.php` को `poller.php` से अलग शुरू करता है। arguments string हैं क्योंकि arrays केवल 1.2.31 से स्वीकार हैं | `lib/poller.php:132` |
| `api_plugin_register_realm()` | Cloudflare: View (`cloudflare.php`), Cloudflare: Operator (`cloudflare_operator.php`) और Cloudflare: Configure (`cloudflare_accounts.php`) | `lib/plugins.php` |
| `api_plugin_valid_entrypoint()` | पंजीकरण कॉल ऐसे फ़ंक्शन से चाहिए जिसके नाम में install, upgrade या setup हो; इसलिए पंजीकरण `plugin_cloudflare_install()` में रहता है | `lib/plugins.php:1124` |
| `api_plugin_uninstall()` | प्लगइन अनइंस्टॉल फ़ंक्शन लौटने के बाद Cacti hooks, अनुमति क्षेत्र और `plugin_config` हटाता है | `lib/plugins.php:801` |
| `api_plugin_disable_hooks()`, `api_plugin_load_realms()` | अक्षम प्लगइन के क्षेत्र लोड और `config_arrays` hook सक्रिय रहते हैं; इसलिए हर प्रवेश बिंदु `api_plugin_is_enabled()` जाँचता है | `lib/plugins.php:1103`, `lib/plugins.php:1268` |
| `include/auth.php` में अनुमति जाँच | basename से पृष्ठ का अनुमति क्षेत्र लागू करती है | `include/auth.php:183` |
| `$database_sessions` | Cacti का PDO handle दोबारा उपयोग होता है | `lib/database.php:129` |
| `db_connect_real()` | handle न होने पर Cacti के arguments से कनेक्शन खोलता है | `include/global.php:363` |

<a id="the-poller_bottom-hook-on-upgrade"></a>
### अपग्रेड पर `poller_bottom` hook

0.2.0 ने `poller_bottom` जोड़ा। `api_plugin_register_hook()` गैर-कॉन्फ़िगरेशन hook को status 0, अर्थात अक्षम, में जोड़ता है, जब तक caller सक्षम करने को न कहे (`lib/plugins.php:1030`, `$enable` branch `lib/plugins.php:1048`)। Cacti पहले से सक्षम प्लगइन फिर सक्षम नहीं करता, इसलिए 0.1.0 से अपग्रेड नया hook बंद छोड़ देता और संग्राहक कभी नहीं चलता। इसलिए 0.2.0 से पुराने इंस्टॉल के लिए `plugin_cloudflare_upgrade()` hook को प्लगइन की वर्तमान enabled स्थिति से पंजीकृत करता है। नया इंस्टॉल इसे अन्य hooks के साथ पंजीकृत करता है, और Cacti प्लगइन सक्षम करते समय सभी hooks सक्षम करता है।

Hook केवल मुख्य पोलर पर चलता है। `poller.php` दूरस्थ पोलर पर भी `poller_bottom` चलाता है (`poller.php:956`), लेकिन `poller_id` 1 न हो तो hook कुछ किए बिना लौटता है। INFO में `remote_collect:0` है।

Cacti कोर प्लगइन upgrade फ़ंक्शन कभी नहीं बुलाता (केवल `cli/audit_database.php` उसे खोजता है), इसलिए `plugin_cloudflare_upgrade()` को `plugin_cloudflare_check_config()`, `settings.php` और `plugins.php` के `config_settings` hook तथा `cloudflare_accounts.php` की शुरुआत से चलाया जाता है; अंतिम पृष्ठ को Cloudflare: Configure चाहिए। `cloudflare.php` इसे नहीं चलाता: वह Cloudflare: View में है, जबकि अपग्रेड स्कीमा migrate करता, टेम्पलेट आयात करता और `resource/script_server/cloudflare_tunnels.xml` लिखता है। प्रशासक द्वारा इन पृष्ठों में से एक खोलने तक, आवश्यक स्कीमा न होने पर viewer पृष्ठ “The Cloudflare tunnels could not be read” दिखाते हैं।

ऊपर के हर Cacti फ़ंक्शन और hook `release/1.2.25` में हैं। पृष्ठ `CactiSecureHeaders::getNonceAttribute()` केवल class मौजूद होने पर बुलाता है, क्योंकि पुराने 1.2 में वह नहीं है।

<a id="cacti-apis-and-tables-used-for-devices-graphs-and-templates"></a>
### डिवाइस, ग्राफ़ और टेम्पलेट के लिए Cacti API और तालिकाएँ

0.3.0 नीचे के फ़ंक्शन दोनों रिलीज़ के signatures के अनुसार बुलाता है। पंक्तियाँ `release/1.2.25` और `release/1.2.31` tags की हैं।

| इंटरफ़ेस | उपयोग | 1.2.25 | 1.2.31 |
|---|---|---|---|
| `api_user_realm_auth()` | `host.php` का अनुमति क्षेत्र जाँचता है | `lib/plugins.php:35` | `lib/plugins.php:35` |
| `api_device_save()` | डिवाइस बनाता है (26 arguments, `poller_id` 1) | `lib/api_device.php:939` | `lib/api_device.php:939` |
| SNMP 0 के लिए reindex विधि None | `api_device_save()` सेट करता है | `lib/api_device.php:1073-1077` | `lib/api_device.php:1073-1077` |
| `api_device_update_host_template()` | `api_device_save()` से कॉल; global default विधि से template queries फिर जोड़ता है | कॉल `:1131`, फ़ंक्शन `:1226`, `REPLACE` `:1266` | कॉल `:1131`, फ़ंक्शन `:1245`, `REPLACE` `:1285` |
| `api_device_dq_add()` | reindex विधि फिर None करता है और query चलाता है | `lib/api_device.php:552-578` | `lib/api_device.php:552-578` |
| `api_device_remove()` | बनाया लेकिन रिकॉर्ड न कर सका होस्ट हटाता है | `lib/api_device.php:45` | `lib/api_device.php:45` |
| `DATA_QUERY_AUTOINDEX_NONE` | reindex विधि None | `include/global_constants.php:75` | `include/global_constants.php:75` |
| `run_data_query()` | `$force` true के साथ reindex | `lib/data_query.php:25` | `lib/data_query.php:25` |
| `run_data_query()` में orphan और शीर्षक रीफ़्रेश | orphan चिह्न; `$force` शीर्षक रीफ़्रेश | `lib/data_query.php:317`, `:356-368` | `lib/data_query.php:317`, `:356-368` |
| `get_data_query_array()` | डेटा क्वेरी XML पढ़ता है | `lib/data_query.php:599`, पथ जाँच नहीं, `file_exists()` `:612` पर | `lib/data_query.php:599`, पथ जाँच `:612-620` |
| `cacti_path_is_within()` | `realpath()` वाली 1.2.31 पथ जाँच | मौजूद नहीं | `lib/functions.php:8058` |
| `query_script_host()` | index, query और num_indexes के लिए CLI script चलाता है | `lib/data_query.php:635`, script server prefix `:646` | `lib/data_query.php:645`, script server prefix `:656` |
| `output_delimiter` विभाजन | सूची की पंक्ति पहले `!` पर विभाजित करता है | `lib/data_query.php:738` | `lib/data_query.php:748` |
| script server `get` कॉल | `<arg_prepend>`, `get`, फ़ील्ड, इंडेक्स | `lib/utility.php:431` (इंडेक्स बिना quotes) | `lib/utility.php:544` (इंडेक्स quotes में) |
| script server include और फ़ंक्शन जाँच | `ss_cloudflare.php` कहाँ हो सकता है | base path जाँच नहीं (`script_server.php:261`, `:278`) | `script_server.php:264-290`, `:327-361` |
| `get_best_data_query_index_type()` | नए ग्राफ़ का index प्रकार | `lib/data_query.php:2285` | `lib/data_query.php:2318` |
| `import_xml_data()` | hash से टेम्पलेट आयात | `lib/import.php:25`, विफल return `:39`, `:56`, `:177-227`, `result` `fail` `:901`, `:1209`, `:1477` | `lib/import.php:25`, विफल return `:39`, `:56`, `:177-227`, `result` `fail` `:978`, `:1286`, `:1554` |
| template hash संस्करण `0103` | वितरित XML का hash prefix | `include/global_arrays.php:135-140` (1.2.20 से 1.2.25) | `include/global_arrays.php:135-146` (1.2.20 से 1.2.31) |
| `create_complete_graph_from_template()` | ग्राफ़ और डेटा स्रोत बनाता है | `lib/template.php:1480` | `lib/template.php:1480` |
| `data_source_exists()` | टनल के अगले ग्राफ़ को वही डेटा स्रोत देता है | `lib/template.php:2194`, कॉल `:1596`, पुनः उपयोग `:1615` | `lib/template.php:2194`, कॉल `:1594`, पुनः उपयोग `:1613` |
| `push_out_host()` | नया डेटा स्रोत पोलर तक भेजता है | `lib/utility.php:687` | `lib/utility.php:834` |
| `retemplate_graphs()`, `update_replication_crc()` | आयात इन्हें बुलाता है इसलिए लोड हैं | `lib/template.php:873`, `lib/utility.php:29` | `lib/template.php:873`, `lib/utility.php:29` |
| `host.php` includes | डिवाइस सहेजने से पहले लोड लाइब्रेरी | `host.php:26-39` | `host.php:26-39` |
| `cli/add_device.php`, `cli/add_graphs.php` | डिवाइस arguments और मौजूदा ग्राफ़ अपनाने का नमूना | `add_device.php:199`, `add_graphs.php:659-716` | `add_device.php:199`, `add_graphs.php:659-716` |
| `host.php` का अनुमति क्षेत्र | क्षेत्र 3, Sites/Devices/Data | `include/global_arrays.php:1227`, `:1285` | `include/global_arrays.php:1240`, `:1298` |
| `read_config_option()`, `set_config_option()` | template hash और गुम होस्ट सेटिंग | `lib/functions.php:679`, `:419` | `lib/functions.php:680`, `:418` |
| `db_fetch_cell_prepared()` | Cacti तालिकाएँ पढ़ता है | `lib/database.php:568` | `lib/database.php:665` |
| `raise_message()`, `is_error_message()` | डिवाइस निर्माण और `api_device_save()` के परिणाम | `lib/functions.php:993`, `:890` | `lib/functions.php:996`, `:893` |

Cacti स्कीमा की पढ़ी या लिखी जाने वाली तालिकाएँ, `cacti.sql` में उनकी `CREATE TABLE` पंक्ति (1.2.25 / 1.2.31) सहित। पठन prepared statements से है; लेखन ऊपर के Cacti फ़ंक्शन से होता है।

| तालिका | उपयोग | पंक्तियाँ |
|---|---|---|
| `host` | डिवाइस मौजूदगी और `deleted` चिह्न | 1876 / 1877 |
| `host_snmp_query` | reindex विधि, `api_device_dq_add()` द्वारा लिखी | 1986 / 1987 |
| `host_snmp_cache` | अंतिम reindex के indexes, ग्राफ़ बनने से पहले जाँचे जाते हैं | 1959 / 1960 |
| `graph_local` | टनल और template का मौजूदा ग्राफ़ खोजता है | 1601 / 1602 |
| `graph_templates_item`, `data_template_rrd` | मौजूदा ग्राफ़ का डेटा स्रोत खोजते हैं | 1778 / 1779, 1557 / 1558 |
| `data_source_profiles` | आयात के लिए डिफ़ॉल्ट प्रोफ़ाइल | 1304 / 1305 |
| `settings` | `cloudflare_templates_sha256` और `cloudflare_missing_host_<account id>`, `set_config_option()` के माध्यम से | 2411 / 2412 |
| `host_template`, `snmp_query`, `data_template`, `graph_templates`, `snmp_query_graph` | hash से आयातित ऑब्जेक्ट हल करते हैं | 2003 / 2004, 2471 / 2471, 1510 / 1511, 1656 / 1657, 2491 / 2491 |

रिलीज़ों में पंक्ति संख्या अलग होने के कारण दोनों दिए गए हैं। हर फ़ंक्शन दोनों में है। व्यवहार में अंतर डेटा क्वेरी पथ जाँच, script server base path जाँच और `get` कॉल के index quotes में है।

<a id="the-1231-resource-path-rule"></a>
### 1.2.31 का resource-path नियम

1.2.31 से `get_data_query_array()` ऐसा XML अस्वीकार करता है जिसका `realpath()` `<path_cacti>/resource` के भीतर न हो (`lib/data_query.php:612-620`, `lib/functions.php:8058`)। 1.2.25 कोई भी मौजूद फ़ाइल पढ़ता है। प्लगइन दोनों में XML को `<base_path>/resource/script_server/cloudflare_tunnels.xml` पर रखता है, इसलिए एक template XML दोनों में चलता है। `resource/` से प्लगइन डायरेक्टरी तक symbolic link 1.2.31 जाँच में विफल है, इसलिए फ़ाइल कॉपी होती है, link नहीं। पोलर script पथ प्लगइन डायरेक्टरी में रहता है, जिसे 1.2.31 script server स्वीकार करता है क्योंकि वह `base_path` के भीतर है।

<a id="the-smoke-on-1225"></a>
### 1.2.25 पर smoke परीक्षण

`make smoke CACTI_REF=release/1.2.25` उसी PHP 8.0 image को 1.2.25 tag से बनाता है, नाम `cf-smoke-cacti:1.2.25` देता है और डिवाइस, ग्राफ़ निर्माण तथा RRD जाँच सहित हर smoke चरण चलाता है। 22 चरण हैं (`tests/docker/smoke.sh`)। 2026-10-22 को MariaDB 10.11.10 के साथ 1.2.31 और 1.2.25 पर सभी 22 पास हुए। CI केवल 1.2.31 checkout करती है; 1.2.25 परिणाम उस तारीख के कोड तक सीमित है और बाद के बदलावों पर नहीं दोहराया गया।

डिवाइस और ग्राफ़ चरण (5f से 5j) HTTP से डिवाइस बनाते हैं, एक देय पोल चलाते हैं जो reindex करके तीन परीक्षण टनलों के 9 ग्राफ़ बनाता है, और blog के RRD में `connectors` 1, `connections` 1, `health` 3 जाँचते हैं। Cloudflare 503 पर `connectors`, `connections` और `health` को `U` जाँचते हैं; उत्तर लौटने पर मान वापस आएँ और ग्राफ़ 9 ही रहें, यह जाँचते हैं। चरण 6 अनइंस्टॉल के बाद 9 ग्राफ़ बचे होना जाँचता है। 1.2.25 का `cli/plugin_manage.php` केवल `--install` के साथ सक्षम करता है, इसलिए पहले से इंस्टॉल प्लगइन के लिए smoke, `plugins.php` की तरह `api_plugin_enable()` बुलाता है।

1.2.25 के बाद आए helpers, जैसे `cacti_redact_sensitive()` और `cacti_plugin_path()`, नहीं बुलाए जाते। प्लगइन संवेदनशील डेटा छिपाने की अपनी व्यवस्था रखता है।

<a id="known-issue-poller-output-warnings-on-some-mariadb-releases"></a>
### ज्ञात समस्या: कुछ MariaDB संस्करणों पर पोलर आउटपुट चेतावनियाँ

MariaDB 10.11.19, 11.4.13 और 11.8.9 में `process_poller_output()` के join `SELECT` के बाद Cacti का MEMORY तालिका `poller_output` पर `DELETE` (1.2.31 में `lib/poller.php:539`) पंक्तियाँ छोड़ देता है: पुनरुत्पादन में 3 में से 2 हटाईं। MariaDB 10.6.28, 10.11.10 और MySQL 8.4 ने तीनों हटाईं। प्रभावित संस्करणों में हर पोल पर एक से अधिक poller item वाले डेटा स्रोतों के लिए, Cacti के अपने स्रोतों सहित, “There are N Data Sources not returning all data” (`lib/poller.php:857`) और “Poller Output Table not Empty” (`poller.php:608`, जो `admin_email()` भी बुलाता है) लॉग होते हैं। RRD मान प्रभावित नहीं होते: update `DELETE` से पहले बनता है और बची पंक्तियाँ अगली बार हटती हैं। इसलिए इंस्टॉल smoke MariaDB 10.11.10 निश्चित रखता है और किसी भी चेतावनी पर विफल होता है।

<a id="php"></a>
## PHP

`composer.json` में `config.platform.php = 7.4.33` है ताकि dev tools PHP 7.4 के लिए resolve हों। PHPStan `phpVersion: 70400` पर चलता है। runtime Composer dependency नहीं है; `vendor/` न होने पर, जैसा release archive में है, `includes/autoload.php` साथ दिए PSR-4 loader से `src/` लोड करता है।

`#[\SensitiveParameter]` टोकन parameters चिह्नित करता है। PHP 8.2 और नए इन्हें stack trace से छिपाते हैं। PHP 8.0 और 8.1 अज्ञात attribute अनदेखा करते हैं और PHP 7.4 पंक्ति को comment मानता है। [security.md](security.md#known-limitations) देखें।

<a id="database"></a>
## डेटाबेस

स्कीमा InnoDB, utf8mb4, `ON DELETE CASCADE` वाला foreign key, migrations के लिए `GET_LOCK()` और `INT UNSIGNED` epoch timestamps उपयोग करता है ([schema.md](../../../schema.md))। परीक्षण Cacti का session `sql_mode` उपयोग करते हैं, जिसमें strict modes हटे हैं।

एक विफलता MariaDB और MySQL अलग बताते हैं। दूसरी तालिका द्वारा referenced तालिका हटाने पर MariaDB SQLSTATE `23000` और त्रुटि 1451 देता है; MySQL SQLSTATE `HY000` और त्रुटि 3730 देता है (8.0.46 और 8.4.11 में देखा गया)। `MigratorTest` दोनों स्वीकार करता है।

<a id="cloudflare-api"></a>
## Cloudflare API

प्लगइन केवल `https://api.cloudflare.com` पर पढ़ने के `GET` अनुरोध करता है। प्रशासक द्वारा वेब इंटरफ़ेस से Verify चलाने पर नीचे के पहले चार अनुरोध होते हैं; अंतिम दो संग्राहक करता है।

| अनुरोध | उद्देश्य | संदर्भ |
|---|---|---|
| `GET /client/v4/accounts/{account_id}/tokens/verify` | टोकन स्थिति जाँचना | [टोकन सत्यापन](../../../research/cloudflare-api-facts.md#1-token-verification) |
| `GET /client/v4/user/tokens/verify` | खाता endpoint पर auth या permission त्रुटि का fallback, जैसा user-owned टोकन पर होता है | [प्रत्यक्ष सत्यापन](../../../research/cloudflare-api-facts.md#live-verification-2026-10-21) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?per_page=1` | टनल पढ़ने की अनुमति जाँचना | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/zones?account.id={account_id}&per_page=5` | ज़ोन पढ़ने की अनुमति जाँचना | [टोकन अनुमतियाँ](../../../research/cloudflare-api-facts.md#3-token-permissions) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?is_deleted=false&per_page=1000&page=N` | खाते के टनल सूचीबद्ध करना, अधिकतम 50 पृष्ठ | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections` | एक टनल के कनेक्टर और कनेक्शन सूचीबद्ध करना | [Cloudflare Tunnel](../../../research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |

आवश्यक अनुमतियाँ [token-permissions.md](token-permissions.md) में हैं।

त्रुटि प्रबंधन शोध फ़ाइल के प्रत्यक्ष अवलोकनों पर आधारित है: HTTP 400 के साथ 6003 या 6111 malformed टोकन है और authentication failure माना जाता है। Verify जैसे वेब अनुरोध अधिकतम एक बार पुनः प्रयास करते हैं। 429 पर `retry-after` 2 सेकंड या कम हो तो उतनी देर बाद, header न हो तो सामान्य 1 सेकंड backoff बाद पुनः प्रयास होता है; अधिक विलंब पर नहीं। संग्राहक अधिकतम दो बार पुनः प्रयास और 30 सेकंड तक `retry-after` स्वीकार करता है; अधिक विलंब अनुरोध विफल करता है और कार्य उस विलंब के बाद फिर चलता है, लेकिन 60 सेकंड से पहले नहीं। अज्ञात टनल के connections अनुरोध पर Cloudflare HTTP 401 और code 1001 देता है, जिसे client `not_found` मानता है, authentication failure नहीं।
