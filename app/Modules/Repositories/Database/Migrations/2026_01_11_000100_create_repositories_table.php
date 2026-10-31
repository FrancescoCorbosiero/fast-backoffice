<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('url', 500)->unique();
            $table->string('provider', 12);
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->string('default_branch', 120)->default('main');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('contact_id');
            $table->index('website_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
