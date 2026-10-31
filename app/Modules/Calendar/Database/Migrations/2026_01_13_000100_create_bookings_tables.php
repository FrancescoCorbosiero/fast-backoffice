<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('external_uid', 120)->unique();
            $table->string('title', 200);
            $table->string('event_type', 120)->nullable();
            $table->string('status', 12);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone', 60)->nullable();
            $table->string('attendee_name', 160)->nullable();
            $table->string('attendee_email', 190)->nullable();
            $table->string('location', 500)->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->string('source', 10);
            $table->jsonb('payload')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('starts_at');
            $table->index('attendee_email');
            $table->index('contact_id');
        });

        // Every webhook delivery, once: the body's hash is the idempotency key.
        Schema::create('calendar_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->string('trigger', 60)->nullable();
            $table->string('outcome', 12);
            $table->string('note', 500)->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_webhook_events');
        Schema::dropIfExists('bookings');
    }
};
