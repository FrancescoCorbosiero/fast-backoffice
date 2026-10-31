<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('url', 500);
            $table->string('host', 253);
            $table->string('status', 12);
            $table->string('hosting_provider', 120)->nullable();
            $table->date('hosting_started_on')->nullable();
            $table->date('hosting_renews_on')->nullable();
            $table->string('hosting_frequency', 12)->nullable();
            $table->bigInteger('hosting_cost_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->unsignedBigInteger('hosting_account_id')->nullable();
            $table->unsignedBigInteger('hosting_category_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->boolean('uptime_enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('is_up')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('down_since')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('host');
            $table->index('contact_id');
            $table->index(['status', 'uptime_enabled']);
            $table->index('hosting_renews_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
