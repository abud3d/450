# نشر بلوت 450 على blout.arb.im

هذه الحزمة مخصصة لبيئة تجريبية مستقلة على CWP. لا تتصل بأي قاعدة بيانات أو ملفات إنتاج تلقائياً.

## محتويات الحزمة
- `app/`: محرك اللعبة والتخزين.
- `database/schema.mysql.sql`: مخطط MySQL الذي يستورد مرة واحدة.
- `public/`: ملفات الموقع وواجهات PHP. يجب أن يكون هذا المجلد وحده هو Document Root.
- لا ترفع مجلدات الاختبارات أو ملفات GitHub إلى الموقع.

## 1) رفع الملفات
1. أنشئ مجلد المشروع خارج `public_html` مثلاً:
   `/home/CPUSER/baloot450/`
2. فك ZIP داخله بحيث تظهر المجلدات `app`, `database`, `public`.
3. اجعل Document Root للنطاق `blout.arb.im` هو:
   `/home/CPUSER/baloot450/public`
4. فعّل SSL وتأكد أن الموقع يفتح عبر HTTPS.

استبدل CPUSER باسم مستخدم CWP الحقيقي. لا تضع Document Root على جذر المشروع.

## 2) إعداد PHP
PHP 8.2+ مطلوب مع:
- pdo_mysql
- mbstring
- json
- openssl

لا يحتاج المشروع Node.js أو Socket.IO أو PM2 أو منفذ 3000.

## 3) قاعدة البيانات — مطلوبة قبل تشغيل اللعب الجماعي
النسخة الحالية تستخدم MySQL لحفظ الغرف واللاعبين والحركات بشكل ذري. لا يمكن تأجيلها مع الإبقاء على اللعب متعدد اللاعبين؛ لا تستخدم JSON أو SQLite بديلاً مؤقتاً.

من CWP أنشئ قاعدة بيانات جديدة ومستخدم قاعدة بيانات مستقلين لهذا المشروع، ثم اربط المستخدم بالقاعدة بصلاحياتها. لا تستخدم قاعدة موقع آخر ولا مستخدم root.

من phpMyAdmin اختر قاعدة البيانات الجديدة ثم Import وارفع:
`/home/CPUSER/baloot450/database/schema.mysql.sql`

أو من SSH بعد استبدال القيم:
```bash
mysql -u 'DB_USER' -p 'DB_NAME' < /home/CPUSER/baloot450/database/schema.mysql.sql
```
سيطلب كلمة المرور تفاعلياً. لا تضع كلمة المرور داخل الأمر.

## 4) إعداد متغيرات البيئة
اضبطها في إعدادات PHP-FPM/Apache الخاصة بالنطاق، لا داخل مجلد public ولا في Git:

- `APP_ORIGIN=https://blout.arb.im`
- `BALOUT_DB_DSN=mysql:host=127.0.0.1;dbname=DB_NAME;charset=utf8mb4`
- `BALOUT_DB_USER=DB_USER`
- `BALOUT_DB_PASSWORD=DB_PASSWORD`
- `BALOUT_DATA_DIR=/home/CPUSER/baloot450-private`

أنشئ مجلد البيانات الخاص خارج public:
```bash
mkdir -p /home/CPUSER/baloot450-private
chmod 700 /home/CPUSER/baloot450-private
```
يجب أن يكون المجلد قابلاً للكتابة من مستخدم PHP-FPM الخاص بالحساب، دون جعله عاماً.

## 5) مهمة cron
بعد التأكد من مسار PHP CLI ومتغيرات البيئة ضمن cron، شغّل كل دقيقة:
```bash
/usr/local/bin/php /home/CPUSER/baloot450/public/api/cron.php
```
قد يختلف مسار PHP في CWP؛ تحقق باستخدام `which php` و`php -v`. لا تفتح cron.php للعامة دون سر مخصص.

## 6) فحص أولي قبل مشاركة الرابط
- افتح الصفحة عبر HTTPS.
- اختبر إنشاء غرفة ثم الانضمام من أربعة متصفحات/أجهزة مستقلة.
- اختبر استعادة اللاعب بعد تحديث الصفحة وانقطاع الاتصال.
- اختبر التسجيل والبريد الصادر.
- راقب سجلات PHP وApache.
- لا تفتح الموقع للاستخدام العام قبل اختبار SMTP الفعلي والضغط والأمان.

## تنبيه
هذه حزمة بيئة اختبار. نجاح اختبارات GitHub Actions لا يثبت أن إعداد CWP أو SMTP أو DNS على خادمك صحيح. لا توجد أي عملية نشر تلقائي أو تعديل DNS ضمن هذه الحزمة.
