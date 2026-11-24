<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where we are unsure we ask the owner instead of guessing.
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                 // pick_business | conflict | missing
            $table->string('field', 30)->nullable();
            $table->text('prompt');
            $table->json('options')->nullable();        // choices to show, if any
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
