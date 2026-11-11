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
         x-on:studio-preview-updated.window="reload()">
