<?php

declare(strict_types=1);

/*
 * ================================================================================
 * THE STUDIO IN ARABIC. `QRPAGER-41` part A.
 * ================================================================================
 *
 * The restaurant owner's own language, and the DEFAULT one: `TenantLanguage::DEFAULT` is
 * `ar`, so a restaurant created with no language stated reads this file and not the English
 * beside it. That is the reason the half-English panel was a defect rather than a polish
 * item -- it was what every new tenant saw.
 *
 * ⚠ **KEY PARITY WITH `en/studio.php` IS A TEST, NOT A HABIT.** `StudioLocalizationTest`
 * compares the two key sets in both directions. Laravel falls back to the English string for
 * a missing key, which renders an English sentence inside an Arabic panel and raises no
 * error anywhere -- the exact defect this ticket removes, arriving one key at a time.
 *
 * ⚠ **THE LANGUAGE PICKER'S OPTION LABELS ARE NOT HERE, AND THAT IS `§12`'s RULING.**
 * `العربية` and `English` are each written in their OWN language, because a picker that
 * renders every option in one language is the one control where translating makes the choice
 * harder. They live in the view with `lang` and `dir` on each, and they are an ALLOW-LIST
 * entry in the enumeration test with that reason recorded beside them.
 */

return [

    'tabs' => [
        'nav_label' => 'أقسام الاستوديو',
        'page' => 'الصفحة',
        'media' => 'الوسائط',
        'theme' => 'الثيم',
        'domains' => 'النطاق',
    ],

    'preview' => [
        'pane_label' => 'معاينة الصفحة',
        'frame_title' => 'معاينة الصفحة كما ستُنشر',
        'desktop' => 'سطح المكتب',
        'phone' => 'جوال',
        'open' => 'افتح المعاينة',
        'opens_in_new_tab' => '(يفتح في تبويب جديد)',
        'refused_title' => 'لا يمكن معاينة هذه المسودة',
        'refused_heading' => 'لا يمكن معاينة هذه المسودة بعد',
        'refused_body' => ':field لا يمكن نشره: :reason',
        'refused_hint' => 'صحّح هذا الحقل في المحرّر ثم عاين مرة أخرى.',
    ],

    'page' => [
        'title' => 'الصفحة',
        'status_heading' => 'الحالة',
        'not_publishable' => 'لا يمكن النشر.',
        'empty' => 'لا توجد كتل في صفحتك بعد. أضف واحدة للبدء.',
        'draft' => 'مسودة — لا يراها أحد بعد.',
        'unpublished_changes' => 'لديك تغييرات لم تُنشر بعد.',
        'published_at' => 'نُشرت على :hostname بتاريخ :date.',
        'will_publish_to' => 'ستُنشر صفحتك على :hostname.',
        'language_legend' => 'لغة الصفحة',
        'language_help' => 'يحدد هذا لغة صفحتك المنشورة واتجاه القراءة فيها. انشر مرة أخرى لتطبيق التغيير على صفحتك المباشرة.',
        'language_current' => '(الحالية)',
        'save' => 'احفظ',
        'publish' => 'انشر',
        'unpublish' => 'ألغِ النشر',
        'take_down' => 'أنزِل موقعي',
    ],

    'footer' => [
        'not_publishable' => 'لا يمكن النشر',
        'no_blocks' => 'لا توجد كتل بعد',
        'unpublished_changes' => 'تغييرات غير منشورة',
        'saved' => 'محفوظ',
    ],

    'form' => [
        'template_label' => 'ترتيب الصفحة',
        'template_help' => 'كيف تُرتَّب كتلك. انشر مرة أخرى لتطبيق التغيير على صفحتك المباشرة.',
        'blocks_label' => 'الكتل',
        'add_block' => 'أضف كتلة',
        'add_item' => 'أضف',
        'theme_section' => 'الثيم',
        'theme_section_help' => 'يغيّر الثيم الألوان والخطوط والمسافات. ولا يغيّر التخطيط.',
        'seo_section' => 'البحث والمشاركة',
        'seo_section_help' => 'ما تعرضه محركات البحث وشبكات التواصل. تُشتق البيانات المنظمة منها ولا تُحرَّر.',
        'seo_title' => 'عنوان الصفحة',
        'seo_description' => 'الوصف',
        'seo_og_image' => 'صورة المشاركة',
    ],

    'help' => [
        'text' => 'نص عادي، حتى :max حرفًا. سطر واحد.',
        'url' => 'عنوان https:// كامل.',
        'telephone' => 'أرقام ومسافات وشرطات، مع + اختيارية في البداية.',
        'media_empty' => 'ارفع صورة في مكتبة الوسائط أولًا.',
        'number' => 'بين :min و:max.',
        'length' => 'رقم ووحدة: px أو rem أو em أو %.',
        'max_characters' => 'حتى :max حرفًا.',
    ],

    'blocks' => [
        'hero' => 'الواجهة',
        'how_it_works' => 'كيف يعمل النداء',
        'menu_highlights' => 'أشهر الأطباق',
        'gallery' => 'معرض الصور',
        'hours_location' => 'الدوام والموقع والخريطة',
        'contact' => 'التواصل',
        'footer' => 'التذييل',
    ],

    'fields' => [
        'heading' => 'العنوان',
        'subheading' => 'العنوان الفرعي',
        'image' => 'الصورة',
        'cta_label' => 'نص الزر',
        'cta_url' => 'رابط الزر',
        'steps' => 'الخطوات',
        'title' => 'العنوان',
        'body' => 'النص',
        'items' => 'العناصر',
        'name' => 'الاسم',
        'description' => 'الوصف',
        'price' => 'السعر',
        'images' => 'الصور',
        'caption' => 'التعليق',
        'address' => 'عنوان المكان',
        'hours' => 'أوقات الدوام',
        'day' => 'اليوم',
        'latitude' => 'خط العرض',
        'longitude' => 'خط الطول',
        'zoom' => 'مستوى التقريب',
        'email' => 'البريد الإلكتروني',
        'phone' => 'الهاتف',
        'links' => 'الروابط',
        'label' => 'النص',
        'url' => 'الرابط',
        'text' => 'النص',
    ],

    /*
     * ⚠ NESTED, BECAUSE THE TOKEN NAMES CONTAIN DOTS AND LARAVEL READS A DOT AS A PATH.
     * `ThemeTokens::NAMES` is keyed `color.background`, and `__('studio.tokens.color.background')`
     * resolves file `studio` then descends `tokens` → `color` → `background`. A flat key
     * spelled `'color.background'` inside `tokens` is never reached by that descent, and
     * Laravel's miss is SILENT: it returns the key itself, so the theme tab rendered the
     * literal text `studio.tokens.color.background` as a field label. Measured, not reasoned
     * about -- the enumeration probe printed all twelve of them.
     */
    'tokens' => [
        /*
         * ⚠ THE SIX COLOUR NAMES ARE MUHAMMAD'S OWN, GIVEN WITH HIS RULING OF 2026-11-12, and
         * `muted` is the one worth reading twice: *"muted is named honestly, coupling included
         * — §12 says tell the owner what they are changing, not hide it behind a tidier
         * word."* That colour paints secondary text AND every hairline on the page, so the
         * name says both. The row that would split them is a `§5` row, not a rename here.
         */
        'color' => [
            'background' => 'خلفية الصفحة',
            'surface' => 'خلفية البطاقات',
            'text' => 'لون الكتابة',
            'muted' => 'لون الكتابة الثانوية والخطوط الفاصلة',
            'accent' => 'لون الأزرار والروابط',
            'accent_text' => 'لون الكتابة فوق الأزرار',
        ],
        'font' => [
            'heading' => 'خط العناوين',
            'body' => 'خط النص',
        ],
        'radius' => [
            'small' => 'استدارة صغيرة',
            'large' => 'استدارة كبيرة',
        ],
        'space' => [
            'section' => 'المسافة بين الأقسام',
        ],
        'size' => [
            'heading' => 'حجم العناوين',
        ],
    ],

    /*
     * The sentence under each colour's name — `QRPAGER-47` Part B item 3. The artboard puts
     * one on every row, and `§12`'s bar is why: a name tells an owner WHICH colour, a sentence
     * tells them WHERE they will see it change.
     */
    'tokens_help' => [
        'color' => [
            'background' => 'اللون اللي ورا كل شي',
            'surface' => 'خلفية الكروت والأقسام المرفوعة',
            'text' => 'العناوين والنصوص',
            'muted' => 'الشرح الصغير والأسعار والخطوط الرقيقة',
            'accent' => 'الأزرار والروابط',
            'accent_text' => 'الكتابة اللي بتظهر فوق لون الأزرار',
        ],
    ],

    'arrangements' => [
        'section' => 'الثيمات الجاهزة',
        'section_help' => 'اختار واحد، وبعدها عدّل عليه تحت.',
        'in_use' => 'مستخدم الآن',
        'note' => 'تبديل الثيم ما بيمسح نصوصك ولا صورك — بس يغيّر الشكل. فيك ترجع للسِجِل في أي وقت.',
    ],

    'logo' => [
        'section' => 'الشعار',
        'section_help' => 'بيظهر فوق صفحتك العامة.',
        'none' => 'ما في شعار بعد.',
        'upload' => 'ارفع شعار جديد',
        'drag' => 'اسحب الملف لهون، أو اضغط لتختار',
        'kilobytes' => ':size ك.ب',
        'megabytes' => ':size م.ب',
        'remove' => 'احذفه',
        'reference_only' => 'حذف الشعار بيشيله من صفحتك بس — الصورة تبقى في مكتبة الوسائط.',
    ],

    'colours' => [
        'section' => 'الألوان',
        'section_help' => 'اضغط على المربّع لتختار لون. منفحص التباين لحالنا — إذا اللون صعب القراءة، منقلك.',
        'reset' => 'رجّع الافتراضي',
        'at_default' => 'الافتراضي',
        'hard_to_read_darken' => 'صعبة القراءة مع اللون اللي جانبها. غمّق هاد اللون شوي، أو ارجع للافتراضي.',
        'hard_to_read_lighten' => 'صعبة القراءة مع اللون اللي جانبها. فتّح هاد اللون شوي، أو ارجع للافتراضي.',
    ],

    'fonts' => [
        'system' => 'خط النظام',
        'sans' => 'بلا زخارف',
        'serif' => 'بزخارف',
        'mono' => 'ثابت العرض',
    ],

    'media' => [
        'title' => 'الوسائط',
        'add_heading' => 'أضف صورة',
        'drag_here' => 'اسحب صورة إلى هنا',
        'or' => 'أو',
        'choose' => 'اختر صورة',
        'formats' => 'PNG أو JPEG أو WebP أو AVIF أو SVG بلا سكربتات، حتى ٥ ميغابايت.',
        'checked_by_contents' => 'تُفحص الصورة بمحتواها لا باسمها.',
        'full' => 'وصلت إلى :max صورة. احذف واحدة لإضافة أخرى.',
        'uploading' => 'جارٍ الرفع…',
        'library_heading' => 'صورك (:count من :max)',
        'empty' => 'لا توجد صور بعد. PNG أو JPEG أو WebP أو AVIF أو SVG بلا سكربتات، حتى ٥ ميغابايت.',
        'delete' => 'احذف',
        'not_measured' => 'غير مقاسة',
        'kilobytes' => 'كيلوبايت',
    ],

    'domains' => [
        'title' => 'النطاق',
        'address_heading' => 'عنوانك',
        'live_at_fallback' => 'صفحتك مباشرة على :hostname. أضف نطاقك الخاص لاستخدامه بدلًا منه.',
        'published_to' => 'صفحتك منشورة على :hostname.',
        'platform_keeps' => 'تحتفظ المنصة دائمًا بـ :hostname لك.',
        'add_heading' => 'أضف نطاقًا',
        'hostname_label' => 'اسم المضيف',
        'hostname_help' => 'اسم المضيف فقط — بلا https:// وبلا شرطة مائلة في النهاية.',
        'add_button' => 'أضف النطاق',
        'waiting_dns' => 'بانتظار DNS.',
        'waiting_dns_body' => 'أضف سجل TXT هذا لدى مزوّد DNS، ثم اختر «تحقّق».',
        'challenge_unavailable' => 'لا يمكننا عرض سجل DNS الخاص بـ :hostname الآن. هذا من جانبنا لا من جانبك. أعد تحميل الصفحة بعد دقيقة؛ وإن تكرر الأمر، احذف النطاق ثم أضفه من جديد.',
        'record_name' => 'الاسم:',
        'record_type' => 'النوع:',
        'record_value' => 'القيمة:',
        'could_not_verify' => 'تعذّر التحقق من :hostname: :reason',
        'dns_verified' => 'تم التحقق من DNS.',
        'issuing_certificate' => 'نُصدر شهادة — يستغرق ذلك عادة بضع دقائق.',
        'still_issuing' => 'ما زال الإصدار جاريًا',
        'still_issuing_body' => 'مضت :minutes دقيقة حتى الآن. لا شيء عليك فعله بعد.',
        'live_at' => 'مباشر على :hostname.',
        'redirects_here' => ':hostname يحوّل إلى هنا.',
        'redirects_to_live' => 'يحوّل هذا النطاق إلى عنوانك المباشر.',
        'challenge_not_found' => 'لم يُعثر على سجل التحقق.',
        'check_again' => 'تحقّق مرة أخرى',
        'check_again_for' => ' من :hostname',
        'verify' => 'تحقّق',
        'verify_for' => ' من :hostname',
    ],

    'theme' => [
        'clear_colour' => 'استخدم الافتراضي',
        'using_default' => 'الافتراضي',
        'title' => 'الثيم',
        'save' => 'احفظ',
    ],

    'notifications' => [
        'choose_file_first' => 'اختر ملفًا أولًا',
        'image_refused' => 'رُفضت :name',
        'image_added' => 'أُضيفت :name',
        'image_not_in_library' => 'هذه الصورة ليست في مكتبتك',
        'image_not_deleted' => 'لم تُحذف الصورة',
        'image_deleted' => 'حُذفت الصورة',
        'language_not_available' => 'هذه ليست إحدى اللغات المتاحة',
        'language_saved' => 'حُفظت اللغة',
        'language_saved_body' => 'انشر مرة أخرى لتطبيقها على صفحتك المباشرة.',
        'cannot_publish_field' => ':field لا يمكن نشره',
        'draft_saved' => 'حُفظت المسودة',
        'published_to' => 'نُشرت على :hostname',
        'take_down_failed' => 'فشل الإنزال — صفحتك ما زالت عامة',
        'no_longer_public' => 'لم تعد صفحتك عامة',
        'theme_saved' => 'حُفظ الثيم',
        'domain_not_added' => 'لم يُضف النطاق',
        'domain_added' => 'أُضيف النطاق',
        'domain_added_body' => 'أضف سجل TXT الظاهر أدناه لدى مزوّد DNS، ثم اختر «تحقّق».',
        'domain_not_on_account' => 'هذا النطاق ليس في حسابك',
        'domain_not_changed' => 'لم يتغيّر النطاق',
        'could_not_verify_yet' => 'تعذّر التحقق من النطاق بعد',
        'txt_not_found' => 'لم يُعثر على سجل TXT.',
        'dns_verified' => 'تم التحقق من DNS',
        'still_issuing' => 'ما زال الإصدار جاريًا',
        'still_issuing_body' => 'لا شيء عليك فعله بعد. تحقّق مرة أخرى بعد بضع دقائق.',
        'domain_live' => 'نطاقك مباشر',
        'no_change' => 'لا تغيير',
    ],

];
