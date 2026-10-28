<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->string('type', 10);
            $table->string('iban', 34)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->date('opened_on');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('cash_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->string('kind', 10);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['kind', 'name']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('direction', 3);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->date('occurred_on');
            $table->string('description', 200);
            $table->foreignId('category_id')->nullable()->constrained('cash_categories')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->string('allocation', 150)->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['occurred_on', 'id']);
            $table->index(['account_id', 'occurred_on']);
            $table->index('allocation');
            $table->index('contact_id');
            $table->index(['direction', 'occurred_on']);
        });

        Schema::create('cash_recurring_expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('frequency', 12);
            $table->date('started_on');
            $table->date('next_due_on');
            $table->foreignId('account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('cash_categories')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_recurring_expenses');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_categories');
        Schema::dropIfExists('cash_accounts');
    }
};
