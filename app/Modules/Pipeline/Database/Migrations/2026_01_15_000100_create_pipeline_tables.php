<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One pipeline per website: which repository it is built from, and
        // whether it is ready to take change requests.
        Schema::create('website_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained('websites')->restrictOnDelete();
            $table->foreignId('repository_id')->constrained('repositories')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('status', 12);
            $table->timestamps();

            $table->index('repository_id');
        });

        Schema::create('change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();
            $table->string('client_name', 160);
            $table->string('website_name', 120);
            $table->string('kind', 12);
            $table->string('status', 16);
            $table->text('request_text');
            $table->string('branch', 120);
            // A random slug names the preview folder and host: never the id.
            $table->char('preview_slug', 26)->unique();
            $table->string('preview_url', 500)->nullable();
            $table->text('log')->default('');
            $table->text('error')->nullable();
            $table->string('client_error', 1000)->nullable();
            $table->jsonb('touched_paths')->nullable();
            $table->jsonb('generated_files')->nullable();
            $table->string('decided_by', 8)->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('deploy_started_at')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('contact_id');
            $table->index('website_id');
        });

        // Sign-in links for the client portal: the token is stored hashed,
        // used once, and expires.
        Schema::create('portal_sign_ins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_sign_ins');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('website_pipelines');
    }
};
