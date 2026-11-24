<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Everything the owner gives us: a link, an uploaded file, or a typed note.
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                 // link | file | note
            $table->string('label', 200)->nullable();   // file name or link title
            $table->string('url', 2048)->nullable();
            $table->string('path', 500)->nullable();    // where an uploaded file is stored
            $table->string('mime', 100)->nullable();
            // pending -> done | failed | blocked (site refused our request)
            $table->string('status', 10)->default('pending');
            $table->longText('text')->nullable();       // what we managed to read
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
