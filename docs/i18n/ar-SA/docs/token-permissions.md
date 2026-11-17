<!-- Source: docs/token-permissions.md; SHA256: a6b754c0fe728f30e24edbf5da2236c10a28452cddb4ae8eb64c7791436cb574 -->

<a id="api-token-permissions"></a>
# أذونات رمز API

لا يقبل الملحق إلا رموز API ذات النطاق المحدد. يرفض مفاتيح API العامة ولا يطلب البريد الإلكتروني للحساب مطلقًا. جميع طلباته للقراءة؛ لا يحتاج إلى أي إذن للكتابة. أسماء الأذونات أدناه هي التسميات الواردة في وثائق Cloudflare، كما سُجلت في [research/cloudflare-api-facts.md](../../../research/cloudflare-api-facts.md#3-token-permissions).

<a id="milestones-1-and-2"></a>
## المرحلتان 1 و2

| الإذن | النطاق | مطلوب | الاستخدام |
|---|---|---|---|
| `Cloudflare Tunnel Read` | الحساب | نعم، أو أحد البدائل أدناه | اختبار الأنفاق في Verify واكتشاف الأنفاق |
| `Zone Read` | المنطقة | لا | اختبار المناطق في Verify. لا يُستخدم لغير ذلك بعد |

تقبل نقاط نهاية الأنفاق في Cloudflare أيًا من الأذونات التالية، وتفي أذونات Write المقابلة بالغرض أيضًا:

- `Cloudflare Tunnel Read`
- `Cloudflare One Connectors Read`
- `Cloudflare One Connector: cloudflared Read`

يسمي مرجع API في Cloudflare إذن المنطقة `Zone Zone Read`، بينما تسميه صفحة الأذونات `Zone Read`. يشير الاسمان إلى الإذن نفسه.

<a id="what-discovery-calls"></a>
### الطلبات التي يجريها الاكتشاف

تجري أداة الجمع طلبين، كلاهما `GET` وكلاهما يحتاج إلى إذن قراءة الأنفاق:

- `/accounts/{account_id}/cfd_tunnel`، لسرد أنفاق الحساب
- `/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections`، لقراءة موصلات نفق واحد

لا يحتاج الاكتشاف إلى إذن يتجاوز إذن قراءة الأنفاق الذي يفحصه Verify بالفعل. إذا رفضت Cloudflare سرد الأنفاق أثناء تشغيل ما، يفشل التشغيل بالفئة `auth` أو `permission`؛ وتؤدي ثلاثة تشغيلات فاشلة متتالية إلى وسم أنفاق الحساب النشطة بالحالة `unavailable` إلى أن ينجح السرد مجددًا.

لا يحتاج التحقق من الرمز (`/tokens/verify`) إلى إذن. تعمل الرموز المملوكة للحساب والمملوكة للمستخدم. يتلقى رمز المستخدم HTTP 401 من نقطة التحقق الخاصة بالحساب، ثم يعيد الملحق المحاولة عبر نقطة المستخدم.

<a id="what-verify-reports"></a>
### ما يعرضه Verify

يسجل Verify النتيجة على الحساب ويعرضها في صفحة التحرير ضمن Connection Status:

- **ok**: الرمز نشط ونجح اختبار الأنفاق.
- **permission_denied**: الرمز نشط لكن اختبار الأنفاق رُفض، أو أعادت نقطتا التحقق من الرمز HTTP 403. أضف أحد أذونات الأنفاق أعلاه.
- **Permission: tunnel_read** و**Permission: zone_read**: ما إذا كان كل اختبار قد سُمح به، وفق أحدث تشغيل لـ Verify وصل إلى الاختبارين. يؤدي تغيير معرّف الحساب أو الرمز أو طريقة تخزينه أو ملفه إلى مسح هاتين النتيجتين.

لا يؤدي غياب إذن المنطقة إلى فشل Verify.

<a id="milestone-4-analytics-planned"></a>
## المرحلة 4: التحليلات (مخطط لها)

لم يُنفذ جمع التحليلات بعد. يُخزن إعداد «Collect Analytics» لكن لا يقرأه أي مكون حاليًا. من المتوقع أن يتطلب إصدار التحليلات:

| الإذن | النطاق |
|---|---|
| `Account Analytics Read` | الحساب |
| `Analytics Read` | المنطقة |

يضبط دليل GraphQL لدى Cloudflare الرمز بإذن Account Analytics Read، وتسرد صفحة الأذونات Analytics Read ضمن أذونات المنطقة. لم يُتحقق بعد من أيهما مطلوب حصرًا لاستعلام `httpRequestsAdaptiveGroups` على مستوى المنطقة.

<a id="creating-the-token"></a>
## إنشاء الرمز

أنشئ رمز API مخصصًا في لوحة Cloudflare، سواء أكان رمز مستخدم أم رمزًا مملوكًا للحساب. امنحه Account > Cloudflare Tunnel > Read، واختياريًا Zone > Zone > Read. احصر موارد الحساب في الحساب الذي ستراقبه، وإذا أضفت Zone Read فاحصر موارد المناطق في مناطق ذلك الحساب. خزّن الرمز كما هو موضح في README.
