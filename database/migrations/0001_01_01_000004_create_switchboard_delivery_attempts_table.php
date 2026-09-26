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
            $table->foreignId('delivery_id')
                ->constrained($this->deliveries())
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->text('response_excerpt')->nullable();
            // Append-only: an attempt is written once and never updated.
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('switchboard.tables.delivery_attempts', 'switchboard_delivery_attempts');
    }

    private function deliveries(): string
    {
        return (string) config('switchboard.tables.deliveries', 'switchboard_deliveries');
    }
};
