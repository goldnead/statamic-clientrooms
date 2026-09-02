<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the client hands back.
 *
 * A submission belongs to one task and may carry text, files, or both. Both
 * tables cascade from the task, because a submission without its task is not
 * evidence of anything.
 *
 * Files live in their own table rather than in `client_room_files`, which
 * holds what the *coach* shared. The two look alike and are not alike: a room
 * document has a `visible_to_client` switch the coach operates, a submission
 * file is the client's own and has no such switch. Keeping them apart means no
 * query anywhere has to remember a filter to stop the coach's document list
 * filling up with the client's uploads — the boundary is structural rather
 * than something a later `where()` can forget.
 *
 * The columns mirror `client_room_files` (`container` + `path`, never an asset
 * id) for the reason spelled out there: an asset id changes when somebody
 * tidies a folder, and a submission must not lose its evidence to housekeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_room_task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('client_room_tasks')->cascadeOnDelete();

            $table->text('body')->nullable();

            // A Statamic user id, like `client_rooms.owner_user_id`: a string,
            // because file-based users carry UUIDs and eloquent ones integers.
            // Null where a coach recorded a submission on the client's behalf.
            $table->string('submitted_by', 64)->nullable();

            // Separate from `created_at`: an import carries the moment the
            // client actually handed the work in, which is not the moment the
            // row was written.
            $table->timestamp('submitted_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'submitted_at']);
        });

        Schema::create('client_room_task_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('client_room_task_submissions')
                ->cascadeOnDelete();

            $table->string('container', 64);
            $table->string('path', 1024);

            // Recorded at upload. The asset knows its own size, but a file the
            // client uploaded and somebody later removed from the container
            // should still be able to say how big it was.
            $table->unsignedBigInteger('size')->default(0);

            $table->timestamps();

            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_room_task_submission_files');
        Schema::dropIfExists('client_room_task_submissions');
    }
};
