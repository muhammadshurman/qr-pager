<?php

declare(strict_types=1);

/*
 * ================================================================================
 * WHAT THE OWNER READS WHEN SOMETHING IS REFUSED. `QRPAGER-41` part A.
 * ================================================================================
 *
 * The sentences carried by `MediaRejected`, `DomainRefused` and `PublishFailed` -- the three
 * exceptions whose own docblocks say they exist to be SHOWN: *"with a reason `§12.3` can
 * show"*, *"a reason safe to show the uploader"*, *"the headline `§12.1` shows and the reason
 * beneath it"*. They reach the panel as a notification body, so they are owner-visible copy
 * and belong in a language file rather than at a throw site.
 *
 * ⚠ **SEPARATE FROM `studio.php` BECAUSE THE LAYER IS SEPARATE.** `studio.php` is the panel's
 * own chrome -- headings, labels, buttons, the copy a view writes. This file is the Landing
 * FEATURE's refusals, thrown by services that the panel merely displays. Keeping them apart
 * means the panel can be rebuilt without touching a refusal's wording, which is exactly what
 * `QRPAGER-41` part B is about to do.
 *
 * ⚠ **AND THEY ARE NOT LOGGED, WHICH IS WHY TRANSLATING THEM IS SAFE.** Checked before any of
 * this was written: no `Log::`, no audit write and no `report()` anywhere reads these
 * reasons. Had an operator-facing log carried them, translating would have made the log
 * speak whichever language the request happened to be in -- a real cost, and the reason this
 * note records the check rather than the conclusion alone.
 *
 * ⚠ **WHAT IS DELIBERATELY NOT HERE: `LandingDocumentInvalid`.** That exception lives in
 * `app/Domain/Landing`, and `deptrac`'s rule 1 is *"domain logic imports no framework or DB
 * code"* -- today that layer imports nothing but PHP built-ins, and `__()` would be its
 * first. Its reasons also arrive prefixed by `$e->field`, which is a raw contract path such
 * as `blocks.0.fields.heading`; translating *"is required"* while leaving that in front of it
 * fixes the smaller half of a notification that is unreadable to a non-technical owner for a
 * different reason. `§5` carries the row and the design.
 */

return [

    'media' => [
        'library_full' => 'your library already holds :max images; delete one to add another',
        'not_stored' => 'the image could not be stored; nothing was saved',
        'not_in_library' => 'that image is not in your library',
        'page_unreadable' => 'your page cannot be read right now, so images cannot be deleted; fix the page first',
        'in_use_by_block' => 'it is used by the :block block; remove it there first',
        'in_use_as_logo' => 'This image is your page logo. Remove it on the theme tab first.',
        'empty' => 'it is empty',
        'too_large' => 'it is :size MB, and the limit is :max MB',
        'wrong_type' => 'it is a :detected file, not one of the five image types this builder accepts',
        'svg_refused' => 'it is an SVG, but :refusal',
        'does_not_decode' => 'its contents do not decode as the image type they claim to be',
    ],

    'svg' => [
        'not_utf8' => 'it is not valid UTF-8',
        'doctype' => 'it carries a DOCTYPE, which this application never accepts in an SVG',
        'entity' => 'it declares an XML entity',
        'not_well_formed' => 'it is not well-formed XML',
        'root_not_svg' => 'its root element is not an SVG',
        'processing_instruction' => 'it carries an XML processing instruction',
        'foreign_namespace' => 'it mixes in a document from another XML namespace',
        'element_refused' => 'it contains a <:element> element, which this application never stores in an SVG',
        'attribute_refused' => 'it sets the attribute [:attribute], which this application never stores in an SVG',
        'attribute_scheme' => 'its [:attribute] attribute carries a scheme or an escape sequence',
        'attribute_external' => 'its [:attribute] attribute fetches something outside the file',
    ],

    'domains' => [
        'not_servable' => 'that is not a hostname we can serve',
        'platform_hostname' => 'that hostname belongs to the platform and cannot be claimed',
        'limit_reached' => 'you already have :max domains; remove one to add another',
        'not_available' => 'that hostname is not available',
        'not_on_account' => 'that domain is not on your account',
        'bad_transition' => 'that domain cannot move from :from to :to',
    ],

    'language' => [
        'not_changed' => 'that setting was not changed',
    ],

    'publish' => [
        'live_unchanged' => 'Publish failed — your live page is unchanged',
        'after_record' => 'Publish failed — your page is recorded as published',
        'field_invalid' => ':field can\'t be published: :reason',
        'no_blocks' => 'your page has no blocks yet, so there is nothing to publish',
        'image_missing' => 'an image this page uses is no longer in your library',
        'address_not_publishable' => 'your site address is not one we can publish to',
        'changed_while_publishing' => 'your page changed while it was publishing; nothing was published, try again',
        'image_not_copied' => 'an image could not be copied to your site, so your page is recorded as published but is not live yet; publish again',
        'metadata_mismatch' => 'your page is live, but the site metadata beside it does not match it; publish again',
        'taken_down_while_publishing' => 'your page was taken down while it was publishing, so nothing is live; publish again when you are ready',
        'another_publish_finished' => 'another publish for your site finished while this one was running; check your page and publish again',
        'take_down_failed' => 'your site could not be taken down; your page is unchanged',
    ],

];
