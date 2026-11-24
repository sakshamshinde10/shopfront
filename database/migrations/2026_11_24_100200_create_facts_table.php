<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One verified-or-not statement about the business, always tied to where it came from.
        // The generator may only use facts that are "confirmed".
        Schema::create('facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 30);                // phone, email, address, hours, service, price, about ...
            $table->text('value');
            $table->string('status', 10)->default('candidate'); // candidate | confirmed | rejected
            $table->timestamps();

            $table->index(['site_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facts');
    }
};
