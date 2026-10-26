<?php

declare(strict_types=1);

namespace App\Platform\Boot;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * The trusted-proxy value that will really be in force, read the way TrustProxies
 * itself reads it.
 *
 * THERE ARE THREE SOURCES FOR ONE VALUE AND THIS SEES TWO OF THEM. Corrected 2026-10-27
 * by the second review of the fix diff, which measured the claim this paragraph used to
 * make ("two sources"). `TrustProxies::setTrustedProxyIpAddresses()` is
 * `$this->proxies() ?: config('trustedproxy.proxies')`, and `proxies()` is
 * `static::$alwaysTrustProxies ?: $this->proxies`:
 *
 *   1. the static, which `$middleware->trustProxies(at: ...)` writes -- SEEN HERE;
 *   2. the config -- SEEN HERE;
 *   3. the instance property `$proxies`, which a subclass sets -- NOT SEEN HERE. This
 *      class reads its OWN null `$proxies`, never the one on the instance the global
 *      stack actually runs. Measured with the config at `10.0.0.0/8` and a subclass
 *      setting `protected $proxies = '*'`: `trustedProxiesAreSound()` reported no
 *      failures, this method returned `'10.0.0.0/8'`, and `$request->ip()` through that
 *      middleware was the caller's own `X-Forwarded-For` from an untrusted peer. It is
 *      latent rather than live -- the stack carries the framework's own `TrustProxies`,
 *      verified -- and it is `PLAN.md` `R-053`, because it is QA H-1's shape inside the
 *      fix for QA H-1's shape.
 *
 * The source this class was built for is (1): a check reading only the config could not
 * see the one call a maintainer actually reaches for. Measured with the config at
 * `10.0.0.0/8` and `TrustProxies::at('*')`: the clause returned no failures while
 * `$request->ip()` was the caller's own `X-Forwarded-For`.
 *
 * A SUBCLASS AND NOT REFLECTION, because the precedence between (1) and (2) is the thing
 * being mirrored and a subclass inherits it rather than restating it:
 * `$alwaysTrustProxies` is a protected static on the parent, so this reads the same
 * storage the real middleware writes, and if the framework reorders those two this
 * follows. A reflection read of one named property would silently stop mirroring. What a
 * subclass does NOT inherit is another instance's property, which is (3) above.
 *
 * Never registered as middleware, and it must not be: the global stack carries the
 * framework's own `TrustProxies`. This class exists to be *asked*, once, at boot.
 */
final class ResolvedTrustedProxies extends TrustProxies
{
    /**
     * Whatever TrustProxies would use for this process: the `at()` override if one was
     * set, otherwise the config -- not a subclass's own `$proxies`, which is source (3)
     * above and `R-053`. Returned unnormalised -- a comma-separated string, an array,
     * `'*'` or null -- because the caller validates every shape Laravel accepts.
     */
    public static function asTrustProxiesReadsThem(): mixed
    {
        return (new self)->proxies() ?: config('trustedproxy.proxies');
    }
}
