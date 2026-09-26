<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_approvals', function (Blueprint $table) {
            $table->foreignId('reviewed_proposal_id')->nullable()->after('proposal_reviewed_at')
                ->constrained('event_proposals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_proposal_id');
        });
    }
};
