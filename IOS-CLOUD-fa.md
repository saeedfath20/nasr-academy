# ساخت iOS بدون داشتن Mac

پروژهٔ آموزشگاه نصر می‌تواند روی محیط macOS سرویس Codemagic ساخته شود. ویندوز شما برای مدیریت سورس و شروع ساخت کافی است. داده‌ها همچنان از همان API آموزشگاه دریافت و همان‌جا ثبت می‌شوند. فایل codemagic.yaml و ابزارهای ساخت در این بسته آماده شده‌اند؛ هنوز ساختی در حساب ابری اجرا نشده است.

## اولین قدم در ویندوز

1. بستهٔ جدید سورس را استخراج کن.
2. در GitHub یک مخزن Private برای پروژه بساز.
3. محتویات داخل پوشهٔ nasr-academy-platform را در ریشهٔ مخزن قرار بده. در صفحهٔ اصلی مخزن باید codemagic.yaml، پوشهٔ mobile-app و پوشهٔ nasr-academy دیده شوند. خود ZIP یا پوشهٔ اضافی دور پروژه را بارگذاری نکن. برای آپلود از مرورگر، Add file > Upload files را باز کن و پوشه‌ها و فایل‌های داخل nasr-academy-platform را انتخاب کن؛ ساختار پوشه‌ها حفظ شود.
4. در https://codemagic.io/ حساب بساز، Add application را انتخاب و فقط همان مخزن پروژه را متصل کن. نوع پروژه را Other یا گزینهٔ تنظیمات YAML انتخاب کن.
5. شاخهٔ مخزن را برای یافتن codemagic.yaml بررسی و workflow با نام Nasr iOS - unsigned compile check را اجرا کن.

این مرحله برای بررسی کامپایل واقعی Swift و وابستگی‌های iOS است. Apple Developer و گواهی امضا لازم ندارد. خروجی Nasr-iOS-simulator.zip فقط برای شبیه‌ساز است و روی آیفون نصب نمی‌شود. نتیجهٔ درست، موفق‌شدن مرحلهٔ Compile iOS Simulator application است؛ اگر ساخت متوقف شد، فایل xcodebuild.log همان ساخت برای عیب‌یابی استفاده می‌شود. محدودیت دقیقه و هزینهٔ حساب خودت را قبل از اجرای ساخت در Codemagic بررسی کن.

## ساخت فایل قابل نصب و TestFlight

برای مسیر TestFlight باید عضویت Apple Developer Program فعال داشته باشی. Apple Account معمولی به‌تنهایی این مرحله را فراهم نمی‌کند. به ترتیب:

1. در حساب توسعه‌دهندهٔ اپل، شناسهٔ com.nasr.academy را برای تیم خودت ثبت کن. اگر این شناسه در اختیار تیم دیگری بود، ابتدا شناسه‌ای یکتا انتخاب کن؛ مقدار PRODUCT_BUNDLE_IDENTIFIER در فایل mobile-app/ios/App/App.xcodeproj/project.pbxproj و bundle_identifier در workflow امضاشده باید دقیقاً یکی باشند.
2. در App Store Connect یک رکورد اپ با همان شناسه بساز.
3. یک App Store Connect API key مخصوص این پروژه ایجاد و در تنظیمات Developer Portal integration داخل Codemagic وارد کن. این کلید در سورس قرار نمی‌گیرد.
4. در Code signing identities یک Apple Distribution certificate و یک App Store provisioning profile مطابق همان شناسه و تیم اضافه کن. Codemagic امکان تولید گواهی با اتصال حساب اپل و دریافت پروفایل‌های حساب را دارد. پروفایل و گواهی باید با یکدیگر منطبق باشند و در پنل با وضعیت معتبر نمایش داده شوند. اطلاعات حساس امضا را در قسمت تنظیمات سرویس وارد کن.
5. workflow با نام Nasr iOS - signed IPA را اجرا کن. گواهی و پروفایل‌های واردشده براساس app_store و com.nasr.academy انتخاب می‌شوند. خروجی از بخش Artifacts با پسوند ipa قابل دریافت است.

workflow امضاشده فقط فایل را می‌سازد و در این بسته تنظیمی برای انتشار خودکار، ارسال به اپ‌استور یا دعوت تست‌کنندگان فعال نشده است. IPA با نوع App Store را نمی‌توان مانند APK با بازکردن فایل روی آیفون نصب کرد؛ مرحلهٔ بعد، بارگذاری به App Store Connect و فعال‌کردن تست از مسیر TestFlight است. پس از آماده‌شدن حساب و اولین ساخت، اتصال آن مرحله را تنظیم می‌کنیم. برای ساخت‌های بعدیِ قابل بارگذاری، شمارهٔ CURRENT_PROJECT_VERSION باید از ساخت قبلی بالاتر باشد.

## وضعیت بررسی این بسته

- ساختار YAML، وجود مسیرها، ارجاع Scheme به Target واقعی App و دستورهای Bash بررسی شده‌اند.
- رفتار توقف در صورت نبود macOS و توقف پس از شکست کامپایل با ابزارهای آزمایشی کنترل شده است.
- هنوز حساب Codemagic متصل نشده و کامپایل و امضای واقعی iOS در این محیط Linux اجرا نشده است.
- Android و فایل APK قبلی تغییر نکرده‌اند. فقط فایل‌های ساخت و راهنمای iOS به سورس اضافه شده‌اند.

## منابع رسمی

- https://docs.codemagic.io/yaml-quick-start/building-an-ionic-app/
- https://docs.codemagic.io/yaml-code-signing/ios-simulator-builds/
- https://docs.codemagic.io/yaml-code-signing/signing-ios/
- https://developer.apple.com/documentation/xcode/distributing-your-app-for-beta-testing-and-releases
- https://developer.apple.com/testflight/
