<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * This feature's own container wiring. A FEATURE PROVIDER, and deptrac is why.
 *
 * The obvious home for these two bindings was AppServiceProvider, and deptrac refused it
 * -- correctly. `app/Providers/.*` is in the Platform layer, and rule 2 of the three
 * named architecture guards (PLAN.md §3, row 2) is "the platform layer imports no feature
 * modules". Binding WebPushDispatcher there made the platform's own provider depend on
 * App\Features\Notifications, which is exactly the coupling that rule exists to prevent:
 * Platform is what every feature builds on, so a Platform file that knows about one
 * feature makes that feature impossible to remove.
 *
 * So the feature wires itself. Features may depend on Domain, Platform and the framework,
 * which is all this needs, and the next feature that needs container wiring has a
 * pattern to follow rather than a shared file to add a line to.
 *
 * Registered in bootstrap/providers.php, which deptrac does not scan -- its paths are
 * ./app, ./database and ./routes (and R-030 already records bootstrap/ and docker/ being
 * outside both guards' reach). That is a gap in the guard, not a loophole being used:
 * what is in that file is one class name per line, which is the one thing a dependency
 * rule cannot usefully police.
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // THE PUSH EDGE, as a PSR-18 client bound by name.
        //
        // WHY A NAMED BINDING AND NOT a ClientInterface type hint on the dispatcher:
        // php-http/discovery would otherwise pick an implementation at runtime, and what
        // it picks is whatever happens to be installed -- so the suite's edge and
        // production's edge would be chosen by the vendor directory rather than by this
        // file. Binding it here means the dispatcher takes exactly the client this
        // application configured, and a test swaps it with one line:
        // $this->app->instance(WebPushDispatcher::EDGE, $recorder).
        //
        // THE TIMEOUTS ARE WHY THIS EXISTS AT ALL. minishlink/web-push has no timeout
        // setting of its own -- its own docblock says "Configure timeouts/proxies/
        // redirects directly on this client instance" -- so an unconfigured client means
        // a hung push edge holds a worker until PHP's socket default gives up. These two
        // numbers are the only thing bounding that, and config/webpush.php carries the
        // reason beside them.
        $this->app->bind(WebPushDispatcher::EDGE, fn (): ClientInterface => new GuzzleClient([
            'connect_timeout' => (float) config('webpush.timeout.connect'),
            'timeout' => (float) config('webpush.timeout.request'),

            // http_errors => false because a 404 or a 410 from a push service is
            // INFORMATION this application acts on -- prune that subscription, which is
            // a gate clause -- and not an exception. With Guzzle's default the library
            // would never see the response at all and MessageSentReport::
            // isSubscriptionExpired() could never be true.
            'http_errors' => false,

            // A push service never legitimately redirects a send, and following one would
            // re-send the VAPID JWT and the encrypted payload to a host the subscription
            // did not name.
            'allow_redirects' => false,
        ]));

        $this->app->bind(WebPushDispatcher::class, fn (): WebPushDispatcher => new WebPushDispatcher(
            $this->app->make(WebPushDispatcher::EDGE),
            $this->app->make(LoggerInterface::class),
        ));
    }
}
