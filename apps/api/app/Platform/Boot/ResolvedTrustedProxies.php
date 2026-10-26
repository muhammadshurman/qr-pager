<?php

declare(strict_types=1);

namespace App\Platform\Boot;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * The trusted-proxy value that will really be in force, read the way TrustProxies
 * itself reads it.
 *
 * THERE ARE TWO SOURCES FOR ONE VALUE and the boot check was on the wrong one until
 * 2026-10-27. `TrustProxies::setTrustedProxyIpAddresses()` is
 * `$this->proxies() ?: config('trustedproxy.proxies')`, and `proxies()` returns
 * `static::$alwaysTrustProxies` first -- the value `$middleware->trustProxies(at: ...)`
 * writes in `bootstrap/app.php`. So a check reading only the config could not see the
 * one call a maintainer actually reaches for. Measured with the config at `10.0.0.0/8`
 * and `TrustProxies::at('*')`: the clause returned no failures while `$request->ip()`
 * was the caller's own `X-Forwarded-For`. That is QA H-1's shape -- a control asserted
 * against one copy of a value the real code takes from another -- one round after
 * QA H-1.
 *
 * A SUBCLASS AND NOT REFLECTION, because the precedence is the thing being mirrored and
 * a subclass inherits it rather than restating it: `$alwaysTrustProxies` is a protected
 * static on the parent, so this reads the same storage the real middleware writes, and
 * if the framework reorders those two sources this follows. A reflection read of one
 * named property would silently stop mirroring.
 *
 * Never registered as middleware, and it must not be: the global stack carries the
 * framework's own `TrustProxies`. This class exists to be *asked*, once, at boot.
 */
final class ResolvedTrustedProxies extends TrustProxies
{
    /**
     * Whatever TrustProxies would use for this process: the `at()` override if one was
     * set, otherwise the config. Returned unnormalised -- a comma-separated string, an
     * array, `'*'` or null -- because the caller validates every shape Laravel accepts.
     */
    public static function asTrustProxiesReadsThem(): mixed
    {
        return (new self)->proxies() ?: config('trustedproxy.proxies');
    }
}
