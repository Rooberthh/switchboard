<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // Deleting a message takes its deliveries, and their attempts,
            // with it: pruning is one delete, never a hunt for orphans.
            $table->foreignId('outbox_message_id')
                ->constrained($this->messages())
                ->cascadeOnDelete();
            // A string with no foreign key: an application's own endpoint
            // storage may hand out ids that are not rows of our table.
            $table->string('endpoint_id');
            $table->string('url', 2048);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['outbox_message_id', 'endpoint_id']);
            // Named, because the generated name runs past MySQL's 64
            // characters. It serves the relay's search for due deliveries.
            $table->index(['delivered_at', 'failed_at', 'next_attempt_at'], "{$this->table()}_due_index");
            $table->index('endpoint_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('switchboard.tables.deliveries', 'switchboard_deliveries');
    }

    private function messages(): string
    {
        return (string) config('switchboard.tables.outbox_messages', 'switchboard_outbox_messages');
    }
};
