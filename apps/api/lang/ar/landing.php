<?php

declare(strict_types=1);

/*
 * The Landing feature's refusals in Arabic. `QRPAGER-41` part A.
 *
 * Each of these is read by the restaurant owner at the moment something of his was turned
 * away -- an image, a hostname, a publish. `§4.2`'s rule governs the wording and is why
 * several of these are not literal translations of the English beside them: *say what to do,
 * never what exists*. `en/landing.php` carries the reasoning for the file as a whole, and
 * `LandingLocalizationTest` holds the two key sets identical in both directions.
 */

return [

    'media' => [
        'library_full' => 'مكتبتك تحوي :max صورة بالفعل؛ احذف واحدة لإضافة أخرى',
        'not_stored' => 'تعذّر تخزين الصورة؛ لم يُحفظ شيء',
        'not_in_library' => 'هذه الصورة ليست في مكتبتك',
        'page_unreadable' => 'تعذّرت قراءة صفحتك الآن، فلا يمكن حذف الصور؛ صحّح الصفحة أولًا',
        'in_use_by_block' => 'هذه الصورة مستخدمة في كتلة :block؛ أزلها من هناك أولًا',
        'empty' => 'الملف فارغ',
        'too_large' => 'حجمه :size ميغابايت، والحد :max ميغابايت',
        'wrong_type' => 'هذا ملف :detected، وليس أحد أنواع الصور الخمسة التي يقبلها المحرّر',
        'svg_refused' => 'الملف SVG، لكن :refusal',
        'does_not_decode' => 'محتوياته لا تُقرأ كنوع الصورة الذي يدّعيه',
    ],

    'svg' => [
        'not_utf8' => 'ترميزه ليس UTF-8 صالحًا',
        'doctype' => 'يحمل DOCTYPE، وهذا ما لا يقبله التطبيق في ملف SVG أبدًا',
        'entity' => 'يعرّف كيانًا من كيانات XML',
        'not_well_formed' => 'ليس XML سليم البناء',
        'root_not_svg' => 'عنصره الجذر ليس SVG',
        'processing_instruction' => 'يحمل تعليمة معالجة XML',
        'foreign_namespace' => 'يدمج مستندًا من فضاء أسماء XML آخر',
        'element_refused' => 'يحتوي على عنصر <:element>، وهذا ما لا يخزّنه التطبيق في ملف SVG أبدًا',
        'attribute_refused' => 'يضبط الخاصية [:attribute]، وهذا ما لا يخزّنه التطبيق في ملف SVG أبدًا',
        'attribute_scheme' => 'خاصيته [:attribute] تحمل مخطط عنوان أو تسلسل هروب',
        'attribute_external' => 'خاصيته [:attribute] تجلب شيئًا من خارج الملف',
    ],

    'domains' => [
        'not_servable' => 'هذا ليس اسم مضيف يمكننا خدمته',
        'platform_hostname' => 'اسم المضيف هذا يخص المنصة ولا يمكن المطالبة به',
        'limit_reached' => 'لديك :max نطاقات بالفعل؛ احذف واحدًا لإضافة آخر',
        'not_available' => 'اسم المضيف هذا غير متاح',
        'not_on_account' => 'هذا النطاق ليس في حسابك',
        'bad_transition' => 'لا يمكن نقل هذا النطاق من :from إلى :to',
    ],

    'language' => [
        'not_changed' => 'لم يتغيّر هذا الإعداد',
    ],

    'publish' => [
        'live_unchanged' => 'فشل النشر — صفحتك المباشرة لم تتغيّر',
        'after_record' => 'فشل النشر — صفحتك مسجّلة كمنشورة',
        'field_invalid' => ':field لا يمكن نشره: :reason',
        'no_blocks' => 'لا توجد كتل في صفحتك بعد، فليس هناك ما يُنشر',
        'image_missing' => 'صورة تستخدمها هذه الصفحة لم تعد في مكتبتك',
        'address_not_publishable' => 'عنوان موقعك ليس عنوانًا يمكننا النشر إليه',
        'changed_while_publishing' => 'تغيّرت صفحتك أثناء النشر؛ لم يُنشر شيء، حاول مرة أخرى',
        'image_not_copied' => 'تعذّر نسخ صورة إلى موقعك، فصفحتك مسجّلة كمنشورة لكنها ليست مباشرة بعد؛ انشر مرة أخرى',
        'metadata_mismatch' => 'صفحتك مباشرة، لكن بيانات الموقع المرافقة لا تطابقها؛ انشر مرة أخرى',
        'taken_down_while_publishing' => 'أُنزلت صفحتك أثناء النشر، فلا شيء مباشر الآن؛ انشر مرة أخرى حين تكون جاهزًا',
        'another_publish_finished' => 'انتهى نشر آخر لموقعك أثناء تنفيذ هذا؛ تحقّق من صفحتك ثم انشر مرة أخرى',
        'take_down_failed' => 'تعذّر إنزال موقعك؛ صفحتك لم تتغيّر',
    ],

];
