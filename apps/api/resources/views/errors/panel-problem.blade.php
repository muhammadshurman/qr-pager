{{--
    The HTML form of a problem document, for the panel screens — QRPAGER-23 §6.1a.

    ⚠ THIS TEMPLATE RECEIVES FOUR STRINGS AND NOTHING ELSE. No exception, no Problem, no
    request: `PanelProblemPage::respond()` passes the type's own fixed title, its status, a
    status-CLASS guidance sentence and the brand. That is what makes an HTML error page safe
    here at all -- measured at 7f1c8f6, an HTML-rendered QueryException was 862,104 bytes
    carrying the SQL and a bound uuid, which is why ProblemRenderer is registered for every
    request. There is no code path from a throwable to this file.

    NO @vite AND NO PANEL LAYOUT, and that is the point rather than laziness: this page is
    what a reader sees when something is down, so it must not depend on the asset manifest,
    on a database read, or on a Livewire component resolving. Everything it needs is in the
    document. The ONE exception is the font stack, which names the approved families and
    falls back to the system face when the self-hosted woff2 cannot be fetched either.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('dir') === 'rtl' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} - {{ $brand }}</title>
    <style>
        /* Token values inline: a stylesheet is one more thing that can be unavailable on
           the page that exists for things being unavailable. */
        :root {
            color-scheme: light dark;
            --paper: {{ (string) config('design-tokens.colours.light.paper') }};
            --ink: {{ (string) config('design-tokens.colours.light.ink') }};
            --ink-mid: {{ (string) config('design-tokens.colours.light.ink-mid') }};
            --surface: {{ (string) config('design-tokens.colours.light.surface') }};
            --hairline: {{ (string) config('design-tokens.colours.light.hairline') }};
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --paper: {{ (string) config('design-tokens.colours.dark.paper') }};
                --ink: {{ (string) config('design-tokens.colours.dark.ink') }};
                --ink-mid: {{ (string) config('design-tokens.colours.dark.ink-mid') }};
                --surface: {{ (string) config('design-tokens.colours.dark.surface') }};
                --hairline: {{ (string) config('design-tokens.colours.dark.hairline') }};
            }
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: {{ (string) config('design-tokens.space.24') }};
            background: var(--paper);
            color: var(--ink);
            font-family: '{{ (string) config('design-tokens.fonts.latin.family') }}',
                {!! (string) config('design-tokens.fonts.fallback') !!};
            font-size: {{ (string) config('design-tokens.size.16') }};
            line-height: 1.5;
        }
        main {
            max-width: 42rem;
            background: var(--surface);
            border: 1px solid var(--hairline);
            border-radius: {{ (string) config('design-tokens.radius.16') }};
            padding: {{ (string) config('design-tokens.space.32') }};
        }
        h1 {
            margin: 0 0 {{ (string) config('design-tokens.space.12') }};
            font-size: {{ (string) config('design-tokens.size.28') }};
            line-height: 1.2;
        }
        p { margin: 0 0 {{ (string) config('design-tokens.space.12') }}; }
        p:last-child { margin-bottom: 0; color: var(--ink-mid); font-size: {{ (string) config('design-tokens.size.13') }}; }
    </style>
</head>
<body>
    {{-- One <main>, one <h1>: a reader using a screen reader lands on the heading, which is
         the sentence that matters. The status code is last and smallest, because it is for
         whoever they report this to and not for them. --}}
    <main>
        <h1>{{ $title }}</h1>
        <p>{{ $guidance }}</p>
        <p>Reference: {{ $status }}</p>
    </main>
</body>
</html>
