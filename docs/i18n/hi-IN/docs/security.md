<!-- Source: docs/security.md; SHA256: 686c0b06d983411f2dfeb405c08f6eea0b8a6f983bb25760d3de0dcf05f96d83 -->

<a id="security"></a>
# सुरक्षा

यह पृष्ठ पड़ाव 3 तक लागू सुरक्षा नियंत्रणों और प्रत्येक को लागू करने वाले कोड का वर्णन करता है। कमजोरी की रिपोर्ट के लिए [SECURITY.md](../../../../SECURITY.md) देखें।

<a id="outbound-requests"></a>
## बाहर जाने वाले अनुरोध

केवल `src/Http/CurlTransport.php` नेटवर्क कनेक्शन खोलता है। `curl_init()` बुलाने से पहले `OutboundPolicy::assertAllowed()` URL को मूल सर्वरों की अनुमति सूची से जाँचता है। एक constant से `OutboundPolicy::production()` द्वारा बनी उत्पादन सूची में ठीक `https://api.cloudflare.com`, पोर्ट 443 है। कोई सेटिंग इसे नहीं बदल सकती।

नीति scheme, host और port की तुलना करती है और ऐसा URL अस्वीकार करती है जिस पर PHP का `parse_url()` और curl parser अलग अर्थ निकाल सकते हों: user info, `@`, backslash, fragment, whitespace, control byte, गैर-ASCII byte और ऐसे port जो केवल सामान्य अंक न हों। अस्वीकृत URL के लिए न नाम resolve होता है न socket खुलता है; URL exception में भी कॉपी नहीं होता।

नीति तक पहुँचने से पहले `CloudflareClient` URL में जाने वाले हिस्से जाँचता है। खाता ID को `^[0-9a-f]{32}$` से मेल खाना चाहिए; अनुरोध पथ को `^/[A-Za-z0-9/_.-]*$` से मेल खाना चाहिए और उसमें `..` नहीं होना चाहिए।

हर अनुरोध में ये सेट होते हैं:

- `CURLOPT_PROTOCOLS` केवल HTTPS
- `CURLOPT_FOLLOWLOCATION` बंद और `CURLOPT_MAXREDIRS` 0
- `CURLOPT_SSL_VERIFYPEER` चालू और `CURLOPT_SSL_VERIFYHOST` 2
- कनेक्शन टाइमआउट 5 सेकंड है; कुल टाइमआउट वेब इंटरफ़ेस अनुरोधों के लिए “Interactive Request Timeout” (डिफ़ॉल्ट 10 सेकंड) और पृष्ठभूमि संग्रह के लिए “Collector Request Timeout” (डिफ़ॉल्ट 20 सेकंड) से आता है। दोनों सेटिंग 5 से 60 सेकंड स्वीकार करती हैं
- `CURLOPT_NOPROXY` का मान `*`, जिससे proxy environment variables अनदेखे होते हैं
- response सीमा 5 MiB; बड़ा body transfer रोक देता है

`tests/Unit/Http/CurlTransportOptionsTest.php` इन विकल्पों को सत्यापित करता है। `tests/Integration/Http/CurlTransportTest.php` वास्तविक transport को स्थानीय सर्वरों के साथ चलाकर जाँचता है कि redirects का पालन नहीं होता, self-signed certificate अस्वीकार होता है, बहुत बड़ा body अस्वीकार होता है, environment का proxy अनदेखा होता है और अनुमति सूची से बाहर का URL कभी कनेक्ट नहीं करता।

<a id="api-tokens"></a>
## API टोकन

केवल सीमित दायरे वाले API टोकन स्वीकार होते हैं। `TokenPolicy::validate()` 37 अक्षरों वाली छोटे अक्षरों की hex string को Global API Key मानकर अलग संदेश के साथ अस्वीकार करता है; अन्यथा `^[A-Za-z0-9_-]{40,256}$` आवश्यक है। नीति टोकन सहेजते समय और फिर हर बार लोड करते समय चलती है।

हर खाता दो स्थानों में से एक में टोकन रखता है।

<a id="token-file"></a>
### टोकन फ़ाइल

खाते में ऐसी फ़ाइल का पूर्ण पथ रहता है जिसमें केवल टोकन हो। `TokenFile::read()` फ़ाइल अस्वीकार करता है जब तक:

- पथ पूर्ण हो और symbolic link न हो
- फ़ाइल सामान्य फ़ाइल हो, अधिकतम 4096 बाइट; जाँच खुले handle पर `fstat()` से हो ताकि वास्तव में पढ़ी फ़ाइल ही जाँची जाए
- अन्य उपयोगकर्ता उसे पढ़ या लिख न सकें (`mode & 0006` शून्य हो)

ये जाँचें Verify में टोकन पढ़ते समय चलती हैं, खाता सहेजते समय नहीं। वेब सर्वर और पोलर उपयोगकर्ता अलग हो सकते हैं, इसलिए वेब सर्वर की save-time जाँच गलत उपयोगकर्ता के लिए पास या फेल हो सकती है। जाँच विफल होने पर Verify “Token file is missing or insecure” दर्ज करता है।

पथ चुनना Cloudflare: Configure क्षेत्र रखने वाले व्यक्ति का विश्वास संबंधी निर्णय है। Verify और संग्राहक उसे क्रमशः वेब सर्वर और पोलर उपयोगकर्ता के रूप में खोलते हैं; पोलर उपयोगकर्ता कभी-कभी root होता है। इसलिए Configure उपयोगकर्ता खाते को ऐसी किसी भी फ़ाइल की ओर निर्देशित कर सकता है जिसे वे उपयोगकर्ता पढ़ सकें। यदि फ़ाइल ऊपर की जाँच पास करे और किनारे की whitespace हटाने के बाद सामग्री टोकन pattern से मिले, तो प्लगइन उस सामग्री को Bearer token के रूप में केवल सत्यापित TLS पर `https://api.cloudflare.com` को भेजता है, क्योंकि बाहरी अनुमति सूची बदली नहीं जा सकती। सामग्री ब्राउज़र या लॉग में कभी नहीं जाती। Verify यह दिखाता है कि फ़ाइल पठनीय और टोकन जैसी थी या नहीं (“Token file is missing or insecure” के बजाय Cloudflare का उत्तर), और लॉग में reason code रहता है, इसलिए Configure उपयोगकर्ता ऐसी फ़ाइल के मौजूद होने का पता लगा सकता है। Configure केवल उन उपयोगकर्ताओं को दें जिन्हें पोलर उपयोगकर्ता की पठनीय फ़ाइलों के लिए विश्वसनीय मानते हैं।

इसे सीमित करने के लिए Settings > Cloudflare > Token File Directory सेट करें (डिफ़ॉल्ट खाली, जिससे कोई भी पथ स्वीकार है)। तब `TokenFile::read()` किसी अन्य filesystem जाँच से पहले `file_outside_directory` कारण से ऐसा पथ अस्वीकार करता है जिसका parent directory उस डायरेक्टरी के भीतर resolve न हो, जिसमें `.` या `..` खंड हों, या जब निर्धारित डायरेक्टरी स्वयं resolve न हो। parent को `realpath()` से resolve किया जाता है, इसलिए डायरेक्टरी का symbolic link बाहर नहीं ले जा सकता। सेटिंग Cacti के Settings पृष्ठ पर है, जिसे Settings/Utilities और Cloudflare: Configure दोनों क्षेत्र चाहिए; इससे Settings/Utilities न रखने वाले Configure उपयोगकर्ता की पहुँच सीमित होती है। डायरेक्टरी के भीतर लिख सकने या उसके किसी parent को बदल सकने वाला उपयोगकर्ता अभी भी पढ़ी जाने वाली सामग्री बदल सकता है।

<a id="encrypted-in-the-cacti-database-vault"></a>
### Cacti डेटाबेस में एन्क्रिप्टेड (vault)

`src/Secret/TokenVault.php`, `openssl_encrypt()` के माध्यम से AES-256-GCM उपयोग करता है, साथ में यादृच्छिक 12-बाइट nonce और 16-बाइट tag। अतिरिक्त प्रमाणित डेटा `plugin_cloudflare:account:<account ID>` है, इसलिए दूसरी खाता पंक्ति में कॉपी किया ciphertext डिक्रिप्ट नहीं होता। संग्रहीत मान `v1:` के बाद nonce, tag और ciphertext का base64 है। खाता ID बदलने पर टोकन फिर दर्ज करना पड़ता है।

32-बाइट कुंजी Settings > Cloudflare > Token Vault Key File में बताए पथ से पढ़ी जाती है (डिफ़ॉल्ट `/etc/cacti/cloudflare.key`)। प्लगइन इस फ़ाइल को कभी बनाता या लिखता नहीं। `TokenVault::fromKeyFile()` केवल तब स्वीकार करता है जब:

- पथ पूर्ण हो और symbolic link न हो
- यह सामान्य फ़ाइल हो जिसे अन्य उपयोगकर्ता पढ़ या लिख न सकें
- इसमें ठीक 32 raw बाइट या 64 hex अक्षर हों, अंत में अधिकतम एक newline; 32 hex अक्षर, अर्थात 16-बाइट कुंजी, अस्वीकार हैं

फ़ाइल गायब या अस्वीकार हो तो vault बंद रहता है और फ़ॉर्म केवल टोकन फ़ाइल स्रोत देता है। मौजूद लेकिन अस्वीकृत फ़ाइल प्रति request केवल एक बार, सिर्फ reason code के साथ लॉग होती है। कुंजी object properties के बाहर रखी जाती है, इसलिए `var_export()`, `(array)` cast और `get_object_vars()` उसे उजागर नहीं कर सकते। object serialization अस्वीकार करता है और `var_dump()`, `__debugInfo()` से `[REDACTED]` छापता है।

अकेला database dump vault टोकन उजागर नहीं करता। डेटाबेस और कुंजी फ़ाइल दोनों पढ़ सकने वाला आक्रमणकारी, जैसे वेब सर्वर उपयोगकर्ता के रूप में, उन्हें डिक्रिप्ट कर सकता है।

<a id="handling-in-the-interface"></a>
### इंटरफ़ेस में प्रबंधन

संपादन फ़ॉर्म सहेजा टोकन ब्राउज़र को कभी नहीं लौटाता। टोकन फ़ील्ड हमेशा खाली रहते हैं; सहेजते समय खाली छोड़ने पर मौजूदा टोकन बना रहता है। सूची केवल टोकन सहेजा होने की जानकारी दिखाती है। संपादन पृष्ठ स्रोत और फ़ाइल पथ `AccountService::credentialDetails()` से पढ़ता है, जो वे विवरण और टोकन मौजूद है या नहीं लौटाता है, कभी टोकन या ciphertext नहीं; इसे configure अधिकार चाहिए। validation विफल फ़ॉर्म अन्य फ़ील्ड रखकर फिर दिखता है, लेकिन टोकन session में कभी नहीं लिखा जाता।

<a id="redaction-and-logging"></a>
## संवेदनशील जानकारी छिपाना और लॉगिंग

खाता स्थिति संदेश निश्चित वाक्य हैं, जैसे “Cloudflare API is unreachable; try again later”। Cloudflare error response का पाठ उनमें कभी कॉपी नहीं होता। स्थिति बदलाव Cacti लॉग में खाता row ID, Cloudflare account ID, पुरानी और नई स्थिति तथा error class या reason code के साथ दर्ज होते हैं, टोकन कभी नहीं।

`Support\Redactor` ज्ञात secrets को, सबसे लंबे से शुरू करके, और किसी भी `Authorization: Bearer` मान को `[REDACTED]` से बदलता है। client इसे Cloudflare error messages और curl error text पर exception में डालने से पहले चलाता है। curl error text से control bytes भी हटते हैं और पाठ 200 अक्षरों तक सीमित होता है।

टोकन संभालने वाले परीक्षण जाँचते हैं कि वह लॉग, exception संदेश या output में न दिखे। इंस्टॉल smoke अपने उपयोग किए टोकन को Cacti लॉग, Apache लॉग, हर सहेजे HTTP response और संपूर्ण database dump में खोजता है।

<a id="database-errors"></a>
## डेटाबेस त्रुटियाँ

हर query bound values वाली prepared statement है और identifiers कभी input से नहीं बनते। विफल statement `DatabaseException` फेंकता है, जिसका संदेश ठीक `SQLSTATE[<state>] driver error <code>` होता है। driver text हटाया जाता है क्योंकि MySQL उसमें statement text दोहराता है, और emulated prepares में वह bound values भी रखता है। पृष्ठ वही संदेश लॉग करता है और निश्चित त्रुटि दिखाता है: save और bulk actions पर Cacti की सामान्य save त्रुटि, तथा list, edit और confirmation पृष्ठों पर “The Cloudflare accounts could not be read”। कोई read path exception को बाहर नहीं जाने देता क्योंकि Cacti का shutdown handler ऐसे exception से पृष्ठ बंद होने पर प्लगइन अक्षम करता है।

<a id="access-control"></a>
## पहुँच नियंत्रण

| अनुमति क्षेत्र | पृष्ठ | डिफ़ॉल्ट |
|---|---|---|
| Cloudflare: Configure | `cloudflare_accounts.php` | इंस्टॉल पर admin को दिया जाता है |
| Cloudflare: View | `cloudflare.php` | इंस्टॉल पर नहीं दिया जाता |
| Cloudflare: Operator | `cloudflare_operator.php` | स्पष्ट अनुमति आवश्यक |
| Sites/Devices/Data (`host.php` के लिए Cacti क्षेत्र) | Create Cacti Device और Create Graphs चालू करना | Cacti की अपनी अनुमति |

Configure को Operator और View पहुँच विरासत में मिलती है, और Operator को View, हर request पर वर्तमान उपयोगकर्ता और समूह अनुमतियों के आधार पर। संग्रहीत अनुमतियाँ नहीं बदलतीं। Operations की दृश्यता View जैसी पूरे खाते की है; यह प्रति-डिवाइस फ़िल्टर नहीं जोड़ता। स्वीकृतियों को POST, मान्य CSRF टोकन और सर्वर द्वारा दोबारा लोड किया गया ताज़ा अवलोकन चाहिए जिसका fingerprint मेल खाए। वे वर्तमान session की व्यक्तिगत स्वीकृतियाँ हैं, अधिकतम एक घंटे तक रहती हैं, अवलोकन बदलने पर समाप्त होती हैं और कभी स्वास्थ्य नहीं बदलतीं या अलर्ट नहीं दबातीं।

`include/auth.php` पृष्ठ नाम से अनुमति क्षेत्र लागू करता है। `AccountService` हर बदलाव, हर Verify और हर credential lookup से पहले `api_user_realm_auth('cloudflare_accounts.php')` द्वारा फिर जाँचता है और अन्यथा `AccessDenied` फेंकता है। इंस्टॉल smoke जाँचता है कि केवल Cloudflare: View वाला उपयोगकर्ता accounts पृष्ठ नहीं खोल सकता। BDD जाँचते हैं कि configure अधिकार बिना उपयोगकर्ता न खाता बदल सकता है, न `credentialDetails()` बुला सकता है। viewer पृष्ठ (`cloudflare.php`) केवल पढ़ते हैं। उन्हें Cloudflare: View चाहिए, जिसे Cacti पृष्ठ नाम से लागू करता है; वे कोई POST action स्वीकार नहीं करते। टनल और कनेक्टर पाठ Cloudflare से आता है, इसलिए हर मान `html_escape()` से छपता है। Tunnels filter और sort मान query तक पहुँचने से पहले निश्चित सूचियों से जाँचे जाते हैं।

डिवाइस और ग्राफ़ actions को दूसरा अनुमति क्षेत्र चाहिए। Cacti डिवाइस `host.php` object है, इसलिए केवल Configure से बनना नहीं चाहिए। Create Cacti Device और Create Graphs चालू करने, दोनों को `cloudflare_accounts.php` और `api_user_realm_auth('host.php')` चाहिए (`includes/cacti_objects.php:34`)। दोनों अनुमतियों बिना dropdown action छिपाता है (`includes/accounts_page.php:55-62`), handler किसी Cacti कॉल से पहले फिर request अस्वीकार करता है (`:234-243`), और `cloudflare_create_device()` तीसरी बार जाँचता है (`includes/cacti_objects.php:47`)। `host.php` क्षेत्र बिना उपयोगकर्ता Create Graphs चालू करके फ़ॉर्म सहेजे तो फ़ील्ड छोड़ दी जाती है और संग्रहीत मान बना रहता है; सेटिंग पहले बंद थी तो चेतावनी दिखती है (`includes/accounts_page.php:193-209`)। बंद करने को केवल Configure चाहिए। संग्राहक बिना logged-in उपयोगकर्ता ग्राफ़ बनाता है, इसलिए क्षेत्र जाँच सेटिंग सहेजते समय होती है।

`list()` बिना guard रहता है क्योंकि उसका `Account` read model टोकन, ciphertext, token source या file path नहीं रखता; integration परीक्षण जाँचता है कि सूची के dump में इनमें से कुछ नहीं है।

Save और bulk actions (Delete, Verify, Enable, Disable) केवल POST स्वीकार करते हैं; अन्य method वापस सूची पर redirect होता है। POST body Cacti के csrf-magic से जाँची जाती है। bulk actions चुने IDs `sanitize_unserialize_selected_items()` से पढ़ते हैं। संख्यात्मक request मान `get_filter_request_var()` से पढ़े जाते हैं; text fields को कुछ भी सहेजने से पहले `AccountService` जाँचता है। पृष्ठ में छपने वाला हर मान `html_escape()` या `__esc()` से जाता है। पृष्ठ का script स्थिर फ़ाइल `js/cloudflare_accounts.js` है, जिसे Cacti उपलब्ध कराए तो उसके CSP nonce से लोड किया जाता है; प्लगइन कोई inline script नहीं जोड़ता।

<a id="collector"></a>
## संग्राहक

टनल खोज अलग PHP प्रक्रिया `poller_cloudflare.php` में चलती है।

- **केवल CLI।** `php_sapi_name()` का मान `cli` न हो तो script 404 देकर बंद हो जाता है, इसलिए HTTP से नहीं बुलाया जा सकता।
- **आरंभ।** `cloudflare_poller_bottom()` इसे `poller_bottom` hook से `exec_background()` के माध्यम से केवल मुख्य पोलर पर शुरू करता है। PHP binary Cacti की `path_php_binary` सेटिंग है और script पथ escape किया जाता है; request data कमांड लाइन तक नहीं पहुँचता।
- **एक समय में एक प्रक्रिया।** संग्राहक `GET_LOCK('plugin_cloudflare_collector', 0)` लेता है। timeout 0 होने से दूसरा संग्राहक तुरंत निकलता है और कनेक्शन बंद होने पर MySQL lock छोड़ता है। हर job row में lease भी है, और खाते की हर write उसी lease की जाँच के साथ एक transaction में होती है।
- **सीमित रन।** 50 सेकंड बाद नया खाता शुरू नहीं होता और प्रति रन अधिकतम 200 कनेक्शन अनुरोध होते हैं। कोई भी सीमा खाते को कनेक्शन पढ़ते बीच में रोक सकती है, जिसे `partial` दर्ज किया जाता है। हर request का connect timeout 5 सेकंड और Collector Request Timeout (डिफ़ॉल्ट 20 सेकंड) है; धीमी Cloudflare API अगले समय-जाँच बिंदु पर रन समाप्त करती है और पोलर चक्र कभी नहीं रोकती।
- **अक्षम स्थिति जाँचना।** हर खाते से पहले सीधे `plugin_config.status` पढ़ा जाता है। `api_plugin_is_enabled()` प्रक्रिया के लिए उत्तर cache करता है, इसलिए रन के बीच अक्षम किया प्लगइन उसे पता नहीं चलेगा। विफल पठन अक्षम माना जाता है।
- **क्रेडेंशियल।** हर खाते का टोकन Verify जैसी फ़ाइल या vault से, उन्हीं जाँचों के साथ मिलता है और sodium लोड हो तो उपयोग के बाद मिटता है। न पढ़ा जा सकने वाला टोकन केवल reason code के रूप में लॉग होता है।
- **लॉगिंग।** Cloudflare client से बाहर का दोष केवल exception class name से लॉग होता है, क्योंकि संदेश या trace में Cloudflare पाठ या टोकन हो सकता है। रन पंक्तियों में संख्यात्मक खाता row ID, error class और counts Cacti के medium verbosity पर रहते हैं।

<a id="cacti-devices-graphs-and-templates"></a>
## Cacti डिवाइस, ग्राफ़ और टेम्पलेट

- **Hostname।** खाते के डिवाइस को `<cf_account_id>.cloudflare.invalid` hostname मिलता है (`includes/cacti_objects.php:107`)। `.invalid` आरक्षित है और कभी resolve नहीं होता, SNMP संस्करण 0 और availability method none है, इसलिए इस डिवाइस के लिए Cacti कहीं कनेक्ट नहीं करता। डिवाइस में टोकन या credential नहीं होता।
- **प्लगइन डायरेक्टरी से बाहर एक निश्चित write।** प्लगइन अपना `templates/resource/cloudflare_tunnels.xml` को `<base_path>/resource/script_server/cloudflare_tunnels.xml` में कॉपी करता है, क्योंकि Cacti 1.2.31 query XML केवल `resource/` से पढ़ता है (`includes/templates.php:46-52`)। स्रोत और लक्ष्य input से नहीं आते। फ़ंक्शन लक्ष्य के पास temporary file `fopen()` mode `xb` से लिखता है, जो मौजूद नाम पर विफल होता है; इसलिए temporary नाम पर रखे link का अनुसरण नहीं होता। mode 0644 करके फ़ाइल को लक्ष्य पर rename करता है (`includes/templates.php:140-178`)। लक्ष्य का symbolic link बदल दिया जाता है, क्योंकि `rename()` link को ही बदलता है, उसके पार नहीं लिखता। कॉपी केवल तब छूटती है जब लक्ष्य समान सामग्री वाला सामान्य फ़ाइल हो (`:151`)। विफलता लॉग होकर false लौटाती है, exception नहीं फेंकती, क्योंकि install exception पर Cacti प्लगइन अक्षम करता है। प्लगइन डायरेक्टरी से बाहर और कुछ नहीं लिखा जाता; uninstall यह फ़ाइल रखता है।
- **अधूरा बना डिवाइस।** `api_device_save()` सफल हो लेकिन प्लगइन डिवाइस रिकॉर्ड न कर सके तो `api_device_remove()` से वह host हटाकर host table फिर पढ़ता है। host ID वही है जो उसी request को अभी मिला था, request या database से लिया मान नहीं। host अभी भी हो तो लॉग ID के साथ बताता है और प्रशासक Devices में हटाता है (`includes/cacti_objects.php:146-158`, `:172-188`)।
- **Locks।** `GET_LOCK('plugin_cloudflare_device_<account id>')` उसी खाते के दूसरे Create Cacti Device को इंतज़ार कराकर बने डिवाइस तक पहुँचाता है, इसलिए खाते को दो डिवाइस नहीं मिलते। `finally` में lock छोड़ते हैं और छोड़ना विफल हो तो परिणाम बदले बिना लॉग करते हैं (`includes/cacti_objects.php:65-82`)। `GET_LOCK('plugin_cloudflare_templates')` दो upgrade प्रक्रियाओं को साथ templates आयात करने से रोकता है (`includes/templates.php:79-94`)। कनेक्शन बंद होने पर MySQL दोनों छोड़ता है।
- **टेम्पलेट आयात।** `import_xml_data()` विफलता exceptions से नहीं, return values और `$import_messages` से बताता है; इसलिए प्लगइन दोनों जाँचता है और फिर हर object hash से resolve हो, यह जाँचता है (`includes/templates.php:188-251`)। असफल आयात केवल stage name से लॉग होता है।
- **Script server।** `ss_cloudflare.php` प्लगइन तालिकाएँ पढ़ता है और नेटवर्क कॉल नहीं करता। device ID केवल `^[1-9][0-9]{0,9}\z` और tunnel index केवल छोटे अक्षरों का UUID स्वीकार करता है, दोनों prepared statements में bind करता है और विफलता केवल exception class name से लॉग करता है (`includes/script_server.php:38-84`)।

<a id="disabled-plugin"></a>
## अक्षम प्लगइन

Cacti अक्षम प्लगइन के अनुमति क्षेत्र लोड और `config_arrays` hook सक्रिय रखता है। इसलिए प्लगइन स्वयं `api_plugin_is_enabled()` जाँचता है। अक्षम होने पर मेनू और breadcrumbs छिपते हैं; `cloudflare_accounts.php`, `cloudflare_operator.php` और `cloudflare.php` Cacti का permission-denied पृष्ठ देते हैं। संग्राहक स्वयं `plugin_config.status` जाँचकर काम किए बिना निकलता है। Settings > Cloudflare टैब दिखता रहता है ताकि सक्षम करने से पहले कुंजी पथ सेट कर सकें। इंस्टॉल smoke अस्वीकृति और दोबारा सक्षम करने के बाद सुधार जाँचता है।

<a id="test-stack"></a>
## परीक्षण वातावरण

इंस्टॉल smoke Cacti को नकली Cloudflare API के साथ चलाता है। Docker network के भीतर नकली API `api.cloudflare.com` के रूप में उत्तर देती है और container शुरू होते समय बनी CA से signed certificate उपयोग करती है। CA दो दिन मान्य है और name constraint द्वारा केवल `api.cloudflare.com` तक सीमित है। केवल Cacti web container उसे अपने system certificate store में इंस्टॉल करता है। smoke जाँचता है कि gateway container उस पर भरोसा नहीं करता और कोई भी अन्य नाम अस्वीकार होता है।

प्लगइन में CA सेटिंग या origin override नहीं है। `CurlTransport` कोई CA option सेट नहीं करता, इसलिए उत्पादन में OS का certificate store उपयोग होता है; `OutboundPolicy::production()` केवल `https://api.cloudflare.com` स्वीकार करता है। परीक्षण CA केवल smoke वातावरण में है।

<a id="uninstall"></a>
## अनइंस्टॉल

अनइंस्टॉल प्लगइन तालिकाएँ, संग्रहीत ciphertext सहित, और `cloudflare_` सेटिंग हटाता है। कुंजी या टोकन फ़ाइल नहीं छूता और Cacti डिवाइस, ग्राफ़, डेटा स्रोत, RRD, टेम्पलेट या `resource/script_server/cloudflare_tunnels.xml` नहीं हटाता।

<a id="known-limitations"></a>
## ज्ञात सीमाएँ

- `#[\SensitiveParameter]` टोकन arguments को stack trace से केवल PHP 8.2 और नए में छिपाता है। PHP 7.4 से 8.1 में प्रभाव नहीं है, इसलिए PHP द्वारा छपे trace में टोकन argument हो सकता है।
- टोकन और कुंजी `sodium_memzero()` से तभी मिटते हैं जब sodium extension लोड हो, और PHP string की कॉपी पहले बना चुका हो सकता है। इसे यथासंभव प्रयास मानें।
- बाहरी अनुरोध HTTP proxy सेटिंग अनदेखी करते हैं। केवल proxy से इंटरनेट पहुँचने वाला Cacti सर्वर Cloudflare API तक नहीं पहुँच सकता।
- कुंजी rotation उपकरण नहीं है। नई कुंजी से पुराने vault टोकन “Stored token cannot be decrypted” के साथ विफल होंगे जब तक हर टोकन फिर दर्ज न हो; README के “Rotating the key” में चरण हैं। हर ciphertext को नई कुंजी से फिर एन्क्रिप्ट करने वाला rekey कमांड भविष्य में जोड़ा जा सकता है।
- फ़ाइल अनुमति समस्याएँ Verify पर आती हैं, save पर नहीं।
- `TokenFile` केवल अंतिम path component का symbolic link अस्वीकार करता है; Token File Directory सेट न हो तो parent directories नहीं जाँचता।
- Cacti वास्तविक fatal error, जैसे मेमोरी समाप्त, `max_execution_time` पार होना या compile error, पर प्लगइन अक्षम करता है। कोई `catch` इन्हें नहीं पकड़ सकता और प्लगइन रोक नहीं सकता; प्रशासक को फिर सक्षम करना पड़ता है। पृष्ठ `Throwable` पकड़ते हैं और class name लॉग करते हैं; इसलिए गुम तालिका जैसी सामान्य exception निश्चित संदेश दिखाती है और Cacti shutdown handler तक नहीं पहुँचती।
- बीच में पूर्ण सूची मिले बिना गिनी गई तीन outage-class विफलताएँ खाते के सक्रिय टनलों को `unavailable` करती हैं, चाहे कारण लंबी rate limit हो। अन्य विफलताएँ बाधा काउंटर को न बढ़ाती हैं, न रीसेट करती हैं। अगली पूर्ण सूची उसे रीसेट करती है और टनल बहाल करती है।
- अपग्रेड में वितरित template XML बदलने पर आयातित templates में प्रशासक के बदलाव overwrite होते हैं, और डिवाइस मुख्य पोलर पर रहना चाहिए। README की ज्ञात सीमाएँ देखें।
- Verify वेब request के भीतर चलता है। एक Verify अधिकतम चार requests कर सकता है (account verify, user verify, tunnel probe, zone probe), हर एक ऊपर के timeouts तक सीमित और अधिकतम एक बार retried होता है।
