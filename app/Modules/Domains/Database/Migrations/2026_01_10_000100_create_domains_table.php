<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 253)->unique();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->string('registrar', 120)->nullable();
            $table->string('status', 12);
            $table->date('registered_on')->nullable();
            $table->date('expires_on');
            $table->unsignedSmallInteger('renewal_months')->default(12);
            $table->boolean('auto_renew')->default(true);
            $table->bigInteger('renewal_cost_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('contact_id');
            $table->index(['status', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
