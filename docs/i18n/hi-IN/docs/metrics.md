<!-- Source: docs/metrics.md; SHA256: 62af26b17013d9977cbe2898d8822142f0a8e0768d6b35af3193c4e8935dd773 -->

<a id="metrics"></a>
# मेट्रिक

प्लगइन खोज द्वारा पहले से संग्रहीत टनल स्थिति के ग्राफ़ बनाता है। पोलर के पथ में कोई भी क्रिया Cloudflare को कॉल नहीं करती: `ObservationReader` (`src/Metrics`) प्लगइन की तालिकाएँ पढ़कर प्रत्येक टनल के लिए हर मेट्रिक का एक अवलोकन बनाता है। अवलोकन में मान होता है, या null मान और उसका कारण होता है। Null को `U` के रूप में छापा जाता है।

<a id="catalog"></a>
## सूची

`Catalog::all()` वह एकमात्र सूची है जिससे टेम्पलेट, डेटा स्रोत नाम और स्क्रिप्ट सर्वर के फ़ील्ड आते हैं। हर मेट्रिक `GAUGE` है और उसका न्यूनतम मान 0 है।

| मेट्रिक | क्वेरी फ़ील्ड | DS नाम | इकाई | अधिकतम | प्रकार |
|---|---|---|---|---|---|
| `cloudflare_tunnel_health` | `health` | `health` | स्थिति | 3 | स्थिति |
| `cloudflare_tunnel_connectors` | `connectors` | `connectors` | कनेक्टर | U | स्थिति |
| `cloudflare_tunnel_connections` | `connections` | `connections` | कनेक्शन | U | स्थिति |
| `cloudflare_tunnel_connector_versions` | `versions` | `versions` | संस्करण | U | स्थिति |
| `cloudflare_collection_age` | `collectAge` | `collect_age` | सेकंड | U | संग्रह |
| `cloudflare_collection_failures` | `collectFailures` | `collect_fail` | विफलताएँ | U | संग्रह |

`versions` टनल के मौजूदा कनेक्टरों में अलग-अलग cloudflared संस्करणों की संख्या है। `collectAge` टनल के कनेक्शन आखिरी बार सफलतापूर्वक पढ़े जाने के बाद बीता समय है, और `collectFailures` खोज कार्य की लगातार विफलताओं की संख्या है।

<a id="health"></a>
## स्वास्थ्य

| Cloudflare स्थिति | मान |
|---|---|
| `healthy` | 3 |
| `degraded` | 2 |
| `down` | 1 |
| `inactive` | 0 |

इस सूची से बाहर की स्थिति अज्ञात होती है, जिसका कारण `malformed` है।

<a id="when-a-value-is-printed"></a>
## मान कब छापा जाता है

गुम मान को कभी शून्य के रूप में दर्ज नहीं करना चाहिए। RRD में शून्य वास्तविक बाधा जैसा दिखता है और बाद में उससे अलग नहीं पहचाना जा सकता; इसलिए जिस मान की पुष्टि रीडर नहीं कर सकता वह `U` होता है।

```
window = 2 * discovery_interval + 60
age    = now - observed_at
```

`now` पोलर की घड़ी है। स्वास्थ्य के लिए `observed_at` का मान `last_seen_at` है और कनेक्शन मेट्रिक (`connectors`, `connections`, `versions`) के लिए `connections_refreshed_at` है। स्थिति मेट्रिक का मान तभी होता है जब ये सभी शर्तें पूरी हों:

- खाते के खोज कार्य का `last_status`, `ok` या `partial` हो;
- टनल का `lifecycle`, `active` हो;
- `observed_at` सेट हो और `-60 <= age <= window` हो। -60 से 0 के बीच की आयु घड़ी का विचलन मानी जाती है और 0 गिनी जाती है;
- कनेक्शन मेट्रिक के लिए `connections_attempted_at <= connections_refreshed_at` हो, अर्थात नवीनतम प्रयास सफल हुआ हो।

जाँच इसी क्रम में होती है और पहली विफल जाँच कारण निर्धारित करती है:

1. कार्य की स्थिति। कार्य की पंक्ति न होने या `never` होने पर `never_collected` मिलता है। `ok` या `partial` से अलग स्थिति पर कार्य का `last_error_class` मिलता है; वह सहेजा न हो तो `transient` मिलता है।
2. जीवनचक्र। `unavailable` या `deleted` से `not_active` मिलता है।
3. `observed_at` null हो: `never_collected`।
4. आयु -60 से कम हो: `clock_skew`। आयु विंडो से अधिक हो: `stale`।
5. केवल कनेक्शन मेट्रिक: रीफ़्रेश के बाद का प्रयास टनल का `connections_error_class` देता है; वह सहेजा न हो तो `transient` मिलता है।
6. संग्रहीत मान स्वयं। Null `connector_count` या `connection_count` से `never_collected` मिलता है, भले ही `connections_refreshed_at` सेट हो। स्वास्थ्य तालिका से बाहर की Cloudflare स्थिति केवल स्वास्थ्य के लिए `malformed` देती है; अन्य मेट्रिक अप्रभावित रहते हैं। `versions` कनेक्टर पंक्तियाँ गिनता है, जिन्हें खोज `connector_count` के साथ सहेजती है; इसलिए null `connector_count` से `versions` भी `never_collected` कारण के साथ अज्ञात होता है।

विफल कार्य पहले जाँचा जाता है क्योंकि वही बाकी स्थितियों को समझाता है। बार-बार बाधा आने पर खोज टनल को `unavailable` चिह्नित करती है, इसलिए कार्य का त्रुटि वर्ग अधिक उपयोगी कारण है।

300 सेकंड के डिफ़ॉल्ट अंतराल पर विंडो 660 सेकंड होती है। ऐसा टनल जिसका कार्य अभी भी `ok` बताता है लेकिन जो 661 सेकंड से रीफ़्रेश नहीं हुआ, `stale` कारण के साथ `U` छापता है।

<a id="collection-metrics"></a>
### संग्रह मेट्रिक

`collectAge` और `collectFailures` संग्राहक का वर्णन करते हैं, इसलिए बाधा के दौरान भी छपते रहते हैं। वे कार्य स्थिति, `unavailable` जीवनचक्र और विंडो को अनदेखा करते हैं। वे इन स्थितियों में अज्ञात होते हैं:

- टनल `deleted` हो (`not_active`);
- टनल के कनेक्शन कभी रीफ़्रेश न हुए हों, या खाते की कार्य पंक्ति न हो (`never_collected`);
- `collectAge` के लिए रीफ़्रेश समय भविष्य में 60 सेकंड से अधिक हो (`clock_skew`)।

Null `consecutive_failures` से `collectFailures`, `never_collected` कारण के साथ अज्ञात होता है। इनका `observed_at` पढ़ने का समय होता है।

<a id="reasons"></a>
## कारण

| कारण | अर्थ |
|---|---|
| कोई `ErrorClass` मान | विफल कार्य या कनेक्शन पढ़ने का संग्रहीत वर्ग |
| `stale` | विंडो से पुराना |
| `clock_skew` | भविष्य में 60 सेकंड से अधिक |
| `not_active` | टनल `unavailable` या `deleted` है |
| `never_collected` | अभी तक कोई सफल पठन संग्रहीत नहीं हुआ |
| `unknown_tunnel` | खाते में ठीक इसी ID वाला टनल नहीं है |

`ErrorClass` के मान `auth`, `permission`, `not_found`, `rate_limited`, `transient`, `malformed` और `config` हैं।

टनल ID का मिलान बिल्कुल सटीक होता है। कॉलम का collation अक्षरों के बड़े-छोटे रूप का अंतर अनदेखा करता है, इसलिए क्वेरी से पहले रीडर हर वह मान अस्वीकार करता है जो छोटे अक्षरों वाला UUID नहीं है।

<a id="sampling"></a>
## सैंपलिंग

Cacti हर पोल पर नवीनतम अवलोकन का नमूना लेता है। खोज प्रत्येक अंतराल में एक बार चलती है, इसलिए दो खोजों के बीच gauge वही मान दोहराता है। RRD का टाइमस्टैम्प पोल का समय होता है, जो `observed_at` के बाद पूरी विंडो, अर्थात `2 * discovery_interval + 60` सेकंड, तक हो सकता है।

<a id="schema-version"></a>
## स्कीमा संस्करण

अवलोकनों में `Version::METRIC_SCHEMA` होता है, जिसका मान 1 है। संस्करण 1 में केवल gauge हैं, इसलिए काउंटर रीसेट नहीं हो सकते। भविष्य के संस्करणों के लिए `type` फ़ील्ड फिर भी `GAUGE` और `COUNTER` में अंतर करता है। मेट्रिक या DS का नाम बदलने, अथवा प्रकार या सीमा बदलने के लिए नया स्कीमा संस्करण चाहिए, क्योंकि टेम्पलेट और मौजूदा RRD फ़ाइलें उन पर निर्भर हैं।
