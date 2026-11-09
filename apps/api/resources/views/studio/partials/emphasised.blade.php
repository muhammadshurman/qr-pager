{{--
    One translated sentence with ONE value emphasised inside it, and no raw echo.

    ================================================================================
    ⚠ THIS EXISTS BECAUSE `{!! !!}` IS REFUSED IN THIS APPLICATION, AND RIGHTLY
    ================================================================================

    `QRPAGER-41` first wrote these sentences as `{!! __('…', ['hostname' => '<strong>'.e($h).'</strong>']) !!}`
    with a comment arguing the interpolation was pre-escaped. `RepoGuardTest` reddened on
    the spot: *"No view in this application performs a raw echo. That is a stronger property
    than the one-exception version"* — and the stronger property is worth more than the
    argument, because the next person to reach for `{!! !!}` will have a comment of their own.

    ⚠ AND SPLITTING THE SENTENCE IN THE VIEW IS NOT THE ALTERNATIVE. Writing
    `Your page will be published to <strong>{{ $h }}</strong>.` as three nodes hard-codes
    ENGLISH WORD ORDER: Arabic puts the hostname in a different place in the same sentence,
    and a translated string that may only be used end-to-end is not translated at all.

    So the sentence stays whole and the view splits it ON ITS OWN PLACEHOLDER. Every part is
    echoed with `{{ }}`, so the value is escaped by Blade exactly as anywhere else, and the
    emphasis lands wherever the translator put `:placeholder`.

    @param string $line         the translated template, e.g. __('studio.page.will_publish_to')
    @param string $placeholder  the token inside it, e.g. ':hostname'
    @param string $value        the value to emphasise
--}}
@php($parts = explode($placeholder, $line, 2))
{{ $parts[0] }}<strong>{{ $value }}</strong>{{ $parts[1] ?? '' }}
