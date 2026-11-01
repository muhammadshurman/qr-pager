import type { CopyKey } from './en.ts'

/**
 * ================================================================================
 * THE ARABIC COPY. Modern Standard Arabic, and a launch language (§12).
 * ================================================================================
 *
 * ⚠ `Record<CopyKey, string>` IS THE CONTROL. A key added to `en.ts` and missing here does
 * not compile. There is NO fallback to English anywhere in this app, deliberately: a
 * fallback turns a missing translation into a screen that half-works, which ships, and
 * `§12` names Arabic a launch language rather than a later addition.
 *
 * TRANSLATED, NOT TRANSLITERATED, and the three places that matter:
 *
 *  - «بالهناء والشفاء» for *Enjoy your order* is the idiom an Arabic speaker actually says
 *    over food. A literal «استمتع بطلبك» is comprehensible and reads like software.
 *  - The cancellation copy keeps the agent explicit -- «قام المطعم بإلغائه» -- for the same
 *    reason the English does: a passive «تم الإلغاء» lets the customer read it as their own
 *    fault, which `§3.5` says the tone must not do.
 *  - `{reference}` and `{time}` are placed where Arabic word order wants them, not where
 *    English put them. That is the whole reason the placeholders are in the copy rather
 *    than concatenated by the caller.
 *
 * WHAT IS DELIBERATELY NOT TRANSLATED: the order reference itself and the elapsed clock.
 * Both are rendered `dir="ltr"` by the kit (`ElapsedTimer`, `OrderReady`), because a code
 * and a clock are read left to right inside right-to-left text -- `QRPAGER-15 §6` trap 2,
 * *"logos and numbers do not [mirror]"*.
 */
export const AR: Record<CopyKey, string> = {
  // ------------------------------------------------------------------ scan landing
  'landing.valid.eyebrow': 'طلبك',
  'landing.valid.lede': 'سننبّه هاتفك في اللحظة التي يصبح فيها طلبك جاهزًا. يمكنك إبعاده الآن.',
  'landing.valid.cta': 'تشغيل التنبيهات',
  'landing.valid.skip': 'اكتفِ بعرض الحالة',
  'landing.invalid.title': 'هذا ليس رابط تنبيه صالحًا',
  'landing.invalid.body': 'أعد مسح الرمز الموجود على فاتورتك، أو اطلب رمزًا جديدًا من الكاشير.',
  'landing.ref.label': 'رقم الطلب',

  // ----------------------------------------------------------------- enable alerts
  'alerts.lede': 'تنبيه واحد عندما يصبح طلبك جاهزًا. ولا شيء غير ذلك، أبدًا.',
  'alerts.cta': 'السماح بالتنبيهات',
  'alerts.why.summary': 'لماذا نطلب ذلك',
  'alerts.why.body':
    'لكي تتمكّن من إبعاد هاتفك وتبقى على علم. نرسل تنبيهًا واحدًا لهذا الطلب، ولا نحفظ لك حسابًا ولا رقم هاتف ولا اسمًا.',
  'alerts.notNow': 'ليس الآن',
  'alerts.granted': 'تم تشغيل التنبيهات. يمكنك إبعاد هاتفك.',
  'alerts.denied.title': 'التنبيهات محجوبة',
  'alerts.denied.body':
    'لن يسألك متصفحك مرة أخرى، لذا لا يمكننا تشغيلها من هنا. أبقِ هذه الصفحة مفتوحة وستبقى الحالة أدناه محدَّثة.',
  'alerts.unsupported.title': 'هذا المتصفح لا يدعم التنبيهات',
  'alerts.unsupported.body':
    'أبقِ هذه الصفحة مفتوحة بدلًا من ذلك؛ ستبقى الحالة أدناه محدَّثة أثناء انتظارك.',
  'alerts.continue': 'متابعة',
  'alerts.install.title': 'أضِف هذه الصفحة إلى الشاشة الرئيسية أولًا',
  'alerts.install.body':
    'على iPhone و iPad، لا تعمل التنبيهات إلا من تطبيق على الشاشة الرئيسية. يستغرق ذلك ثلاث نقرات.',
  'alerts.install.step1': 'اضغط زر المشاركة في شريط المتصفح.',
  'alerts.install.step2': 'اختر «أضِف إلى الشاشة الرئيسية».',
  'alerts.install.step3': 'افتحها من الأيقونة الجديدة، ثم اسمح بالتنبيهات.',
  'alerts.install.tabNotice': 'أنت في علامة تبويب في المتصفح، لذا لا يمكن للتنبيهات أن تصلك بعد.',

  // ----------------------------------------------------------------------- waiting
  'waiting.title': 'جارٍ تحضير طلبك',
  'waiting.status.waiting': 'قيد التحضير',
  'waiting.elapsed.label': 'الوقت المنقضي منذ المسح',
  'waiting.next.summary': 'ما سيحدث بعد ذلك',
  'waiting.next.body':
    'عندما يضع المطبخ طلبك جاهزًا، سينبّه هاتفك وستتحول هذه الشاشة إلى اللون الأخضر. وإن لم تنتبه للتنبيه، ستظل الشاشة تعرضه.',
  'waiting.awake.label': 'إبقاء الشاشة مضاءة',
  'waiting.awake.on': 'مُشغَّل',
  'waiting.awake.off': 'مُعطَّل',
  'waiting.awake.unavailable': 'قد تخفت إضاءة شاشتك أثناء الانتظار، لكن التنبيه سيصل على أي حال.',
  'waiting.degraded.title': 'تحديثات الحالة متأخرة',
  'waiting.degraded.body':
    'قد يكون العدّاد أدناه متأخرًا قليلًا. هذا لا يؤثر على تنبيهك، وسيصل كما هو مقرر.',
  'waiting.degraded.asOf': 'آخر تأكيد {time}',
  'waiting.offline.title': 'أنت غير متصل بالإنترنت',
  'waiting.offline.body': 'لا يمكننا التحقق من الحالة دون اتصال. لا يزال طلبك قيد التحضير.',
  'waiting.retry': 'أعد المحاولة',

  // ------------------------------------------------------------------- order ready
  'ready.heading': 'طلبك جاهز',
  'ready.instruction': 'أظهِر هذا الرقم عند الكاشير.',
  'ready.ack': 'تم',
  'ready.acked': 'في الطريق',
  'ready.announce': 'طلبك جاهز. رقم الطلب {reference}.',

  // ----------------------------------------------------------------- session ended
  'ended.completed.title': 'بالهناء والشفاء',
  'ended.completed.body': 'انتهى عمل هذا التنبيه. شكرًا لانتظارك.',
  'ended.cancelled.title': 'تم إلغاء هذا الطلب',
  'ended.cancelled.body': 'قام المطعم بإلغائه. يُرجى السؤال عند الكاشير إن لم تكن تتوقّع ذلك.',
  'ended.expired.title': 'انتهت صلاحية هذا الرمز',
  'ended.expired.body': 'تبقى رموز التنبيه صالحة لمدة ساعتين. اطلب رمزًا جديدًا من الكاشير.',
  'ended.rescan': 'امسح رمزًا جديدًا',
  'ended.home': 'العودة إلى {restaurant}',

  // ---------------------------------------------------------------- offline / error
  'error.offline.title': 'أنت غير متصل بالإنترنت',
  'error.offline.body': 'سنحاول من جديد بمجرد عودة الاتصال.',
  'error.server.title': 'لا يمكننا الوصول إلى المطبخ في هذه اللحظة',
  'error.server.body': 'هذا خلل من جانبنا لا من جانبك، ولا يؤثر على طلبك.',
  'error.unknown.title': 'حدث خطأ ما',
  'error.unknown.body': 'لسنا متأكدين من السبب. أعد المحاولة، واسأل عند الكاشير إن تكرّر ذلك.',
  'error.retry': 'أعد المحاولة',

  // --------------------------------------------------------------------- non-visual
  'a11y.loading': 'جارٍ التحقق من طلبك',
  'a11y.productMark': 'تنبيه الطلبات',
  'a11y.statusRegion': 'حالة الطلب',
}
