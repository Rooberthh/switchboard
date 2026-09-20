<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Switchboard;
use Throwable;

/**
 * Verify, normalize, persist, respond. Holds no provider knowledge and no
 * secret, and does no work the provider has to wait for.
 *
 * @internal
 */
final class InboxController
{
    public function __invoke(Request $request, string $provider): Response
    {
        $driver = Switchboard::driver($provider);

        try {
            $verified = $driver->verify($request);
        } catch (Throwable $e) {
            // A driver that throws on a malformed request has told us the same
            // thing as a driver that returned false, so answer the same way.
            // Reported, because it is far more likely to be a driver bug than
            // a forgery.
            report($e);

            $verified = false;
        }

        if (! $verified) {
            // One undifferentiated rejection: tampered body, wrong secret and
            // stale timestamp are indistinguishable from out here.
            return response()->noContent(Response::HTTP_BAD_REQUEST);
        }

        $data = $driver->normalize($request);

        InboxMessages::createOrFirst(
            [
                'provider' => $provider,
                'event_id' => $data->eventId,
            ],
            [
                'event_type' => $data->eventType,
                'subject' => $data->subject,
                'data' => $data->data,
                'occurred_at' => $data->occurredAt ?? now(),
            ],
        );

        return response()->noContent();
    }
}
