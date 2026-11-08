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
        // When a request became deployed, rejected or failed: the clock its
        // attachments are kept by (docs/pipeline.md §10). Not updated_at,
        // which a contact merge rewrites on every request of the contact.
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->timestamp('finished_at')->nullable();
            $table->index('finished_at');
        });
        DB::table('change_requests')->whereIn('status', ['deployed', 'rejected', 'failed'])->update(['finished_at' => DB::raw('updated_at')]);

        // The images a request carries: the file as stored (metadata
        // stripped) on the pipeline's private disk under a generated name.
        // The client's file name is for display only, never a path. A row
        // outlives its file: removed_at says when retention took it.
        Schema::create('change_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('change_request_id')->constrained('change_requests')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('stored_path', 40)->unique();
            $table->string('mime', 16);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedSmallInteger('position');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['change_request_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_request_attachments');
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->dropIndex(['finished_at']);
            $table->dropColumn('finished_at');
        });
    }
};
