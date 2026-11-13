<?php

declare(strict_types=1);

namespace App\Features\Landing\Console;

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

/**
 * ONE COMMAND, ONE PLAUSIBLE RESTAURANT — `QRPAGER-48` item 1.
 *
 * ================================================================================
 * ⚠⚠ WHY THIS EXISTS, AND IT IS A PROCESS DEFECT RATHER THAN A MISSING FEATURE
 * ================================================================================
 *
 * Muhammad was asked to judge the theme tab and could not, because the tenant he was handed
 * was the **e2e staff fixture** — whose content is this suite's own debris. `HERO
 * 1794515970354` is `QRPAGER-47`'s `R-303` case (`addBlock(page, 0, 'HERO ' . Date.now())`)
 * and `SAVED 1794515913859` is `QRPAGER-45`'s preview case. Its theme was purple because a
 * colour case had left it that way.
 *
 * His rule, and the reason this file is item 1 rather than a nicety:
 *
 *   "An e2e fixture must never again be the thing I am asked to judge a design from."
 *
 * So this tenant is **separate from every fixture in `apps/api/bin`** and nothing in the test
 * suite writes to it. The fixtures are for ASSERTIONS; this is for JUDGEMENT, and the two
 * populations are kept apart deliberately.
 *
 * ================================================================================
 * NO TIMESTAMP REACHES A CONTENT FIELD, WHICH IS THE WHOLE POINT
 * ================================================================================
 *
 * Every string below is fixed. Nothing is derived from `now()`, `Str::random()` or a uuid, so
 * two runs a week apart produce the same page and a screenshot taken today is still the page
 * tomorrow. A fixture that stamps the clock into a heading is exactly what made the last
 * tenant unjudgeable.
 *
 * ================================================================================
 * IDEMPOTENT BY FIXED IDENTITY, NOT BY A LOOKUP THAT COULD MISS
 * ================================================================================
 *
 * The tenant, the owner and the membership carry FIXED uuids, so a second run updates the
 * same three rows rather than making a second restaurant. `§6` question 6 for this ticket
 * rules that out by name: *"ran twice and made two restaurants" is ruled out*.
 *
 * ⛔ LOCAL AND TESTING ONLY, failing CLOSED on an unset or unrecognised `APP_ENV` — the guard
 * every sibling in `apps/api/bin` carries since `§11` round one SEC M-6, for the same reason:
 * this writes as the database owner.
 */
final class SeedDemoTenantCommand extends Command
{
    protected $signature = 'studio:demo-tenant';

    protected $description = 'Seed the demo restaurant a design can be judged from. Local and testing only.';

    /** Fixed, so a re-run updates these rows rather than creating a second restaurant. */
    private const TENANT_ID = '01999000-0000-7000-8000-000000000001';

    private const USER_ID = '01999000-0000-7000-8000-000000000002';

    private const MEMBERSHIP_ID = '01999000-0000-7000-8000-000000000003';

    private const EMAIL = 'salma@al-sarw.demo';

    /** Named so nobody mistakes it for a credential worth protecting. */
    private const PASSWORD = 'demo-not-a-secret';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            /*
             * ⚠ LOWERCASE, AND THAT IS NOT A STYLE CHOICE (`§11` QA M-3 / SECURITY M-1).
             * `FixtureScriptGuardTest` DERIVES the set of guarded fixtures by grepping for
             * the literal `refusing to run`, so a capital R put this command outside the one
             * control that proves a guard really fires -- on top of living outside the
             * `bin/*` glob. Both halves are fixed: the string matches, and the derivation
             * now walks artisan commands too.
             *
             * The environment is QUOTED because the same test asserts the refusal names what
             * it saw: a guard that will not say why it fired is the kind somebody disables.
             */
            $this->components->error('refusing to run: this writes as the database owner and is for local and testing only.');
            $this->line("  APP_ENV is '".(string) config('app.env')."'.");

            return self::FAILURE;
        }

        try {
            $this->seedTenant();
            $this->seedOwner();
            $media = $this->seedMedia();
            $this->seedPage($media);
        } catch (Throwable $e) {
            $this->components->error('refused: '.$e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('The demo restaurant is ready.');
        $this->line('  restaurant   مطعم السَّرو');
        $this->line('  sign in at   http://localhost:3000/staff');
        $this->line('  then open    http://localhost:8000/studio/theme');
        $this->line('  email        '.self::EMAIL);
        $this->line('  password     '.self::PASSWORD);
        $this->newLine();
        $this->line('  Re-running updates this restaurant in place; it never makes a second one.');

        return self::SUCCESS;
    }

    /** The restaurant itself. `withTenant`, because `tenants` carries FORCE RLS. */
    private function seedTenant(): void
    {
        withTenant(self::TENANT_ID, function (): void {
            DB::connection('pgsql_owner')->table('tenants')->updateOrInsert(
                ['id' => self::TENANT_ID],
                [
                    'name' => 'مطعم السَّرو',
                    'slug' => 'demo-al-sarw',
                    'timezone' => 'Asia/Amman',
                    'language' => 'ar',
                ],
            );
        }, 'pgsql_owner');
    }

    /** The person who signs in. `users` is global, so no scope — `§2.4`. */
    private function seedOwner(): void
    {
        DB::connection('pgsql_owner')->table('users')->updateOrInsert(
            ['id' => self::USER_ID],
            [
                'name' => 'سَلمى حدّاد',
                'email' => self::EMAIL,
                'password' => Hash::make(self::PASSWORD),
                'is_platform_admin' => false,
            ],
        );

        withTenant(self::TENANT_ID, function (): void {
            DB::connection('pgsql_owner')->table('tenant_users')->updateOrInsert(
                ['id' => self::MEMBERSHIP_ID],
                [
                    'tenant_id' => self::TENANT_ID,
                    'user_id' => self::USER_ID,
                    'role' => TenantRole::Owner->value,
                ],
            );
        }, 'pgsql_owner');
    }

    /**
     * The imagery, through the SAME `InspectedImage` path as any upload an owner makes.
     *
     * ⚠⚠ THE FILES COME FROM A DIRECTORY AND THIS METHOD KNOWS NOTHING ELSE ABOUT THEM.
     * Muhammad's ruling of 2026-11-13: *"replacing them with real photographs later is a file
     * drop, not a code change."* `DemoMedia` is that seam — the role is the filename, so a
     * photograph dropped over `hero.jpg` becomes the hero with nothing here to edit.
     *
     * ⚠ **IDEMPOTENT BY (name, checksum), WHICH IS WHAT KEEPS A RE-RUN FROM PILING UP.** An
     * asset is re-used when BOTH its stored name and the sha256 of its bytes match, because
     * either alone is wrong: the name alone would keep a stale asset after a file is
     * replaced, and the checksum alone would collapse two roles that happen to hold identical
     * bytes. `MediaLibrary::store()` is the only writer either way, so `InspectedImage`
     * inspects these exactly as it inspects an upload — a generated file that drifted from
     * what the inspector accepts would fail HERE rather than reach the library.
     *
     * @return array{logo: ?string, hero: ?string, dishes: list<string>, gallery: list<string>}
     */
    private function seedMedia(): array
    {
        $files = DemoMedia::files();

        /*
         * ⚠ THE IMAGELESS PATH IS REFUSED RATHER THAN SEEDED (`§11` QA L-3).
         *
         * `DemoMedia::files()` answers `['logo' => null, 'hero' => null, 'dishes' => [],
         * 'gallery' => []]` when its directory is missing — a plain `is_dir()` false — and
         * every `?? null` downstream then produced a demo restaurant with no pictures at
         * all, reporting success. The whole point of this tenant is that it is what a design
         * is judged from; an imageless one is the e2e fixture again, which is the thing the
         * ticket exists to stop being judged from.
         *
         * The directory is COMMITTED (`database/demo-media`), so its absence is not a
         * configuration choice — it means the checkout is incomplete or the path moved, and
         * both are worth saying out loud. The roles are checked rather than the directory,
         * because a directory that exists and holds nothing this recognises is the same
         * failure wearing a different hat.
         */
        if ($files['logo'] === null || $files['hero'] === null || $files['dishes'] === [] || $files['gallery'] === []) {
            throw new RuntimeException(
                'no demo imagery found in '.DemoMedia::directory().
                ' — expected logo.*, hero.*, dish-*.* and gallery-*.*; run `php artisan studio:demo-imagery` to regenerate it',
            );
        }

        $library = app(MediaLibrary::class);

        $existing = withTenant(self::TENANT_ID, static fn (): array => DB::connection('pgsql_owner')
            ->table('media_assets')
            ->where('tenant_id', self::TENANT_ID)
            ->get(['id', 'original_name', 'checksum'])
            ->all(), 'pgsql_owner');

        $byKey = [];

        foreach ($existing as $row) {
            $byKey[$row->original_name.'/'.$row->checksum] = $row->id;
        }

        $reused = 0;
        $stored = 0;

        $put = function (string $path) use ($library, $byKey, &$reused, &$stored): string {
            $bytes = (string) file_get_contents($path);
            $key = MediaLibrary::safeName(basename($path)).'/'.hash('sha256', $bytes);

            if (isset($byKey[$key])) {
                $reused++;

                return (string) $byKey[$key];
            }

            $stored++;

            return $library->store(self::TENANT_ID, basename($path), $bytes);
        };

        /*
         * ⚠ NO `!== null` GUARDS ON THE LOGO AND THE HERO ANY MORE. The refusal above proves
         * both are strings by the time execution reaches here, and PHPStan says so --
         * `notIdentical.alwaysTrue` on both lines. Keeping a dead check beside a real guard
         * reads as "this might be absent", which is the state the guard exists to deny.
         */
        $ids = ['logo' => $put($files['logo']), 'hero' => $put($files['hero']), 'dishes' => [], 'gallery' => []];

        foreach ($files['dishes'] as $path) {
            $ids['dishes'][] = $put($path);
        }

        foreach ($files['gallery'] as $path) {
            $ids['gallery'][] = $put($path);
        }

        $this->line('  media: '.$stored.' stored, '.$reused.' re-used');

        return $ids;
    }

    /**
     * The page, through `LandingPages` so every value is validated by `LandingDocument`
     * rather than written past it — a demo that drifted from the schema would fail HERE
     * rather than produce a draft the studio cannot open.
     *
     * ⚠ `template` IS `rail` AND THERE IS NO `theme` MEMBER AT ALL. Muhammad's words: *"Rail
     * defaults, no overrides."* Naming the arrangement makes the Rail card read "in use now"
     * in the picker, which is what makes the theme tab judgeable; adding a colour would be an
     * override and would be the thing he could not see past last time.
     *
     * It is PUBLISHED as well as saved, so the status plate this ticket builds has a real
     * published state to show rather than only the draft one.
     */
    /** @param array{logo: ?string, hero: ?string, dishes: list<string>, gallery: list<string>} $media */
    private function seedPage(array $media): void
    {
        $pages = app(LandingPages::class);

        $pages->saveDraft(self::TENANT_ID, [
            'template' => 'rail',
            // The theme tab's logo slot, so it is not empty when the design is judged.
            'logo' => $media['logo'],
            'blocks' => [
                [
                    'type' => 'hero',
                    'fields' => [
                        'heading' => 'مطعم السَّرو',
                        'subheading' => 'مشاوي ومازات على الفحم، من سنة ١٩٨٧.',
                        // ⚠ A COMPLETE web address: `Field::url()` refuses a fragment, and
                        // `#menu` is what the first run of this command was refused for.
                        'image' => $media['hero'],
                        'cta_label' => 'احجز طاولة',
                        'cta_url' => 'https://bookings.al-sarw.demo',
                    ],
                ],
                [
                    'type' => 'how_it_works',
                    'fields' => [
                        'heading' => 'كيف بتطلب',
                        'steps' => [
                            ['title' => 'امسح الكود', 'body' => 'في كود على كل طاولة، امسحه بكاميرا تلفونك.'],
                            ['title' => 'اطلب وانت قاعد', 'body' => 'اختار من المنيو وثبّت طلبك، بدون ما تنادي حدا.'],
                            ['title' => 'بنرنّلك لما يجهز', 'body' => 'بيوصلك تنبيه على نفس الصفحة، فاضل تيجي تستلم.'],
                        ],
                    ],
                ],
                [
                    'type' => 'menu_highlights',
                    'fields' => [
                        'heading' => 'أطباق بنفتخر فيها',
                        'items' => [
                            // ⚠ PAIRED BY POSITION with `dish-N.*`, which is why `DemoMedia`
                            // sorts naturally: a dish that silently lost its picture would
                            // be a demo that cannot show a dish card doing its job.
                            ['name' => 'مسخّن رول', 'description' => 'دجاج بلدي وسمّاق وبصل، ملفوف بخبز الصاج.', 'price' => '٤٫٥٠ د.أ', 'image' => $media['dishes'][0] ?? null],
                            ['name' => 'مقلوبة دجاج', 'description' => 'رز وزهرة وباذنجان، بتنعمل كل يوم الجمعة.', 'price' => '٧٫٠٠ د.أ', 'image' => $media['dishes'][1] ?? null],
                            ['name' => 'شيش طاووق', 'description' => 'عالفحم، مع ثوم وخبز وسلطة.', 'price' => '٦٫٢٥ د.أ', 'image' => $media['dishes'][2] ?? null],
                            ['name' => 'فتّة حمص', 'description' => 'حمص وخبز محمّر وصنوبر وسمنة بلدية.', 'price' => '٣٫٧٥ د.أ', 'image' => $media['dishes'][3] ?? null],
                            ['name' => 'كنافة نابلسية', 'description' => 'بتتعمل على الطلب، بتاخد عشر دقايق.', 'price' => '٣٫٠٠ د.أ', 'image' => $media['dishes'][4] ?? null],
                        ],
                    ],
                ],
                /*
                 * ⚠ THE GALLERY EXISTS FOR THE ARRANGEMENTS, NOT FOR THE CONTENT. It is the
                 * block where `tile` lays a grid and `rail` runs full-measure crops, so a
                 * demo without one cannot show the difference the picker is FOR.
                 */
                [
                    'type' => 'gallery',
                    'fields' => [
                        'heading' => 'من المحل',
                        'images' => array_values(array_filter([
                            isset($media['gallery'][0]) ? ['image' => $media['gallery'][0], 'caption' => 'الصالة الداخلية'] : null,
                            isset($media['gallery'][1]) ? ['image' => $media['gallery'][1], 'caption' => 'الكنافة وهي بتتحمّر'] : null,
                            isset($media['gallery'][2]) ? ['image' => $media['gallery'][2], 'caption' => 'قهوة الصباح'] : null,
                        ])),
                    ],
                ],
                [
                    'type' => 'hours_location',
                    'fields' => [
                        'heading' => 'أوقات الدوام والموقع',
                        'address' => 'جبل اللويبدة، شارع الكلية العلمية الإسلامية، عمّان',
                        'hours' => [
                            ['day' => 'السبت', 'hours' => '١٢:٠٠ ظهرًا – ١٢:٠٠ منتصف الليل'],
                            ['day' => 'الأحد', 'hours' => '١٢:٠٠ ظهرًا – ١١:٠٠ مساءً'],
                            ['day' => 'الإثنين', 'hours' => '١٢:٠٠ ظهرًا – ١١:٠٠ مساءً'],
                            ['day' => 'الثلاثاء', 'hours' => '١٢:٠٠ ظهرًا – ١١:٠٠ مساءً'],
                            ['day' => 'الأربعاء', 'hours' => '١٢:٠٠ ظهرًا – ١١:٠٠ مساءً'],
                            ['day' => 'الخميس', 'hours' => '١٢:٠٠ ظهرًا – ١٢:٠٠ منتصف الليل'],
                            ['day' => 'الجمعة', 'hours' => '١:٠٠ بعد الظهر – ١٢:٠٠ منتصف الليل'],
                        ],
                    ],
                ],
                [
                    'type' => 'contact',
                    'fields' => [
                        'heading' => 'تواصل معنا',
                        'phone' => '+962 6 461 2871',
                        'email' => 'hala@al-sarw.demo',
                        'links' => [
                            ['label' => 'إنستغرام', 'url' => 'https://instagram.com/al.sarw.amman'],
                        ],
                    ],
                ],
                [
                    'type' => 'footer',
                    'fields' => [
                        'text' => 'مطعم السَّرو — جبل اللويبدة، عمّان.',
                    ],
                ],
            ],
            'seo' => [
                'title' => 'مطعم السَّرو — مشاوي ومازات في جبل اللويبدة',
                'description' => 'مشاوي ومازات على الفحم في جبل اللويبدة من سنة ١٩٨٧. امسح الكود من طاولتك واطلب.',
            ],
        ]);

        $pages->publish(self::TENANT_ID);
    }
}
