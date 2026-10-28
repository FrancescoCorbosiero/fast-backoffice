<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 50);
            $table->string('path', 500)->unique();
            $table->string('owner', 150)->index();
            $table->string('kind', 50);
            $table->string('filename');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestamp('created_at');
            $table->index(['owner', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
