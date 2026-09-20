<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Inbox;

use Illuminate\Database\Eloquent\Builder;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * The one place the package reaches for the inbox message model.
 *
 * Nothing else constructs or queries it, which is what keeps the model-swap
 * seam (a useInboxMessageModel() configurator) a one-line addition later.
 *
 * @internal
 */
final class InboxMessages
{
    /** @var class-string<InboxMessage> */
    private static string $model = InboxMessage::class;

    /**
     * @return class-string<InboxMessage>
     */
    public static function model(): string
    {
        return self::$model;
    }

    /**
     * @return Builder<InboxMessage>
     */
    public static function query(): Builder
    {
        $model = self::$model;

        return (new $model())->newQuery();
    }

    public static function find(int $id): ?InboxMessage
    {
        return self::query()->find($id);
    }

    /**
     * Insert first and recover from the unique index, rather than reading
     * before writing: a concurrent retry of the same event must not be able
     * to slip between the read and the write.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public static function createOrFirst(array $attributes, array $values = []): InboxMessage
    {
        return self::query()->createOrFirst($attributes, $values);
    }
}
