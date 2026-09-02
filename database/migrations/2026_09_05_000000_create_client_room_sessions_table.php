<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened, as a row.
 *
 * A room already carries what is to do and what was shared. This is the third
 * thing it always claimed to hold: the sitting itself — when it was, what was
 * said, the write-up afterwards, and where the recording and the transcript
 * are.
 *
 * **These rows are usually not typed here.** They arrive from a coaching
 * cockpit that keeps its own database for the work — Zoom sync, processing
 * state, the draft of the write-up — and pushes the finished sitting across
 * when it is published. Three columns exist for that traffic and would be odd
 * otherwise:
 *
 * - `external_id` is the sitting's id in that system, and the reason a
 *   re-import creates nothing. Unique across the table, because the id comes
 *   from one system and means one sitting there.
 * - `recording_url_expires_at` and `transcript_url_expires_at`. The cockpit
 *   hands out **temporary** links, minted per request and dead in a few hours.
 *   Writing one into a column without its expiry is how a client ends up
 *   clicking a link that used to work. Stored with the moment it dies, so
 *   whatever reads it can tell the difference between "no recording" and "a
 *   link that needs minting again".
 * - `has_transcript` is the fact the cockpit reports, and it is not the same
 *   as holding a link to one. A transcript can exist while no valid URL does.
 *
 * `published_status` is the same line as on tasks, and the default is the same
 * `draft`: a sitting written without an opinion about it stays out of the
 * client's room until somebody has one.
 *
 * The enum-ish columns are plain strings, as everywhere else in this addon.
 * `status` in particular takes the cockpit's own vocabulary — `scheduled`,
 * `in-progress`, `processing`, `review-ready`, `completed`, `cancelled`,
 * `no-show` — and a value nobody here anticipated must not cost the sitting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_room_sessions')) {
            return;
        }

        Schema::create('client_room_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('room_id')->constrained('client_rooms')->cascadeOnDelete();

            // The sitting's id in the system it came from. Nullable, because a
            // sitting entered by hand has no such id; unique, because one that
            // does must never land twice.
            $table->string('external_id', 191)->nullable()->unique();

            $table->string('title');

            // When it was held. Nullable: a sitting can be recorded before it
            // has a time, and the cockpit's own field is nullable too.
            $table->dateTime('held_at')->nullable();

            $table->unsignedInteger('duration_minutes')->nullable();

            // scheduled|in-progress|processing|review-ready|completed|cancelled|no-show
            $table->string('status', 20)->nullable();

            $table->string('published_status', 20)->default('draft');

            // What was planned, and the short line afterwards.
            $table->text('agenda')->nullable();
            $table->text('summary')->nullable();

            // The write-up. `longText` on purpose: a generated protocol of a
            // sixty-minute sitting runs past what `text` holds on MySQL.
            $table->longText('protocol')->nullable();

            // The coach's own notes. Never leaves the Control Panel — there is
            // no reader for this column outside it, and that is the point.
            $table->text('notes')->nullable();

            $table->text('recording_url')->nullable();
            $table->dateTime('recording_url_expires_at')->nullable();

            $table->boolean('has_transcript')->default(false);
            $table->text('transcript_url')->nullable();
            $table->dateTime('transcript_url_expires_at')->nullable();

            $table->string('coach_name')->nullable();

            // Provenance: what the sitting carried in the system it came from
            // and this one has no column for — booking id, session type, the
            // package it was paid from.
            $table->json('meta')->nullable();

            $table->timestamps();

            // The client's list: one room, published only, newest first.
            $table->index(['room_id', 'published_status', 'held_at'], 'client_room_sessions_room_published_held_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_room_sessions');
    }
};
