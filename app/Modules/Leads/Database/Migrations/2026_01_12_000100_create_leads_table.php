<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('company', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('source', 60)->nullable();
            $table->string('status', 12);
            $table->bigInteger('estimated_value_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->date('next_follow_up_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_follow_up_on']);
            $table->index('email');
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
