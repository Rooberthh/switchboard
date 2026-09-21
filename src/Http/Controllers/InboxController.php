<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Rooberthh\Switchboard\Actions\CreateInboxMessageAction;
use Rooberthh\Switchboard\Exceptions\InvalidInboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Throwable;

/**
 * Verify, normalize, persist, respond. Holds no provider knowledge and no
 * secret — the registered provider class supplies both — and does no work the
 * provider has to wait for.
 *
 * @internal
 */
final class InboxController
{
    public function __invoke(Request $request, string $provider): Response
    {
        $webhookProvider = Switchboard::resolve($provider);

        try {
            $verified = $webhookProvider->verification()->verify($request);
        } catch (Throwable $e) {
            // A verification that throws on a malformed request, or one the
            // provider could not even build, has told us the same thing as
            // one that returned false, so answer the same way. Reported,
            // because it is far more likely to be a bug or a misconfiguration
            // than a forgery.
            report($e);

            $verified = false;
        }

        if (! $verified) {
            // One undifferentiated rejection: tampered body, wrong secret and
            // stale timestamp are indistinguishable from out here.
            return response()->noContent(Response::HTTP_BAD_REQUEST);
        }

        $data = $webhookProvider->normalize($request);

        // The name is what stored messages are found by, so a provider may
        // only file messages under its own. Disagreeing is a bug in the
        // provider, not a forgery — the request has already verified — so it
        // throws and is reported rather than answering with a rejection.
        if ($data->provider !== $webhookProvider::name()) {
            throw InvalidInboxMessage::providerMismatch($webhookProvider::name(), $data->provider);
        }

        app(CreateInboxMessageAction::class)->execute($data);

        return response()->noContent();
    }
}
