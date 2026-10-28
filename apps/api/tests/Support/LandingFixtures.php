<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Features\Landing\MediaLibrary;
use App\Models\User;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Document shapes and bucket setup shared by the Task 7 feature tests.
 *
 * A class and not file-scope functions, for the reason GuardFixtures is one: Pest ships
 * its own `fixture()` and a redeclaration takes the whole suite down with an error that
 * names the redeclaration rather than the collision.
 */
final class LandingFixtures
{
    /**
     * The bucket is infrastructure, created the way a production bucket is -- through the
     * client, by whoever owns the environment. Idempotent, because the container holds no
     * volume and a `docker compose down` takes the bucket with it.
     */
    public static function bucket(): void
    {
        $disk = MediaLibrary::disk();

        // A real instanceof and not an inline @var: MediaLibrary::disk() is typed as the
        // Filesystem CONTRACT, because nothing in app/ should depend on the driver being
        // S3 (§1: "nothing under apps/ may depend on which one is running"). Bucket
        // creation does -- it is infrastructure, not a flysystem concept -- so this is
        // the one place that asks, and it asks rather than asserting.
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new RuntimeException('the s3 disk is not the S3 driver; the suite runs against the real service');
        }

        $bucket = (string) config('filesystems.disks.s3.bucket');
        $client = $disk->getClient();

        if (! $client->doesBucketExist($bucket)) {
            $client->createBucket(['Bucket' => $bucket]);
        }
    }

    /**
     * A platform admin, with deliberately no membership anywhere.
     *
     * In the shared fixtures class and not at file scope in one test, because two Task 7
     * suites need it and a file-scope function is only defined when ITS file is loaded --
     * running one test file then failed with "Call to undefined function". GuardFixtures'
     * docblock records the same reasoning for the same shape.
     *
     * The OWNER connection, because `qrpager_app` holds no INSERT on
     * `users.is_platform_admin` (R-006): the app role cannot create a platform admin at
     * all, which is that narrowing working rather than an obstacle.
     */
    public static function platformAdmin(): User
    {
        $id = (string) Str::uuid7();

        DB::connection('pgsql_owner')->table('users')->insert([
            'id' => $id,
            'name' => 'Platform Admin',
            'email' => 'studio-admin-'.Str::lower(Str::random(10)).'@example.test',
            'is_platform_admin' => true,
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * A page using every block type and every field kind -- the document the publish and
     * fuzz tests render.
     *
     * @param  array<string, string>  $overrides  field path -> value, for the fuzz suite
     * @return array<string, mixed>
     */
    public static function fullPage(?string $mediaId = null, array $overrides = []): array
    {
        $image = $mediaId;

        $document = [
            'blocks' => [
                ['type' => 'hero', 'fields' => [
                    'heading' => 'Welcome to Olive',
                    'subheading' => 'Fish & Chips, since 1998',
                    'image' => $image,
                    'cta_label' => 'Book a table',
                    'cta_url' => 'https://bookings.example.test/olive',
                ]],
                ['type' => 'how_it_works', 'fields' => [
                    'heading' => 'How the pager works',
                    'steps' => [
                        ['title' => 'Scan', 'body' => 'Scan the code on your table.'],
                        ['title' => 'Sit', 'body' => 'Take a seat anywhere.'],
                        ['title' => 'Collect', 'body' => 'We buzz your phone.'],
                    ],
                ]],
                ['type' => 'menu_highlights', 'fields' => [
                    'heading' => 'Menu highlights',
                    'items' => [
                        ['name' => 'Cod', 'description' => 'Line caught', 'price' => '12.00', 'image' => $image],
                        ['name' => 'Haddock', 'description' => null, 'price' => null, 'image' => null],
                    ],
                ]],
                ['type' => 'gallery', 'fields' => [
                    'heading' => 'The room',
                    'images' => $image === null ? [] : [['image' => $image, 'caption' => 'The counter']],
                ]],
                ['type' => 'hours_location', 'fields' => [
                    'heading' => 'Find us',
                    'address' => '1 High Street, Leeds',
                    'hours' => [
                        ['day' => 'Monday', 'hours' => '11:00 to 21:00'],
                        ['day' => 'Tuesday', 'hours' => 'Closed'],
                    ],
                    'latitude' => 53.8,
                    'longitude' => -1.55,
                    'zoom' => 16,
                ]],
                ['type' => 'contact', 'fields' => [
                    'heading' => 'Contact',
                    'email' => 'hello@example.test',
                    'phone' => '+44 113 496 0000',
                    'links' => [['label' => 'Instagram', 'url' => 'https://instagram.example.test/olive']],
                ]],
                ['type' => 'footer', 'fields' => [
                    'text' => 'Olive, 1 High Street',
                    'links' => [['label' => 'Privacy', 'url' => 'https://example.test/privacy']],
                ]],
            ],
            'theme' => [
                'color.accent' => '#0b5fff',
                'color.background' => '#ffffff',
                'font.heading' => 'serif',
                'radius.large' => '12px',
            ],
            'seo' => [
                'title' => 'Olive — fish and chips in Leeds',
                'description' => 'Scan, sit down, and we will buzz your phone when your order is ready.',
                'og_image' => $image,
            ],
        ];

        foreach ($overrides as $path => $value) {
            self::set($document, $path, $value);
        }

        return $document;
    }

    /**
     * Every field a tenant can author, as a dotted path into fullPage().
     *
     * The fuzz suite iterates this, so a field added to BlockSchema and not here is a
     * field nothing fuzzes -- which LandingXssFuzzTest asserts against the schema rather
     * than trusting this list to be complete.
     *
     * @return list<string>
     */
    public static function authoredTextPaths(): array
    {
        return [
            'blocks.0.fields.heading',
            'blocks.0.fields.subheading',
            'blocks.0.fields.cta_label',
            'blocks.1.fields.heading',
            'blocks.1.fields.steps.0.title',
            'blocks.1.fields.steps.0.body',
            'blocks.2.fields.heading',
            'blocks.2.fields.items.0.name',
            'blocks.2.fields.items.0.description',
            'blocks.2.fields.items.0.price',
            'blocks.3.fields.heading',
            'blocks.3.fields.images.0.caption',
            'blocks.4.fields.heading',
            'blocks.4.fields.address',
            'blocks.4.fields.hours.0.day',
            'blocks.4.fields.hours.0.hours',
            'blocks.5.fields.heading',
            'blocks.5.fields.links.0.label',
            'blocks.6.fields.text',
            'blocks.6.fields.links.0.label',
            'seo.title',
            'seo.description',
        ];
    }

    /**
     * Every field that takes a URL, an address, a media id or a number.
     *
     * @return list<string>
     */
    public static function authoredNonTextPaths(): array
    {
        return [
            'blocks.0.fields.cta_url',
            'blocks.0.fields.image',
            'blocks.2.fields.items.0.image',
            'blocks.3.fields.images.0.image',
            'blocks.4.fields.latitude',
            'blocks.4.fields.longitude',
            'blocks.4.fields.zoom',
            'blocks.5.fields.email',
            'blocks.5.fields.phone',
            'blocks.5.fields.links.0.url',
            'blocks.6.fields.links.0.url',
            'seo.og_image',
        ];
    }

    /** @param array<mixed> $document */
    public static function set(array &$document, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $cursor = &$document;

        foreach ($keys as $key) {
            $key = is_numeric($key) ? (int) $key : $key;

            if (! is_array($cursor)) {
                return;
            }

            $cursor = &$cursor[$key];
        }

        $cursor = $value;
    }
}
