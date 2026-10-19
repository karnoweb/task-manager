# از اینجا شروع کنید — Task Manager برای AI Agent

**هدف:** ساخت یک پکیج مستقل Laravel با Task/Todo، مدیریت کامل پروژه، موتور عمومی Workflow/Pipeline و فرآیندهای CRM، قابل اتصال اختیاری به Kanban و Calendar. **توسعه ۶ فاز + فاز ۷ فقط آزمون.**

## فایل‌ها و ترتیب خواندن

1. `KARNOWEB-TASK-MANAGER-ENTERPRISE-PRD-v2.0-FA.md` — تعریف محصول و ۲۳۶ سناریوی اصلی.
2. `TASK-MANAGER-7-PHASE-IMPLEMENTATION-PLAN-FA.md` — مرز فازها، خروجی هر فاز و Gate دقیق ۲۳۶ آزمون.
3. `TASK-MANAGER-ENTERPRISE-TEST-SCENARIOS-FA.md` — ۲۳۶ سناریوی اصلی + ۶ E2E + ۴۰ NET + ۸۰ REG = ۳۶۲ شناسه.
4. `TASK-MANAGER-AI-AGENT-PROMPTS-7-PHASES-FA.md` — هفت فرمان مستقل Agent برای اجرای مرحله‌ای.

## پیش از شروع

- Agent باید repo Task Manager را (در صورت جدید بودن، با scaffolding) بررسی/ایجاد کند؛ قبل از نام‌گذاری نهایی facade تطبیق با سورس حسابداری و انبارداری در صورت دسترسی انجام شود. **بدون سورس آن دو پکیج، سازگاری قطعی با APIشان ادعا نشود.**
- گزارش Code Review واقعی Kanban/Calendar و source فعلیِ tag قفل‌شده خوانده شود؛ هیچ Dependency اجباری در package core ایجاد نشود.
- تعیین Scope/Actor/cutoff equality، مالکیت Workflow Instance و وضعیت CRM و سیاست انتشار باید ثبت شود.
- پس از **هر فاز** گزارش، log واقعی اجرای Gate و commit SHA را تحویل و برای تأیید انسانی مکث کند.

## مهم‌ترین قرارداد فاز هفتم

**فاز هفت فقط آزمون، benchmark، گزارش و تصمیم Go/No-Go است.** اگر عیبی پیدا شد، Agent تست بازتولید و گزارش Defect می‌دهد ولی **کد محصول را در فاز ۷ تغییر نمی‌دهد**. عیب به فاز مالک بازمی‌گردد، سپس Freeze جدید و تکرار کامل فاز ۷ الزامی است.

## شروع سریع

فایل `TASK-MANAGER-AI-AGENT-PROMPTS-7-PHASES-FA.md` را باز کنید؛ **فقط متن «پرامپت فاز ۱»** و چهار سند مبنا را به Agent بدهید. فاز دوم را پس از تأیید Gate فاز اول آغاز کنید.
