{{-- A preview of a draft that no longer validates. The field is named; the value is not.

     ⚠ `lang` AND `dir` COME FROM THE LOCALE NOW — `QRPAGER-41`. This document is rendered
     INSIDE the preview pane's iframe, which is its own browsing context, so it inherits
     nothing from the panel around it: a hard-coded `lang="en"` here put an English document
     with LTR layout inside an Arabic RTL panel, and no enumeration of the panel's own DOM
     could see it, because it is a separate document. The two are set together for the reason
     `§12` gives everywhere else -- a page with `lang` and no `dir` lays Arabic out left to
     right. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ __('filament-panels::layout.direction') === 'rtl' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('studio.preview.refused_title') }}</title><meta name="robots" content="noindex,nofollow"></head>
<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem">
<h1>{{ __('studio.preview.refused_heading') }}</h1>
<p>{{ __('studio.preview.refused_body', ['field' => $field, 'reason' => $reason]) }}</p>
<p>{{ __('studio.preview.refused_hint') }}</p>
</body>
</html>
