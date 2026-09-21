<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Contracts;

use Illuminate\Http\Request;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * Everything Switchboard needs to know about one provider, in one class: what
 * it is called, how its requests are verified, what they mean, and which
 * handler acts on each event type.
 *
 * Deliberately larger than the package's other contracts, so that an
 * integrator reading one file sees the whole of an integration. The cost is
 * that a method added here is a breaking change; see
 * docs/adr/0005-a-provider-is-one-class.md.
 *
 * Most applications extend {@see \Rooberthh\Switchboard\Inbox\WebhookProvider}
 * rather than implementing this directly.
 *
 * Public API.
 */
interface WebhookProvider
{
    /**
     * Event type to the invokable class that handles it. Handlers are resolved
     * from the container and run on the queue, never during the request.
     *
     * @var array<string, class-string>
     */
    public array $handlers { get; }

    /**
     * The provider's name: stored on every message, the default route segment
     * and the key its secret is configured under. Static, so registering a
     * provider never has to build one. Keep it stable — stored messages are
     * found by it.
     */
    public static function name(): string;

    /**
     * How this provider's requests are proven authentic. Switchboard calls it;
     * the provider never verifies a request itself.
     */
    public function verification(): Verification;

    /**
     * What a verified request means. Only called for a request that verified.
     *
     * @param Request $request
     */
    public function normalize(Request $request): InboxMessageData;

    /**
     * What to do with a message whose event type has no handler.
     *
     * @param InboxMessage $message
     */
    public function unhandled(InboxMessage $message): void;
}
