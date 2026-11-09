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

  'staff.signin.sub': 'دخول الموظفين لورديّة اليوم',
  'staff.signin.or': 'أو',
  'staff.signin.forgot': 'نسيت كلمة المرور؟',
  'staff.signin.failedHead': 'ما قدرنا نتأكد من مفتاح المرور',
  'staff.signin.failedBody': 'جرّب مرة ثانية، أو ادخل بالبريد وكلمة المرور تحت.',
  'staff.signin.waiting': 'في انتظار مفتاح المرور…',
  'staff.signin.waitingSub': 'افتح القفل بجهازك — بالبصمة أو بالوجه أو برمز الجهاز.',
  'staff.signin.cancel': 'إلغاء والدخول بكلمة المرور',
  'staff.signin.title': 'تسجيل الدخول',
  'staff.signin.passkey': 'استخدم مفتاح المرور',
  'staff.signin.working': 'جارٍ تسجيل الدخول…',
  'staff.signin.offline': 'لا يوجد اتصال. تحقّق من الشبكة وحاول مرة أخرى.',
  'staff.signin.unsupported': 'هذا المتصفّح لا يدعم مفاتيح المرور. استخدم كلمة المرور.',
  'staff.signin.email': 'البريد الإلكتروني',
  'staff.signin.passwordLabel': 'كلمة المرور',
  'staff.signin.submit': 'دخول',
  'staff.signin.twoFactor': 'أدخل الرمز المكوّن من ٦ أرقام من تطبيق المصادقة.',
  'staff.signin.code': 'الرمز',
  'staff.signin.choose': 'أي مطعم؟',
  'staff.signin.throttled': 'محاولات كثيرة. انتظر دقيقة ثم سجّل الدخول مرة أخرى.',
  'staff.signin.throttled.body': 'إعادة تسجيل الدخول فورًا لن تنجح بعد.',

  // ---------------------------------------------------------------------- the shell
  'staff.shell.signedIn': 'مسجّل الدخول بصفة {name}',
  'staff.shell.signOut': 'تسجيل الخروج',
  'staff.shell.loading': 'جارٍ التحميل',
  'staff.shell.error.title': 'حدث خطأ من جهتنا.',
  'staff.shell.error.body': 'لم يكن ذلك بسببك.',
  'staff.shell.error.retry': 'حاول مرة أخرى',
  'staff.shell.throttled.title': 'جارٍ اللحاق.',
  'staff.shell.throttled.body': 'يستغرق هذا لحظة. لا حاجة لأي إجراء منك.',
  'staff.shell.offline.title': 'لا يوجد اتصال.',
  'staff.shell.offline.body': 'سنواصل المحاولة.',
  'staff.role.owner': 'المالك',
  'staff.role.manager': 'المدير',
  'staff.role.staff': 'موظف',
  'staff.nav.today': 'اليوم',
  'staff.nav.devices': 'شاشات المطبخ',
  'staff.passkey.add': 'أضف مفتاح مرور إلى هذا الجهاز',
  'staff.passkey.added': 'يمكن لهذا الجهاز تسجيل دخولك بمفتاح المرور الآن.',
  'staff.passkey.failed':
    'لم ينجح ذلك. حاول مرة أخرى، أو سجّل الدخول بكلمة المرور في المرة القادمة.',

  // ------------------------------------------------------------------- the board (10b)
  'staff.board.title': 'الطلبات',
  'staff.board.order': 'طلب {reference}',
  'staff.board.waitingLabel': 'منذ',
  'staff.board.open': 'افتح الطلب {reference}',
  'staff.board.status.waiting': 'قيد التحضير',
  'staff.board.status.ready': 'جاهز',
  'staff.board.status.completed': 'تم الاستلام',
  'staff.board.status.cancelled': 'ملغى',
  'staff.board.status.expired': 'منتهي',
  'staff.board.empty.title': 'لا توجد طلبات قيد الانتظار.',
  'staff.board.empty.body': 'سيظهر الطلب هنا بمجرد إنشاء جهاز نداء له.',
  'staff.board.empty.action': 'تحقّق من الطلبات الجديدة',
  'staff.board.error.title': 'لم نتمكّن من تحميل الطلبات.',
  'staff.board.error.body': 'لم يتغيّر شيء. حاول مرة أخرى.',
  'staff.board.offline.title': 'لا يوجد اتصال.',
  'staff.board.offline.body': 'هذه الطلبات من آخر مرة وصلنا فيها إلى الشبكة.',
  'staff.board.retry': 'حاول مرة أخرى',
  'staff.board.throttled.title': 'اللوحة تتابع التحديث.',
  'staff.board.throttled.body': 'ستُحدّث نفسها بعد لحظات. لا حاجة لأي إجراء منك.',
  'staff.board.stale': 'آخر تحديث {time}',

  // ------------------------------------------------------------------ the pager detail
  'staff.detail.ready': 'أبلغه أن الطلب جاهز',
  'staff.detail.renotify': 'نبّهه مرة أخرى',
  'staff.detail.complete': 'تم استلام الطلب',
  'staff.detail.cancel': 'إلغاء الطلب',
  'staff.detail.close': 'إغلاق',
  'staff.detail.working': 'جارٍ الإرسال…',
  'staff.detail.cancel.title': 'إلغاء هذا الطلب؟',
  'staff.detail.cancel.body': 'سيتم إبلاغ العميل بالإلغاء. لا يمكن التراجع عن ذلك.',
  'staff.detail.cancel.confirm': 'إلغاء الطلب',
  'staff.detail.cancel.keep': 'الاحتفاظ به',
  'staff.detail.limit': 'لقد نبّهته العدد المسموح من المرات. ابحث عنه بنفسك.',
  'staff.detail.expired': 'انتهت صلاحية جهاز النداء. أعطِ العميل رمزًا جديدًا.',
  'staff.detail.gone': 'هذا الطلب لم يعد على اللوحة. ربما أكمله شخص آخر.',
  'staff.detail.stale': 'تغيّرت حالة هذا الطلب. تم تحديث اللوحة.',
  'staff.detail.failed': 'لم تنجح العملية. لم يتغيّر شيء — حاول مرة أخرى.',

  // ----------------------------------------------------------------------- the lock
  'staff.lock.title': 'الشاشة مقفلة',
  'staff.lock.body': 'لا يوجد أحد مسجّل الدخول على هذه الشاشة. سجّل الدخول بمفتاح المرور للمتابعة.',
  'staff.lock.signIn': 'تسجيل الدخول',
  'staff.signedout.title': 'تم تسجيل الخروج',
  'staff.signedout.body':
    'لم تعد هذه الشاشة مسجّلة الدخول. سجّل الدخول بمفتاح المرور عندما تكون مستعدًا.',

  // -------------------------------------------------------------------- kitchen screens
  'staff.devices.title': 'شاشات المطبخ',
  'staff.devices.lede': 'تعرض شاشة المطبخ الطلبات دون أن يسجّل أحد الدخول إليها.',
  'staff.devices.empty.title': 'لا توجد شاشة مطبخ بعد.',
  'staff.devices.empty.body': 'أعدّ واحدة وستعرض طلبات هذا المطعم.',
  'staff.devices.empty.action': 'إعداد شاشة مطبخ',
  'staff.devices.add': 'إعداد شاشة مطبخ',
  'staff.devices.expires': 'تتوقف عن العمل في {date}',
  'staff.devices.lastUsed': 'آخر استخدام {when}',
  'staff.devices.neverUsed': 'لم تُستخدم بعد',
  'staff.devices.name.label': 'ما اسم هذه الشاشة؟',
  'staff.devices.name.hint': 'اسم تتعرّف عليه، مثل طاولة التسليم أو الشوّاية.',
  'staff.devices.create': 'أنشئها',
  'staff.devices.creating': 'جارٍ تهيئة الشاشة…',
  'staff.devices.cancel': 'إلغاء',
  'staff.devices.issued.title': 'أعدّ الشاشة بهذا',
  'staff.devices.issued.warning':
    'انسخه الآن. يُعرض مرة واحدة ولا يمكن عرضه مرة أخرى. إذا فقدته، أزل هذه الشاشة وأعدّ واحدة جديدة.',
  'staff.devices.issued.done': 'نسختُه',
  'staff.devices.remove': 'إزالة',
  'staff.devices.remove.title': 'إزالة هذه الشاشة؟',
  'staff.devices.remove.body': 'ستتوقف الشاشة عن العمل فورًا. يمكنك إعداد واحدة جديدة بعد ذلك.',
  'staff.devices.remove.confirm': 'إزالة',
  'staff.devices.remove.keep': 'الإبقاء عليها',
  'staff.devices.denied.title': 'لا يمكنك تغيير شاشات المطبخ.',
  'staff.devices.denied.body': 'يمكن للمالك أو المدير فقط إعداد شاشات المطبخ. اطلب ذلك من أحدهما.',
  'staff.devices.error.title': 'لم نتمكّن من تحميل شاشات المطبخ.',
  'staff.devices.error.body': 'لم يتغيّر شيء. حاول مرة أخرى.',
  'staff.devices.offline.title': 'لا يوجد اتصال.',
  'staff.devices.offline.body': 'الشاشات كما هي. حاول مرة أخرى عند عودة الشبكة.',

  /* ================================================================================
   * TASK 10c — شاشة المطبخ (`/kds`)
   * ================================================================================
   *
   * Two things this surface does to Arabic that the other two do not, and both are about
   * the reader rather than the script:
   *
   *  - **NOBODY IS SIGNED IN**, so nothing here is addressed to a known person. The staff
   *    copy uses the second person throughout («لقد نبّهته», «سجّل الدخول»); this screen
   *    does not know who is in front of it, so every string is impersonal — «أعدّ» and
   *    «انسخه» appear only on the setup screen, which somebody IS standing at.
   *  - **«الرمز», NEVER a transliteration of *token*.** `staff.devices.issued.*` already
   *    calls it «الرمز» on the screen that produces it, and these two screens are the two
   *    ends of one errand, so they use one word for the thing being carried.
   *
   * The order reference and the elapsed clock stay `dir="ltr"` by the kit, as everywhere
   * else — `QRPAGER-15 §6` trap 2.
   */

  // ------------------------------------------------------------------ setting one up
  'kds.setup.title': 'إعداد شاشة المطبخ هذه',
  'kds.setup.body':
    'الصق الرمز من صفحة «شاشات المطبخ» في المطعم. بعد ذلك ستعرض هذه الشاشة الطلبات من تلقاء نفسها، دون أن يسجّل أحد الدخول إليها.',
  'kds.setup.code.label': 'الرمز',
  'kds.setup.code.hint': 'ظهر مرة واحدة فقط، عند إنشاء الشاشة.',
  'kds.setup.submit': 'اعرض الطلبات',
  'kds.setup.working': 'جارٍ التحقق من الرمز…',
  'kds.setup.refused':
    'هذا الرمز لم يعد صالحًا. أنشئ شاشة مطبخ جديدة من صفحة «شاشات المطبخ» والصق الرمز الجديد هنا.',

  // ------------------------------------------------------------------- the orders
  'kds.title': 'الطلبات',
  'kds.order': 'طلب {reference}',
  'kds.waitingLabel': 'الانتظار',
  'kds.status.waiting': 'قيد التحضير',
  'kds.status.ready': 'جاهز',
  'kds.status.completed': 'تم التسليم',
  'kds.status.cancelled': 'ملغى',
  'kds.status.expired': 'منتهي الصلاحية',
  'kds.empty.title': 'لا يوجد طلب في الانتظار.',
  'kds.empty.body': 'يظهر الطلب هنا بمجرد إنشاء جهاز نداء له.',
  'kds.empty.action': 'تحقّق الآن',
  'kds.error.title': 'لم نتمكّن من تحميل الطلبات.',
  'kds.error.body': 'لم يتغيّر شيء. حاول مرة أخرى.',
  'kds.offline.title': 'لا يوجد اتصال.',
  'kds.offline.body': 'هذه آخر الطلبات التي تمكّنّا من الوصول إليها. قد تكون حالتها تغيّرت.',
  'kds.retry': 'حاول مرة أخرى',
  'kds.throttled.title': 'جارٍ اللحاق بالتحديثات.',
  'kds.throttled.body': 'ستُحدَّث الطلبات من تلقاء نفسها بعد لحظات.',
  'kds.stale': 'آخر تحديث {time}',

  // -------------------------------------------------------------- taking it off the wall
  'kds.forget': 'إزالة رمز هذه الشاشة',
  'kds.forget.title': 'إزالة رمز هذه الشاشة؟',
  'kds.forget.body':
    'ستتوقف هذه الشاشة عن عرض الطلبات فورًا، ولا يمكن استخدام الرمز مرة أخرى. سيحتاج أحدهم إلى إنشاء شاشة مطبخ جديدة لإعادتها.',
  'kds.forget.confirm': 'إزالة الرمز',
  'kds.forget.keep': 'استمرار عرض الطلبات',

  /* ================================================================================
   * TASK 10d — الطلبات السابقة، وإصدار ورقة النداء
   * ================================================================================
   *
   * `QRPAGER-28 §3`'s zero-technical bar, in Arabic. The words deliberately NOT used:
   * «جلسة» (session), «رمز مميز» (token), «فلتر»/«مرشّح» (filter), «استعلام» (query). A date
   * range is «من» and «إلى», an order has «رقم», and a sheet has «رمز».
   *
   * ⚠ «إعادة الإصدار» AND NOT «إعادة الطباعة», which is Muhammad's ruling carried into the
   * language: a sheet is re-ISSUED, never re-printed, and the Arabic has to make the same
   * distinction the English does or the screen would promise something physically unavailable.
   */

  // ------------------------------------------------------------------------- history
  'staff.nav.history': 'الطلبات السابقة',
  'staff.history.title': 'الطلبات السابقة',
  'staff.history.lede': 'كل الطلبات في الأشهر الثلاثة الماضية. الأحدث أولًا.',
  'staff.history.filter.status': 'اعرض هذه فقط',
  'staff.history.filter.from': 'من',
  'staff.history.filter.to': 'إلى',
  'staff.history.filter.reference': 'رقم الطلب',
  'staff.history.filter.reference.hint': 'اكتب أي جزء منه.',
  'staff.history.filter.apply': 'اعرض هذه الطلبات',
  'staff.history.filter.clear': 'اعرض الكل',
  'staff.history.started': 'بدأ {time}',
  'staff.history.ready': 'جهز {time}',
  'staff.history.collected': 'تم التسليم {time}',
  'staff.history.empty.title': 'لا يوجد طلب يطابق ما طلبته.',
  'staff.history.empty.body': 'جرّب مدة أوسع، أو امسح ما اخترته.',
  'staff.history.empty.action': 'اعرض الكل',
  'staff.history.none.title': 'لا توجد طلبات بعد.',
  'staff.history.none.body': 'تظهر الطلبات هنا بعد أن تبدأ جهاز نداء لأحدها.',
  'staff.history.none.action': 'ابدأ جهاز نداء',
  'staff.history.error.title': 'لم نتمكّن من تحميل الطلبات السابقة.',
  'staff.history.error.body': 'لم يتغيّر شيء. حاول مرة أخرى.',
  'staff.history.offline.title': 'لا يوجد اتصال.',
  'staff.history.offline.body': 'حاول مرة أخرى عند عودة الشبكة.',
  'staff.history.retry': 'حاول مرة أخرى',
  'staff.history.range.tooWide': 'هذه مدة طويلة. اختر ثلاثة أشهر أو أقل.',
  'staff.history.range.backwards': 'التاريخ الثاني قبل الأول.',

  // ------------------------------------------------------- issuing a sheet (10d)
  'staff.nav.issue': 'ابدأ جهاز نداء',
  'staff.issue.title': 'ابدأ جهاز نداء',
  'staff.issue.lede': 'أعطِ الطلب رقمًا، ثم اطبع الورقة وسلّمها للعميل.',
  'staff.issue.reference.label': 'ما رقم الطلب؟',
  'staff.issue.reference.hint': 'أي رقم تستخدمه على الفاتورة، مثل A-14 أو 102.',
  'staff.issue.create': 'أنشئ الورقة',
  'staff.issue.creating': 'جارٍ إنشاء الورقة…',
  'staff.issue.sheet.title': 'اطبع هذه وسلّمها للعميل',
  'staff.issue.sheet.order': 'طلب {reference}',
  'staff.issue.sheet.instruction': 'يمسح العميل الرمز، وننبّه هاتفه عندما يصبح الطلب جاهزًا.',
  'staff.issue.sheet.print': 'اطبعها',
  'staff.issue.sheet.done': 'تم',
  'staff.issue.sheet.once':
    'يُعرض هذا الرمز مرة واحدة. إذا فقدت الورقة، افتح الطلب من «الطلبات السابقة» وأنشئ ورقة جديدة — وعندها تتوقف الورقة القديمة عن العمل.',
  'staff.issue.error.title': 'لم نتمكّن من بدء جهاز النداء.',
  'staff.issue.error.body': 'لم يتغيّر شيء. حاول مرة أخرى.',
  'staff.issue.offline.title': 'لا يوجد اتصال.',
  'staff.issue.offline.body': 'لم يبدأ شيء. حاول مرة أخرى عند عودة الشبكة.',
  'staff.issue.reference.invalid': 'رقم الطلب يقبل الحروف والأرقام والمسافات و - _ # . / فقط.',

  // ----------------------------------------------------- re-issuing a sheet (10d)
  'staff.issue.reissue': 'أنشئ ورقة جديدة لهذا الطلب',
  'staff.issue.reissue.title': 'إنشاء ورقة جديدة؟',
  // `R-232` — the English carries the measurement; the Arabic makes the same distinction
  // between «لا يمكن مسحه» (cannot be scanned) and still being notified.
  'staff.issue.reissue.body':
    'إنشاء ورقة جديدة يوقف عمل الرمز القديم: لن يتمكّن أحد من مسحه مرة أخرى. وإذا كان العميل قد مسح الورقة القديمة بالفعل فسيُبلَّغ عند جهوز طلبه. لا يمكن التراجع عن هذا.',
  'staff.issue.reissue.confirm': 'أنشئ ورقة جديدة',
  'staff.issue.reissue.keep': 'أبقِ القديمة',
  'staff.issue.reissue.working': 'جارٍ إنشاء الورقة الجديدة…',
  'staff.issue.reissue.finished':
    'هذا الطلب منتهٍ بالفعل، وورقة جديدة لن تنبّه أحدًا. ابدأ جهاز نداء جديدًا.',
  'staff.issue.reissue.expired': 'انتهت صلاحية هذا الطلب. ابدأ جهاز نداء جديدًا.',
  'staff.issue.reissue.gone': 'هذا الطلب لم يعد هنا. راجع «الطلبات السابقة».',

  /* ================================================================================
   * TASK 10e — إدارة فريق العمل
   * ================================================================================
   *
   * `QRPAGER-29 §6`'s hard half, in Arabic: the two refusals. Neither says «دور» (role),
   * «صلاحية» (privilege) or «أعلى رتبة» (senior) — each says what the person can do and what
   * to do about it, which is the same choice the English makes.
   */
  'staff.nav.team': 'فريق العمل',
  'staff.team.title': 'فريق العمل',
  'staff.team.lede': 'كل من يمكنه تسجيل الدخول إلى هذا المطعم.',
  'staff.team.you': 'هذا أنت',
  'staff.team.empty.title': 'لا يعمل أحد آخر هنا بعد.',
  'staff.team.empty.body': 'أضف شخصًا لديه حساب بالفعل ليتمكّن من تسجيل الدخول.',
  'staff.team.empty.action': 'أضف شخصًا',
  'staff.team.add': 'أضف شخصًا',
  'staff.team.add.title': 'إضافة شخص إلى هذا المطعم',
  'staff.team.add.email': 'بريده الإلكتروني',
  'staff.team.add.email.hint': 'يحتاج إلى حساب بالفعل. لن نرسل له رسالة.',
  'staff.team.add.role': 'ما الذي يمكنه فعله؟',
  'staff.team.add.submit': 'أضفه',
  'staff.team.add.working': 'جارٍ الإضافة…',
  'staff.team.add.cancel': 'إلغاء',
  'staff.team.add.unknown':
    'لم نتمكّن من إضافة هذا البريد. يحتاج إلى حساب هنا أولًا — اطلب منه التسجيل ثم أضفه.',
  'staff.team.add.invalid': 'هذا لا يبدو بريدًا إلكترونيًا.',
  'staff.team.role.change': 'تغيير ما يمكن لـ {name} فعله',
  'staff.team.role.working': 'جارٍ الحفظ…',
  'staff.team.remove': 'إزالة {name}',
  'staff.team.remove.title': 'إزالة {name}؟',
  'staff.team.remove.body':
    'لن يتمكّن من تسجيل الدخول إلى هذا المطعم بعد الآن. حسابه نفسه لا يُحذف، ويمكنك إضافته مرة أخرى.',
  'staff.team.remove.confirm': 'أزِله',
  'staff.team.remove.keep': 'أبقِه',
  'staff.team.denied.seniority':
    'لا يمكنك أن تمنح أحدًا أكثر مما تملك أنت. اطلب من المالك أن يفعل ذلك.',
  'staff.team.denied.ownRole':
    'لا يمكنك تغيير ما تستطيع فعله بنفسك. اطلب من شخص آخر هنا أن يغيّره لك.',
  'staff.team.denied.lastOwner':
    'كل مطعم يحتاج إلى مالك واحد على الأقل. اجعل شخصًا آخر مالكًا أولًا، ثم غيّر هذا.',
  'staff.team.denied.title': 'لا يمكنك تغيير فريق العمل.',
  'staff.team.denied.body': 'يمكن للمالك أو المدير فقط. اطلب ذلك من أحدهما.',
  'staff.team.error.title': 'لم نتمكّن من تحميل فريق العمل.',
  'staff.team.error.body': 'لم يتغيّر شيء. حاول مرة أخرى.',
  'staff.team.offline.title': 'لا يوجد اتصال.',
  'staff.team.offline.body': 'القائمة كما هي. حاول مرة أخرى عند عودة الشبكة.',
  'staff.team.retry': 'حاول مرة أخرى',
  'staff.team.failed': 'لم تنجح العملية. لم يتغيّر شيء — حاول مرة أخرى.',
}
