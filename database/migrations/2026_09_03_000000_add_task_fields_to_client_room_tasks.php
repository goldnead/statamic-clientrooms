<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a task needs to carry real coaching work, not just a line of text.
 *
 * Additive only: every column is nullable except `published_status`, and
 * `down()` takes exactly these six back. The enum-ish columns are plain
 * strings on purpose — the allowed values live on the model and are checked
 * where somebody types them, so an import does not have to fight a database
 * constraint over a value a coach used for years.
 *
 * `published_status` is the dangerous one. It is the line between what the
 * coach is still writing and what the client sees, so the column default is
 * `draft`: anything written without an opinion stays invisible until somebody
 * has one. Rows that existed before this migration were visible, so they are
 * stamped `published` — nobody's client loses a task because the addon grew a
 * column.
 */
return new class extends Migration
{
    /** Laravel's own name for the index below, spelled out so it can be looked for. */
    private const INDEX = 'client_room_tasks_room_id_published_status_index';

    /**
     * Each of the three steps asks whether it is still needed, because
     * `ALTER TABLE` commits itself on MySQL: a deploy killed after the columns
     * land leaves them standing while Laravel still calls this migration
     * unrun. Without the checks the retry dies on "duplicate column".
     *
     * The backfill in particular runs **only** in the same breath that creates
     * the columns. Re-running it later would stamp `published` over drafts the
     * coach has since written, and put them in front of a client.
     */
    public function up(): void
    {
        $fresh = ! Schema::hasColumn('client_room_tasks', 'published_status');

        if ($fresh) {
            Schema::table('client_room_tasks', function (Blueprint $table) {
                $table->text('description')->nullable()->after('title');

                // exercise, homework, practice … free-form, see config `task_types`.
                $table->string('type', 40)->nullable()->after('description');

                // assigned|in-progress|completed|overdue|cancelled. `done_at` stays
                // the truth for done-ness; this is the workflow state around it.
                $table->string('status', 20)->nullable()->after('type');

                $table->string('published_status', 20)->default('draft')->after('status');
                $table->string('priority', 20)->nullable()->after('published_status');
                $table->unsignedInteger('estimated_minutes')->nullable()->after('priority');
            });

            // Everything that already existed was visible to the client. Keep it so.
            DB::table('client_room_tasks')->update(['published_status' => 'published']);
        }

        if (! $this->hasIndex()) {
            Schema::table('client_room_tasks', function (Blueprint $table) {
                $table->index(['room_id', 'published_status'], self::INDEX);
            });
        }
    }

    private function hasIndex(): bool
    {
        foreach (Schema::getIndexes('client_room_tasks') as $index) {
            if (($index['name'] ?? null) === self::INDEX) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // The index exists only where the column does, so one check covers
        // both — including the half-applied state the guard in `up()` describes.
        if (! Schema::hasColumn('client_room_tasks', 'published_status')) {
            return;
        }

        if ($this->hasIndex()) {
            Schema::table('client_room_tasks', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        Schema::table('client_room_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'type',
                'status',
                'published_status',
                'priority',
                'estimated_minutes',
            ]);
        });
    }
};
