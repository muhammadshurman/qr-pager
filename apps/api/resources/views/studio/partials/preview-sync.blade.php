{{--
    THE PREVIEW FOLLOWS THE FORM — QRPAGER-45, and this partial is the fix for its rejection.

    Muhammad's ruling of 2026-11-11, after the delivery was rejected: *"fix at the SHARED
    level, not per page. Whatever the preview contract is — write on settled change, forget
    on save and publish — both studio pages implement it."*

    The original shipped these attributes inline on `builder.blade.php` only, so the theme
    tab had no debounce and no reload: a colour change moved nothing until Save, and after
    Save the stale key kept winning. One copy, included by every studio page that edits the
    document.

    ⚠ `input` AND `change`, ON THE WRAPPER, BECAUSE BOTH BUBBLE. One listener covers every
    field the schema renders now and every one it renders later. `change` is not redundant:
    a `<select>`, a radio and a native colour input fire it where some engines fire no
    `input` — and the arrangement radio and the theme swatches are exactly those.

    ⚠ ONE RELOAD FOR TWO RAPID CHANGES, which `clearTimeout` buys: each change cancels the
    pending call, so a burst produces exactly one request after it stops. 400ms is the
    ruling's number, and nothing is sent until the form has been quiet for the whole window.

    ⚠ THE PANE STAYS JS-FREE. `partials/preview.blade.php` is untouched — its own docblock's
    claim that it needs no JavaScript is still true, because all of it lives here.

    ⚠ THIS PARTIAL EMITS ATTRIBUTES AND DOES NOT CLOSE THE TAG. It used to end
    `reload()">`, and both call sites add their own `>` — so every studio page served a
    stray `>` as the first child of `.studio-layout`, which is `display:flex`, making it an
    anonymous flex item: a visible glyph plus an extra gap before the form column. Found by
    the §11 QA round of 2026-11-11, on a page that was correct before the partial existed.
--}}
x-data="{
            timer: null,
            reloading: false,
            schedule() {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => $wire.previewChanged(), 400);
            },
            reload() {
                if (this.reloading) return;
                const frame = this.$root.querySelector('[data-studio-preview]');
                if (! frame) return;
                this.reloading = true;
                try {
                    frame.contentWindow.location.reload();
                } catch (e) {
                    frame.src = frame.src;
                }
                setTimeout(() => this.reloading = false, 250);
            }
         }"
         x-on:input="schedule()"
         x-on:change="schedule()"
         x-on:studio-preview-updated.window="reload()"
