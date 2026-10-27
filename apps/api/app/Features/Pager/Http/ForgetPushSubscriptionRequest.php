<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED (R-052's
// mechanism; see ResolvePagerSessionRequest for the full note).
//
// The keys are deliberately NOT required to unsubscribe. A browser that has revoked
// permission or rotated its subscription may no longer be able to produce them, and
// demanding them would leave rows nobody can delete -- which is exactly the
// dead-subscription growth the 404/410 pruning clause exists to prevent.

/**
 * The body for removing a device: the code from the QR scan, and the endpoint to forget.
 */
final class ForgetPushSubscriptionRequest extends PresentedTokenRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return parent::rules() + [
            'endpoint' => ['required', 'string', 'max:2048', 'starts_with:https://', 'url'],
        ];
    }

    public function endpoint(): string
    {
        /** @var string $endpoint */
        $endpoint = $this->validated()['endpoint'];

        return $endpoint;
    }
}
