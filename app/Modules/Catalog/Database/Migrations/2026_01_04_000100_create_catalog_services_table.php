<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellable services: a template source only. Lines are copied into
 * documents, so the catalog never changes an issued document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_services', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('vat_basis_points')->default(0);
            $table->string('natura', 5)->nullable();
            $table->string('cadence', 12)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_services');
    }
};
