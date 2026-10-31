<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_overrides', function (Blueprint $table): void {
            $table->string('job_key', 100)->primary();
            $table->boolean('enabled')->nullable();
            $table->string('cron', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('job_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('job_key', 100);
            $table->string('trigger', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('output')->default('');
            $table->index(['job_key', 'id']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('job_overrides');
    }
};
