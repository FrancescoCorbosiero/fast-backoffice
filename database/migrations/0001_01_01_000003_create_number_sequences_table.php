<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Kernel's gapless counters (design.md §5.2): one row per
 * (series, year), advanced under a row lock inside the issuing transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->string('series', 50);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number');
            $table->primary(['series', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
