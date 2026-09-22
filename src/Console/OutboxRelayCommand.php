<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\Command;
use Rooberthh\Switchboard\Actions\QueueDeliveryAction;
use Rooberthh\Switchboard\Actions\RelayOutboxMessageAction;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\OutboxMessage;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The outbox relay: the only way a message reaches an endpoint (ADR-0007).
 *
 * Schedule it — an outbox nobody relays sends nothing:
 *
 *     Schedule::command('switchboard:outbox:relay')->everyMinute()->withoutOverlapping();
 *
 * @internal
 */
#[AsCommand(name: 'switchboard:outbox:relay')]
final class OutboxRelayCommand extends Command
{
    /** @var string */
    protected $signature = 'switchboard:outbox:relay';

    /** @var string */
    protected $description = 'Turn emitted outbox messages into deliveries and queue every delivery that is due';

    public function handle(RelayOutboxMessageAction $relay, QueueDeliveryAction $queue): int
    {
        $messages = 0;
        $deliveries = 0;

        // Paged by id: relaying a message removes it from this query.
        OutboxMessage::query()->unrelayed()->eachById(function (OutboxMessage $message) use ($relay, &$messages, &$deliveries): void {
            $deliveries += $relay->execute($message);
            $messages++;
        });

        $queued = 0;

        Delivery::query()->due()->eachById(function (Delivery $delivery) use ($queue, &$queued): void {
            if ($queue->execute($delivery)) {
                $queued++;
            }
        });

        $this->components->info(sprintf(
            'Relayed %d outbox %s into %d %s. Queued %d %s.',
            $messages,
            str('message')->plural($messages),
            $deliveries,
            str('delivery')->plural($deliveries),
            $queued,
            str('delivery')->plural($queued),
        ));

        return self::SUCCESS;
    }
}
