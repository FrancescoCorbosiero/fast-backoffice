<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number');
            $table->string('number_text', 30);
            $table->string('status', 12);
            $table->date('issued_on');
            $table->date('valid_until');
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('customer_name', 160);
            $table->string('subject', 200)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->jsonb('lines');
            $table->jsonb('totals');
            $table->bigInteger('total_cents');
            $table->text('notes')->nullable();
            $table->date('sent_on')->nullable();
            $table->date('decided_on')->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained('payment_requests')->restrictOnDelete();
            $table->unsignedBigInteger('pdf_file_id')->nullable();
            $table->timestamps();

            $table->unique(['year', 'number']);
            $table->index(['status', 'valid_until']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
