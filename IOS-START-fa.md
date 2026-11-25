# شروع تست iOS — نسخهٔ 1.5.0-beta.8

پروژهٔ iOS در همین سورس کامل موجود است و رابط آن با Android یکسان و به همان API آنلاین آموزشگاه متصل است. دادهٔ آموزشی و مالی جداگانه‌ای روی گوشی ساخته نمی‌شود. فایل‌های رابط iOS با خروجی نسخهٔ Android تطبیق داده شده‌اند؛ ساخت بومی و تست Face ID / Touch ID هنوز باید در Xcode و روی iPhone انجام شوند. این بسته شامل IPA امضاشده نیست.

## پیش‌نیازها

- Mac با نسخهٔ macOS سازگار با Xcode نصب‌شده.
- Xcode نسخهٔ 26 یا جدیدتر؛ یک بار باز شود و نصب اجزای اولیه تکمیل شود.
- Node.js نسخهٔ 22 یا جدیدتر.
- iPhone با iOS 15 یا جدیدتر برای تست واقعی؛ شبیه‌ساز برای بررسی اولیهٔ رابط.
- Apple Account در Xcode. نصب توسعه‌ای روی گوشی شخصی با Personal Team ممکن است و نیاز به تمدید دوره‌ای دارد. توزیع بین تست‌کنندگان با TestFlight به عضویت Apple Developer Program نیاز دارد.

این پروژه از Swift Package Manager استفاده می‌کند؛ برای همین پروژه نصب CocoaPods لازم نیست.

## آماده‌سازی

ZIP سورس کامل را روی Mac استخراج کن. در Terminal وارد پوشهٔ nasr-academy-platform شو و اجرا کن:

```bash
bash Prepare-iOS.command
```

این دستور پیش‌نیازها را بررسی می‌کند، وابستگی‌ها را طبق فایل قفل نصب می‌کند، رابط را می‌سازد، فایل‌ها را با iOS همگام می‌کند و پروژه را در Xcode باز می‌کند. ساخت و بارگذاری به حساب اپل را خودکار انجام نمی‌دهد.

## اجرای اولین نسخه روی iPhone

1. در Xcode > Settings > Accounts حساب Apple را اضافه کن.
2. پروژهٔ App و سپس Target با نام App را انتخاب کن. در Signing & Capabilities گزینهٔ Automatically manage signing را فعال و Team خودت را انتخاب کن. شناسهٔ پروژه com.nasr.academy است؛ برای انتشار واقعی باید آن را در تیم خودت ثبت کنی. اگر این شناسه برای Personal Team قابل ثبت نبود، برای تست شخصی شناسه‌ای یکتا مانند com.yourteam.nasr.academy انتخاب کن.
3. iPhone را وصل و پیام Trust را تأیید کن. در صورت درخواست Xcode، Developer Mode گوشی را فعال کن.
4. گوشی را از فهرست دستگاه‌های بالای Xcode انتخاب و Run را بزن. Simulator برای آزمایش حسگر واقعی کافی نیست.
5. ورود با نام کاربری و رمز، یادآوری ورود بعد از بستن اپ، فعال‌سازی و ورود خودکار Face ID / Touch ID، نقش‌ها، انتخاب ترم، دریافت دادهٔ یکسان با سایت و ثبت تغییر از دو دستگاه را بررسی کن. تغییر آموزشی در حالت قطع اینترنت نباید ثبت‌شده نمایش داده شود.

تنظیم NSFaceIDUsageDescription، مخزن امن Keychain، آیکون نصر و اتصال به https://nasr-institute.ir/ در پروژه موجودند.

## تست روی گوشی‌های دیگر

بعد از تست روی گوشی اول، برای توزیع TestFlight، شناسهٔ اپ را در حساب توسعه‌دهنده و App Store Connect بساز. در Xcode دستگاه مقصد را روی Any iOS Device بگذار، Product > Archive را اجرا و از Organizer مسیر Distribute App > App Store Connect را ادامه بده. بعد از پردازش ساخت، تست‌کنندگان را در TestFlight دعوت کن. انتشار برای تست‌کنندگان بیرونی ممکن است بررسی اپل لازم داشته باشد. گواهی، پروفایل امضا و مشخصات حساب تیم، به مالک حساب تعلق دارد و در این سورس قرار داده نشده است.

## منابع رسمی

- پیش‌نیازهای Capacitor 8: https://capacitorjs.com/docs/getting-started/environment-setup
- راهنمای iOS و نسخهٔ حداقل: https://capacitorjs.com/docs/ios
- حساب و Personal Team: https://developer.apple.com/help/account/basics/about-your-developer-account/
- توزیع تستی: https://developer.apple.com/documentation/xcode/distributing-your-app-for-beta-testing-and-releases
- TestFlight: https://developer.apple.com/testflight/

## ساخت با ویندوز و مک ابری

اگر Mac در اختیار نداری، راهنمای IOS-CLOUD-fa.md را دنبال کن. فایل codemagic.yaml برای ساخت شبیه‌ساز بدون گواهی و ساخت IPA پس از تنظیم امضای اپل آماده شده است.
