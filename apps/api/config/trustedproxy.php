<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | WHAT THIS DECIDES is what $request->ip() returns, and therefore what the per-IP
    | rate limit actually counts.
    |
    | Unset, Laravel trusts no proxy and ip() is the raw REMOTE_ADDR -- measured at
    | cb584c3: with REMOTE_ADDR=10.0.0.7 and X-Forwarded-For: 203.0.113.9, ip()
    | returned 10.0.0.7. Behind the locked topology (CloudFront -> ALB -> ECS Fargate,
    | §1 IaC) REMOTE_ADDR is the ALB's own ENI, so `ratelimit:api:ip:<hash>` collapses
    | to ONE bucket per ALB node shared by every client on the task. Two consequences,
    | and the second is worse than the first: the per-IP limit that §4 › Edge & ops
    | requires would not exist, and the limiter would invert into a shared-fate denial
    | of service -- roughly 30 unknown-path requests a minute from one host locks every
    | other client out of the 404 bucket. Security finding #2.
    |
    | NOT A CATCH-ALL, AND THAT IS THE POINT OF HAVING A VALUE HERE AT ALL. Trusting
    | every peer makes X-Forwarded-For attacker-controlled, and the per-IP limit is then
    | bypassed by rotating one header -- strictly worse than the bug it appears to fix.
    | BootChecks refuses to serve on every spelling of it and on an empty value for the
    | reason above, so neither mistake can reach production quietly.
    |
    | THERE ARE FOUR SPELLINGS and this file documented one of them as valid for a
    | round. '*' and '**' are the obvious two. A '/0' prefix is a third: Symfony's
    | IpUtils matches every peer of that family whatever the base address, so
    | `10.0.0.0/0` is as total as `0.0.0.0/0`. The fourth is the literal `REMOTE_ADDR`,
    | which reads like a restriction and is the opposite -- Laravel resolves it to the
    | CURRENT peer, so the trusted list is whoever is connecting. Measured 2026-10-27
    | with peer 198.51.100.77 and `X-Forwarded-For: 203.0.113.9`: ip() was 203.0.113.9
    | under all four, and the peer's own address under a CIDR it sits outside. All four
    | are refused.
    |
    | THE SINGLE-INGRESS ASSUMPTION, written down because the CIDR below is only sound
    | while it holds: THE TASK ACCEPTS TRAFFIC FROM THE LOAD BALANCER AND FROM NOTHING
    | ELSE. A security group that opens the task port to anything other than the ALB's
    | group turns this value into a header an attacker can set, because they would then
    | be the trusted peer. Task 13 owns the VPC and therefore owns both halves: the real
    | CIDR, and the security group that makes it true. Neither half is useful alone.
    |
    | Comma-separated. Each entry is a bare address or a CIDR with a prefix of at least
    | 1. BootChecks validates every entry, because an entry that does not parse is
    | silently inert inside Symfony's IpUtils -- which would put us back in the first
    | paragraph with nothing in any log to say so.
    |
    | AND IT IS NOT THE ONLY SOURCE. `$middleware->trustProxies(at: ...)` in
    | bootstrap/app.php wins over this file inside TrustProxies, so BootChecks reads
    | both through `ResolvedTrustedProxies` rather than trusting that this file is the
    | whole answer. Set the value here; the boot check will catch it either way.
    |
    | Local and testing are exempt: there is no proxy in front of either, so the peer
    | IS the client and trusting nothing is the correct answer.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
