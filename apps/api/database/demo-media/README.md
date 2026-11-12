# The demo restaurant's imagery

These are **placeholders**, and they are generated — not photographs of anything.

**Real photographs arrive with the first real restaurant, not from a stock source.** That is
where pictures that are actually *of* the food will come from anyway, and it is the only
source that does not hand somebody else's licence to whoever inherits this repository.

## Why not stock photography

Ruled by Muhammad on 2026-11-13, and the reason is not aesthetic:

> `§10` delivers this repo to the client by Transfer Ownership. Committing megabytes of
> third-party photography, with attribution obligations attached, into a repository someone
> else inherits is a liability they never asked for — for a demo seeder.

Wikimedia Commons was reachable and was **not** used, deliberately.

## Why not flat colour blocks either

`QRPAGER-46`'s captures used flat fixtures, and they made the three arrangements impossible
to judge:

> a photo grid and a column of full-width bands both read as slabs of colour, so I could not
> tell whether `tile` and `rail` actually differ in a way a restaurant owner would see.

That is a real property of the arrangements rather than a taste. So these carry what a
photograph carries and a swatch does not — a subject against a ground, varied luminance,
mid-frequency detail, grain, a vignette — and, most importantly, **varied aspect ratios**,
because a grid of squares and a column of full-measure crops is exactly where the
arrangements diverge.

They are abstract. Nobody would mistake one for kunafa. They are honest about being
placeholders while still being judgeable, which flat blocks were not.

## Replacing them is a file drop, not a code change

`studio:demo-tenant` reads this directory and knows nothing about how the files were made.
**The role is the filename:**

| pattern | used as |
| --- | --- |
| `logo.*` | the tenant's logo, in the theme tab's logo slot |
| `hero.*` | the hero block's image |
| `dish-N.*` | the menu highlights, paired by position |
| `gallery-N.*` | the gallery block, in order |

The extension is free — a `.png` may replace a `.jpg`. Drop files in, re-run
`php artisan studio:demo-tenant`, and the document references the new assets. Nothing in the
seeder changes, and the generator never has to run again.

## Regenerating

`php artisan studio:demo-imagery` rewrites only files that are missing; pass `--force` to
overwrite. Every image is a pure function of its seed, so a regeneration is byte-identical
and the seeder stays idempotent. **Existing files are never overwritten without `--force`, so
a real photograph dropped in place survives.**
