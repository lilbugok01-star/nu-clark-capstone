<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'e_signature_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('e_signature_path')->nullable();
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->default('student')->change();
        });

        // String statuses keep the configurable approval chain portable across
        // MySQL and SQLite without raw ALTER/ENUM statements.
        Schema::table('events', function (Blueprint $table) {
            $table->string('status', 50)->default('draft')->change();
        });
        Schema::table('venue_reservations', function (Blueprint $table) {
            $table->string('status', 50)->default('pending')->change();
        });

        Schema::table('event_proposals', function (Blueprint $table) {
            $table->text('recommendations')->nullable()->after('expected_outcomes');
            $table->timestamp('recommendations_applied_at')->nullable()->after('recommendations');
        });

        Schema::table('event_approvals', function (Blueprint $table) {
            $table->timestamp('proposal_reviewed_at')->nullable()->after('e_signature_used');
        });

        Schema::create('equipment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignId('venue_reservation_id')->nullable()->constrained('venue_reservations')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('item_name', 150);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('purpose', 255)->nullable();
            $table->enum('status', ['requested', 'available', 'unavailable', 'issued', 'returned'])->default('requested');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['venue_reservation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_requests');

        Schema::table('event_approvals', function (Blueprint $table) {
            $table->dropColumn('proposal_reviewed_at');
        });

        Schema::table('event_proposals', function (Blueprint $table) {
            $table->dropColumn(['recommendations', 'recommendations_applied_at']);
        });

        // Status columns intentionally remain strings: historical workflow
        // values must not be truncated by restoring a narrower enum.
    }
};
