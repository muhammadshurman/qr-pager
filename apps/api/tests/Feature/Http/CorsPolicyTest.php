<?php

declare(strict_types=1);

/*
 * ================================================================================
 * CORS IS CLOSED, AND THIS FILE IS WHY IT IS A CONTROL RATHER THAN A VACANCY
 * ================================================================================
 *
 * Task 12. `config/cors.php` carries the reasoning; this drives it.
 *
 * ⚠ THIS FILE EXISTS BECAUSE THE REPOSITORY'S OWN GUARD DEMANDED IT, which is worth recording
 * rather than tidying away. `config/cors.php`'s docblock cited `CorsPolicyTest`, the case was
 * actually written inside `ResponseHeaderTest`, and `RepoGuardTest` went red with
 * *"CorsPolicyTest (cited by config/cors.php)"* — a citation to a control that is not where it
 * says it is, which is `R-136`'s shape and was `8a`'s first blocking finding. The guard was
 * right: the fix is the file, not the citation.
 */

it('answers no CORS header to a cross-origin preflight, on any surface', function (): void {
    /*
     * ⚠ CLOSED BY DECISION NOW, NOT BY THE ABSENCE OF A FILE. Until Task 12 there was no
     * `config/cors.php`, so `cors.paths` was empty and `HandleCors` matched nothing -- which
     * the security review of `task-2` measured and recorded as `R-038`. Inert and safe is the
     * right posture; inert BY ACCIDENT is not a posture, and it is one
     * `vendor:publish --tag=cors` away from Laravel's shipped default of
     * `'allowed_origins' => ['*']` over `api/*`.
     *
     * Driven rather than read, because `§8` does not accept a configuration as a control, and
     * because `paths` being empty is exactly the kind of thing that is true until someone adds
     * one path for one reason.
     */
    $hostile = 'https://evil.example.com';

    foreach (['/v1/members', '/v1/auth/me', '/v1/pager-sessions', '/healthz'] as $path) {
        $preflight = $this->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => $hostile,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        expect($preflight->headers->get('Access-Control-Allow-Origin'))
            ->toBeNull("[{$path}] answers a preflight from {$hostile}")
            ->and($preflight->headers->get('Access-Control-Allow-Credentials'))
            ->toBeNull("[{$path}] would let {$hostile} read a credentialed response");

        // And the real request, not only the preflight: a surface that refuses the preflight
        // and then answers the GET with an allow-origin header is still readable by a
        // browser that skipped the preflight (a simple GET needs none).
        $get = $this->call('GET', $path, [], [], [], ['HTTP_ORIGIN' => $hostile]);

        expect($get->headers->get('Access-Control-Allow-Origin'))
            ->toBeNull("[{$path}] returns an allow-origin header on a simple cross-origin GET");
    }

    // The configuration is empty AND credentials are off, asserted together: `paths` is what
    // is load-bearing today, and `supports_credentials` is what a future diff that adds a
    // path would inherit. Both must be the fail-closed value.
    expect(config('cors.paths'))->toBe([])
        ->and(config('cors.allowed_origins'))->toBe([])
        ->and(config('cors.supports_credentials'))->toBeFalse();
});
