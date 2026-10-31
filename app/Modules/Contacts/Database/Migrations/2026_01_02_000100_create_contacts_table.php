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
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 10);
            $table->string('display_name', 160);
            $table->string('first_name', 60)->nullable();
            $table->string('last_name', 60)->nullable();
            $table->string('business_name', 80)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->char('vat_country', 2)->nullable();
            $table->string('vat_number', 28)->nullable();
            $table->string('tax_code', 16)->nullable();
            $table->char('sdi_code', 7)->nullable();
            $table->string('pec')->nullable();
            $table->string('street', 60)->nullable();
            $table->string('street_number', 8)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->string('city', 60)->nullable();
            $table->string('province', 2)->nullable();
            $table->char('country', 2)->default('IT');
            $table->jsonb('roles')->default('[]');
            $table->boolean('email_consent')->default(false);
            $table->timestamp('email_suppressed_at')->nullable();
            $table->string('email_suppression_reason', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->timestamps();

            $table->index('display_name');
            $table->index('email');
            $table->index('merged_into_id');
        });

        // One live contact per fiscal identity; merged contacts keep theirs.
        DB::statement('CREATE UNIQUE INDEX contacts_vat_number_live_unique ON contacts (vat_country, vat_number) WHERE merged_into_id IS NULL AND vat_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX contacts_tax_code_live_unique ON contacts (tax_code) WHERE merged_into_id IS NULL AND tax_code IS NOT NULL');
        DB::statement('CREATE INDEX contacts_roles_gin ON contacts USING gin (roles)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
