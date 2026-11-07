<?php

declare(strict_types=1);

/*
 * A real `outbox:work --once` whose push edge BLOCKS, so it can be killed mid-batch.
 *
 * WHY A SEPARATE ENTRY SCRIPT AND NOT A FLAG IN THE APPLICATION. The obvious way to make
 * the edge hang is a condition in NotificationsServiceProvider behind an environment
 * variable -- and that would be a production code path that an environment variable can
 * make hang, which bootstrap/app.php already rejects in so many words for the SIGTERM
 * drain route: "a production code path a request can trigger, and that is strictly
 * worse". So the override lives here, in a file nothing but bin/outbox-kill-proof runs,
 * and the application ships with no knowledge of it at all.
 *
 * WHAT IT PROVES BY BLOCKING WHERE IT DOES. The worker claims the whole batch in one short
 * transaction and commits the lease BEFORE any send. Blocking inside the first send
 * therefore puts the process in the most dangerous state there is: every row leased,
 * none processed, and the lease durable in a database the dead process will never touch
 * again. That is what "mid-batch" means.
 *
 * It touches the handshake file as soon as the first request arrives, so the proof waits
 * for a fact rather than sleeping and hoping.
 */

use App\Features\Notifications\WebPushDispatcher;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @var Application $app */
$app = require __DIR__.'/outbox-proof-boot.php';

$mode = (string) ($argv[1] ?? '');
$signal = (string) ($argv[2] ?? '');

if (! in_array($mode, ['block', 'deliver'], true)) {
    fwrite(STDERR, "outbox-proof-worker: usage: outbox-proof-worker.php block <handshake-file> | deliver\n");

    exit(1);
}

if ($mode === 'block' && $signal === '') {
    fwrite(STDERR, "outbox-proof-worker: block mode needs a handshake file path\n");

    exit(1);
}

$edge = $mode === 'block'
    ? new class($signal) implements ClientInterface
    {
        public function __construct(private readonly string $signal) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            // Tell the proof we are inside a send, then never come back. The kill lands here.
            file_put_contents($this->signal, (string) $request->getUri());

            while (true) {
                sleep(1);
            }
        }
    }
// THE RECOVERY PASS NEEDS AN EDGE TOO, and the first version of this proof forgot:
// it ran plain `php artisan outbox:work --once`, which boots without the generated
// VAPID keypair and therefore without a usable dispatcher, so all eight rows came
// back `retried` and the proof reported them lost. The failure was the proof's, not
// the worker's -- which is exactly the sort of thing a proof that lives in the repo
// and runs in the suite catches, and one in a scratchpad does not (R-043).
: new class implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(201);
    }
};

$app->instance(WebPushDispatcher::EDGE, $edge);
$app->forgetInstance(WebPushDispatcher::class);

exit($app->make(Kernel::class)->call('outbox:work', ['--once' => true]));
