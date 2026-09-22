<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\Command;
use Rooberthh\Switchboard\Actions\ReplayDeliveryAction;
use Rooberthh\Switchboard\Models\Delivery;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Send failed deliveries again, once a receiver has fixed its side.
 *
 * Replayed deliveries become due now and the scheduled relay sends them, the
 * same stored body to the same URL, starting their retry schedule over.
 *
 * @internal
 */
#[AsCommand(name: 'switchboard:outbox:replay')]
final class OutboxReplayCommand extends Command
{
    /** @var string */
    protected $signature = 'switchboard:outbox:replay
                            {--endpoint= : Only replay deliveries to this endpoint id}';

    /** @var string */
    protected $description = 'Return failed outbox deliveries to pending, for the relay to send again';

    public function handle(ReplayDeliveryAction $replay): int
    {
        $query = Delivery::query()->failed();

        $endpoint = $this->option('endpoint');

        if (is_string($endpoint)) {
            if (trim($endpoint) === '') {
                // Not the same as leaving it off: a script whose variable went
                // missing must not replay every endpoint's failures.
                $this->components->error('The --endpoint option was given without a value.');

                return self::FAILURE;
            }

            $query->where('endpoint_id', $endpoint);
        }

        $replayed = 0;

        // Paged by id, not by offset: replaying removes the row from this query.
        $query->eachById(function (Delivery $delivery) use (&$replayed, $replay): void {
            $replay->execute($delivery);

            $replayed++;
        });

        $this->components->info("Replayed {$replayed} failed outbox " . str('delivery')->plural($replayed) . '. The relay sends them on its next run.');

        return self::SUCCESS;
    }
}
