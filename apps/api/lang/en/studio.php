<?php

declare(strict_types=1);

/*
 * ================================================================================
 * THE STUDIO'S OWN COPY. `QRPAGER-41` part A.
 * ================================================================================
 *
 * Every string the restaurant owner can see in `/studio`, in one place, with `ar` beside
 * it. The panel's locale is the TENANT'S, set by `StudioLocale` from `tenants.language` --
 * there is no preference and no switcher, which is Muhammad's ruling in his own words:
 * *"`tenants.language` already exists and `dir` is already derived from it — use them; do
 * not add a setting."*
 *
 * ⚠ **THIS FILE IS THE ENGLISH ONE AND IT IS NOT DECORATION.** It carries the exact strings
 * that were hard-coded before this ticket, character for character, which is what makes the
 * conversion provable: every service-level test that asserts a refusal's wording runs under
 * the default locale and reads the same sentence it always read. A paraphrase here would
 * have turned a mechanical extraction into a silent copy change.
 *
 * ⚠ **AND IT MUST STAY A MIRROR OF `ar/studio.php`.** `StudioLocalizationTest` asserts the
 * two files have the identical key set in both directions, so a key added to one and not the
 * other fails rather than falling back to English in an Arabic panel -- which is the exact
 * defect this ticket exists to remove, arriving one key at a time.
 *
 * ⚠ **WHAT IS NOT HERE: FILAMENT'S OWN CHROME.** All ten Filament packages ship
 * `resources/lang/ar`, so `Collapse all`, `Expand all`, the block picker, clone, delete,
 * reorder and move up/down translate from the locale alone. `§4a` lists them. Re-stating
 * one here would create a second source for a string the framework already owns.
 */

return [

    'tabs' => [
        'nav_label' => 'Studio sections',
        'page' => 'Page',
        'media' => 'Media',
        'theme' => 'Theme',
        'domains' => 'Domain',
    ],

    'preview' => [
        'pane_label' => 'Page preview',
        'frame_title' => 'A preview of your page as it will be published',
        'desktop' => 'Desktop',
        'phone' => 'Phone',
        'open' => 'Open the preview',
        'opens_in_new_tab' => '(opens in a new tab)',
        'refused_title' => 'This draft cannot be previewed',
        'refused_heading' => 'This draft cannot be previewed yet',
        'refused_body' => ':field can\'t be published: :reason',
        'refused_hint' => 'Fix that field in the builder and preview again.',
    ],

    'chrome' => [
        // QRPAGER-48 item 2. Names the SCREEN, so the restaurant's name beside it is not
        // read as the product's (§12: no unexplained identifier).
        'studio' => 'Studio',
        'signed_in_as' => 'Signed in as :name',
        'published' => 'Live',
        // Said as a FACT about the page, not as an instruction: the action is beside it.
        'unpublished_changes' => 'edited since you last published',
        'saved_draft' => 'Saved as a draft',
        'not_saved' => 'Not saved yet',
    ],

    'page' => [
        'title' => 'Landing page',
        'status_heading' => 'Status',
        'not_publishable' => 'Not publishable.',
        'empty' => 'Your page has no blocks yet. Add one to get started.',
        'draft' => 'Draft — not visible to anyone yet.',
        'unpublished_changes' => 'You have changes that are not published yet.',
        'published_at' => 'Published to :hostname on :date.',
        'will_publish_to' => 'Your page will be published to :hostname.',
        'language_legend' => 'Page language',
        'language_help' => 'This sets the language and the reading direction of your published page. Publish again to apply a change to your live page.',
        'language_current' => '(Current)',
        'save' => 'Save',
        'publish' => 'Publish',
        // QRPAGER-48 item 3. Names where it goes, not what it is: a person reading this
        // has a board of live orders in mind, not a product noun (§12).
        'back_to_staff' => 'Orders board',
        'unpublish' => 'Unpublish',
        'take_down' => 'Take my site down',
    ],

    'footer' => [
        'not_publishable' => 'Cannot publish',
        'no_blocks' => 'No blocks yet',
        'unpublished_changes' => 'Unpublished changes',
        'saved' => 'Saved',
    ],

    'form' => [
        'template_label' => 'Page arrangement',
        'template_help' => 'How your blocks are laid out. Publish again to apply a change to your live page.',
        'blocks_label' => 'Blocks',
        'add_block' => 'Add a block',
        'add_item' => 'Add',
        'theme_section' => 'Theme',
        'theme_section_help' => 'A theme changes colours, fonts and spacing. It cannot change the layout.',
        'seo_section' => 'Search and sharing',
        'seo_section_help' => 'What search engines and social networks show. Structured data is derived from these and is not editable.',
        'seo_title' => 'Page title',
        'seo_description' => 'Description',
        'seo_og_image' => 'Sharing image',
    ],

    'help' => [
        'text' => 'Plain text, up to :max characters. One line.',
        'url' => 'A full https:// address.',
        'telephone' => 'Digits, spaces and hyphens, with an optional leading +.',
        'media_empty' => 'Upload an image in the media library first.',
        'number' => 'Between :min and :max.',
        'length' => 'A number and a unit: px, rem, em or %.',
        'max_characters' => 'Up to :max characters.',
    ],

    /*
     * ⚠ THE BUILDER'S BLOCK LABELS ARE HERE AND `BlockType::label()` STAYS ENGLISH, which
     * looks like a duplicate and is the opposite of one. That enum's label is carried into
     * `docs/contracts/landing-page.schema.json` as each block's `title` -- a COMMITTED
     * CONTRACT that `composer landing:check` byte-diffs, and that Task 11's renderer reads.
     * Localizing the enum would put Arabic into the schema and redden that check; localizing
     * only the panel leaves the contract alone. The enum's own docblock already says why the
     * label lives there, so the split is recorded at both ends.
     */
    'blocks' => [
        'hero' => 'Hero',
        'how_it_works' => 'How the pager works',
        'menu_highlights' => 'Menu highlights',
        'gallery' => 'Gallery',
        'hours_location' => 'Hours, location and map',
        'contact' => 'Contact and social',
        'footer' => 'Footer',
    ],

    /*
     * Field labels were DERIVED before this ticket -- `ucfirst(str_replace(['_','.'],' '))`
     * over the contract's own field names, which is how `color.background` became
     * "Color background". A derivation cannot be translated, so the names are keys now and
     * `StudioLocalizationTest` requires one per field in `BlockSchema` and per token in
     * `ThemeTokens::NAMES`: a field added later fails rather than appearing in English.
     */
    'fields' => [
        'heading' => 'Heading',
        'subheading' => 'Subheading',
        'image' => 'Image',
        'cta_label' => 'Button text',
        'cta_url' => 'Button link',
        'steps' => 'Steps',
        'title' => 'Title',
        'body' => 'Text',
        'items' => 'Items',
        'name' => 'Name',
        'description' => 'Description',
        'price' => 'Price',
        'images' => 'Images',
        'caption' => 'Caption',
        'address' => 'Address',
        'hours' => 'Hours',
        'day' => 'Day',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'zoom' => 'Zoom',
        'email' => 'Email',
        'phone' => 'Phone',
        'links' => 'Links',
        'label' => 'Label',
        'url' => 'Link',
        'text' => 'Text',
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
        // The six names are Muhammad's own, from his ruling of 2026-11-12. `muted` names its
        // coupling — one value paints secondary text and every hairline — because `§12` says
        // tell the owner what they are changing rather than hide it behind a tidier word.
        'color' => [
            'background' => 'Page background',
            'surface' => 'Card background',
            'text' => 'Text colour',
            'muted' => 'Secondary text and dividing lines',
            'accent' => 'Buttons and links',
            'accent_text' => 'Text on buttons',
        ],
        'font' => [
            'heading' => 'Heading font',
            'body' => 'Body font',
        ],
        'radius' => [
            'small' => 'Small corner radius',
            'large' => 'Large corner radius',
        ],
        'space' => [
            'section' => 'Space between sections',
        ],
        'size' => [
            'heading' => 'Heading size',
        ],
    ],

    // The sentence under each colour's name: a name says WHICH colour, a sentence says where
    // the owner will see it change.
    'tokens_help' => [
        'color' => [
            'background' => 'The colour behind everything',
            'surface' => 'The ground behind cards and raised sections',
            'text' => 'Headings and body text',
            'muted' => 'Small print, prices and the thin rules between sections',
            'accent' => 'Buttons and links',
            'accent_text' => 'The writing that sits on top of your button colour',
        ],
    ],

    'arrangements' => [
        'section' => 'Ready-made themes',
        'section_help' => 'Pick one, then adjust it below.',
        'in_use' => 'In use now',
        'note' => 'Switching a theme never removes your text or your images — it only changes the look. You can go back to Ledger at any time.',
    ],

    'logo' => [
        'section' => 'Logo',
        'section_help' => 'It appears at the top of your public page.',
        'none' => 'No logo yet.',
        'upload' => 'Upload a new logo',
        'drag' => 'Drop the file here, or click to choose one',
        'kilobytes' => ':size KB',
        'megabytes' => ':size MB',
        'remove' => 'Remove',
        'reference_only' => 'Removing the logo takes it off your page only — the image stays in your media library.',
    ],

    'colours' => [
        'section' => 'Colours',
        'section_help' => 'Tap a square to choose a colour. We check the contrast for you — if a colour is hard to read, we say so.',
        'reset' => 'Back to the default',
        'at_default' => 'Default',
        'hard_to_read_darken' => 'Hard to read against the colour beside it. Darken this one a little, or go back to the default.',
        'hard_to_read_lighten' => 'Hard to read against the colour beside it. Lighten this one a little, or go back to the default.',
    ],

    'fonts' => [
        'system' => 'System',
        'sans' => 'Sans serif',
        'serif' => 'Serif',
        'mono' => 'Monospace',
    ],

    'media' => [
        'title' => 'Media',
        'add_heading' => 'Add an image',
        'drag_here' => 'Drag an image here',
        'or' => 'or',
        'choose' => 'Choose an image',
        'formats' => 'PNG, JPEG, WebP, AVIF or script-free SVG, up to 5 MB.',
        'checked_by_contents' => 'An image is checked by its contents, not by its name.',
        'full' => 'You have reached :max images. Delete one to add another.',
        'uploading' => 'Uploading…',
        'library_heading' => 'Your images (:count of :max)',
        'empty' => 'No images yet. PNG, JPEG, WebP, AVIF or script-free SVG, up to 5 MB.',
        'delete' => 'Delete',
        'not_measured' => 'not measured',
        'kilobytes' => 'KB',
    ],

    'domains' => [
        'title' => 'Domains',
        'address_heading' => 'Your address',
        'live_at_fallback' => 'Your page is live at :hostname. Add your own domain to use it instead.',
        'published_to' => 'Your page is published to :hostname.',
        'platform_keeps' => 'The platform always keeps :hostname for you.',
        'add_heading' => 'Add a domain',
        'hostname_label' => 'Hostname',
        'hostname_help' => 'The hostname only — no https:// and no trailing slash.',
        'add_button' => 'Add domain',
        'waiting_dns' => 'Waiting for DNS.',
        'waiting_dns_body' => 'Add this TXT record at your DNS provider, then choose Verify.',
        'challenge_unavailable' => 'We cannot show the DNS record for :hostname right now. This is on our side, not yours. Reload this page in a minute; if it keeps happening, remove the domain and add it again.',
        'record_name' => 'Name:',
        'record_type' => 'Type:',
        'record_value' => 'Value:',
        'could_not_verify' => 'We could not verify :hostname: :reason',
        'dns_verified' => 'DNS verified.',
        'issuing_certificate' => 'We are issuing a certificate — this usually takes a few minutes.',
        'still_issuing' => 'Still issuing',
        'still_issuing_body' => ':minutes minutes so far. Nothing to do yet.',
        'live_at' => 'Live at :hostname.',
        'redirects_here' => ':hostname redirects here.',
        'redirects_to_live' => 'This domain redirects to your live address.',
        'challenge_not_found' => 'the challenge was not found.',
        'check_again' => 'Check again',
        'check_again_for' => ' for :hostname',
        'verify' => 'Verify',
        'verify_for' => ' :hostname',
    ],

    'theme' => [
        'clear_colour' => 'Use the default',
        'using_default' => 'Using the default',
        'title' => 'Theme',
        // QRPAGER-48 item 2. One line, plain language, saying what this screen is FOR --
        // §12's bar is a reader with zero technical background.
        'description' => 'Pick a ready-made look, then change anything you like. Every change shows in the preview straight away.',
        'save' => 'Save',
    ],

    'notifications' => [
        'choose_file_first' => 'Choose a file first',
        'image_refused' => ':name was refused',
        'image_added' => ':name was added',
        'image_not_in_library' => 'That image is not in your library',
        'image_not_deleted' => 'That image was not deleted',
        'image_deleted' => 'Image deleted',
        'language_not_available' => 'That is not one of the available languages',
        'language_saved' => 'Language saved',
        'language_saved_body' => 'Publish again to apply it to your live page.',
        'cannot_publish_field' => ':field can\'t be published',
        'draft_saved' => 'Draft saved',
        'published_to' => 'Published to :hostname',
        'take_down_failed' => 'Take-down failed — your page is still public',
        'no_longer_public' => 'Your page is no longer public',
        'theme_saved' => 'Theme saved',
        'domain_not_added' => 'That domain was not added',
        'domain_added' => 'Domain added',
        'domain_added_body' => 'Add the TXT record shown below at your DNS provider, then choose Verify.',
        'domain_not_on_account' => 'That domain is not on your account',
        'domain_not_changed' => 'That domain was not changed',
        'could_not_verify_yet' => 'We could not verify that domain yet',
        'txt_not_found' => 'The TXT record was not found.',
        'dns_verified' => 'DNS verified',
        'still_issuing' => 'Still issuing',
        'still_issuing_body' => 'Nothing to do yet. Check back in a few minutes.',
        'domain_live' => 'Your domain is live',
        'no_change' => 'No change',
    ],

];
