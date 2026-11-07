{{--
    What a SUPERSEDED hostname serves.

    §3 row 7: "custom domain issues TLS automatically and the old domain redirects". The
    old domain's prefix keeps an index.html, and this is it -- a canonical link, a meta
    refresh and a visible link, so the redirect works with and without JavaScript and
    tells a search engine which hostname is the real one.

    ⚠ A STATIC OBJECT CANNOT SEND A 301, and this file is honest about that rather than
    the handover claiming one. A proper 301 at the edge is a CloudFront function or a
    bucket redirection rule, both of which are Terraform and therefore Task 13's (SR-2).
    What this task owns is that the old hostname stops serving the page and points at the
    new one, which is what these three mechanisms do.

    The hostname reaching here has been through tenant_domains_hostname_is_dns_name and
    PublishedSite::isSafeHostname, and it is STILL escaped by {{ }} -- the same two
    independent halves as everywhere else in this task (D5).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Moved to {{ $hostname }}</title>
<meta http-equiv="refresh" content="0; url=https://{{ $hostname }}/">
<link rel="canonical" href="https://{{ $hostname }}/">
<meta name="robots" content="noindex">
</head>
<body>
<p>This page has moved to <a href="https://{{ $hostname }}/">{{ $hostname }}</a>.</p>
</body>
</html>
