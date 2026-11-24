<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('business_name', 150);
            $table->string('city', 100)->nullable();
            // draft -> processing -> needs_input / ready -> published
            $table->string('status', 20)->default('draft')->index();
            // Whoever creates a site gets this secret; it replaces a login for editing.
            $table->string('edit_token', 64)->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
