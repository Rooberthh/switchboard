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
            $table->unsignedBigInteger('outbox_message_id');
            // A string with no foreign key: an application's own endpoint
            // storage may hand out ids that are not rows of our table.
            $table->string('endpoint_id');
            $table->string('url', 2048);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['outbox_message_id', 'endpoint_id']);
            $table->index(['delivered_at', 'failed_at', 'next_attempt_at']);
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
};
