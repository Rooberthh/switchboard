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
            $table->string('provider');
            $table->string('event_id');
            $table->string('event_type');
            $table->string('subject')->nullable();
            $table->json('data');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('relayed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index('subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('switchboard.tables.inbox_messages', 'switchboard_inbox_messages');
    }
};
