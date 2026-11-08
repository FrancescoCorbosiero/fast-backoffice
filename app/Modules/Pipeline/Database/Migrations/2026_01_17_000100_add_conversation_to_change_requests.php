<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The exchange after a run that changed nothing and asked the client
        // something (status needs_input): the agent's turns with the
        // questions it listed, the client's answers. Oldest first; null for a
        // request that never needed one.
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->json('conversation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table): void {
            $table->dropColumn('conversation');
        });
    }
};
