<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('subject', 200);
            $table->string('preheader', 200)->nullable();
            $table->text('body_html');
            $table->jsonb('audience_roles')->default('[]');
            $table->string('status', 12);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('email', 190);
            $table->string('status', 14);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('message_id', 190)->nullable()->unique();
            $table->string('ses_message_id', 190)->nullable();
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
            $table->index('contact_id');
            $table->index('ses_message_id');
        });

        // Every SNS notification, once: its MessageId is the idempotency key.
        Schema::create('mail_events', function (Blueprint $table): void {
            $table->id();
            $table->string('sns_message_id', 120)->unique();
            $table->string('event_type', 40)->nullable();
            $table->string('ses_message_id', 190)->nullable();
            $table->string('header_message_id', 190)->nullable();
            $table->foreignId('recipient_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
            $table->string('outcome', 12);
            $table->string('note', 500)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index('recipient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_events');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
