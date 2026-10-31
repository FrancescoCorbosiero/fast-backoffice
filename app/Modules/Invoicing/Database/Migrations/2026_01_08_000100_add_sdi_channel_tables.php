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
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('sdi_identifier', 30)->nullable();
            $table->timestamp('sdi_outcome_at')->nullable();
            $table->jsonb('sdi_errors')->nullable();
            $table->timestamp('rejected_at')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX supplier_invoices_sdi_id_unique ON supplier_invoices (sdi_id) WHERE sdi_id IS NOT NULL');

        // One row per file handed to the PEC provider (sdi.md §3).
        Schema::create('sdi_transmissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('file_name', 40);
            $table->string('message_id', 255)->unique();
            $table->string('sent_to', 190);
            $table->timestamp('sent_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_to_sdi_at')->nullable();
            $table->string('transport_error', 500)->nullable();
            $table->string('outcome', 4)->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->string('sdi_identifier', 30)->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->index('file_name');
        });

        // Every PEC message read, exactly once (sdi.md §5).
        Schema::create('pec_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('message_id', 255)->unique();
            $table->string('uid', 40)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('from_address', 190)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('kind', 24);
            $table->string('outcome', 12);
            $table->text('note')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete();
            $table->string('sdi_identifier', 30)->nullable();
            $table->string('file_name', 80)->nullable();
            $table->unsignedBigInteger('raw_file_id')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(['outcome', 'processed_at']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pec_messages');
        Schema::dropIfExists('sdi_transmissions');
        DB::statement('DROP INDEX IF EXISTS supplier_invoices_sdi_id_unique');
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['sdi_identifier', 'sdi_outcome_at', 'sdi_errors', 'rejected_at']);
        });
    }
};
