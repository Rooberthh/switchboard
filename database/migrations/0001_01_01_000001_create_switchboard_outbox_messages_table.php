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
            $table->uuid('event_id')->unique();
            $table->string('event_type');
            $table->json('payload');
            $table->longText('body');
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('relayed_at')->nullable();
            $table->timestamps();

            $table->index('relayed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('switchboard.tables.outbox_messages', 'switchboard_outbox_messages');
    }
};
