<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('relationship');
            $table->text('preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->date('gifted_on');
            $table->string('giver');
            $table->text('reaction')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->string('event')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'gifted_on', 'id']);
        });

        Schema::create('recipient_members', function (Blueprint $table) {
            $table->foreignId('recipient_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('joined_at');
            $table->primary(['recipient_id', 'user_id']);
        });

        Schema::create('recipient_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_invitations');
        Schema::dropIfExists('recipient_members');
        Schema::dropIfExists('gifts');
        Schema::dropIfExists('recipients');
    }
};
