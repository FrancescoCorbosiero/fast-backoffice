<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 4);
            $table->string('status', 16);
            $table->string('series', 50);
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('number_text', 30)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('customer_name', 160);
            $table->foreignId('related_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->char('currency', 3)->default('EUR');
            $table->jsonb('lines');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
            $table->jsonb('seller')->nullable();
            $table->jsonb('customer')->nullable();
            $table->string('regime', 4)->nullable();
            $table->jsonb('totals')->nullable();
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->string('sdi_file_name', 40)->nullable();
            $table->string('progressivo_invio', 10)->nullable();
            $table->unsignedBigInteger('xml_file_id')->nullable();
            $table->unsignedBigInteger('pdf_file_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_channel', 10)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'issued_on']);
            $table->index(['contact_id', 'issued_on']);
            $table->index('due_on');
        });

        DB::statement('CREATE UNIQUE INDEX invoices_number_unique ON invoices (series, year, number) WHERE number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX invoices_sdi_file_name_unique ON invoices (sdi_file_name) WHERE sdi_file_name IS NOT NULL');

        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number');
            $table->string('number_text', 30);
            $table->string('status', 12);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('customer_name', 160);
            $table->char('currency', 3)->default('EUR');
            $table->jsonb('lines');
            $table->jsonb('totals');
            $table->bigInteger('total_cents');
            $table->bigInteger('paid_cents')->default(0);
            $table->date('paid_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->unsignedBigInteger('pdf_file_id')->nullable();
            $table->timestamps();

            $table->unique(['year', 'number']);
            $table->index(['status', 'issued_on']);
            $table->index('contact_id');
        });

        Schema::create('recurring_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('frequency', 12);
            $table->date('started_on');
            $table->date('next_issue_on');
            $table->date('last_issued_on')->nullable();
            $table->jsonb('lines');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('auto_issue')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_issue_on']);
            $table->index('contact_id');
        });

        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('supplier_name', 160);
            $table->string('fingerprint', 120)->unique();
            $table->string('type', 4);
            $table->string('number', 30);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->jsonb('lines');
            $table->jsonb('totals');
            $table->bigInteger('total_cents');
            $table->bigInteger('paid_cents')->default(0);
            $table->string('sdi_id', 30)->nullable();
            $table->string('file_name', 80)->nullable();
            $table->unsignedBigInteger('xml_file_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['contact_id', 'issued_on']);
            $table->index('issued_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('recurring_invoices');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('invoices');
    }
};
