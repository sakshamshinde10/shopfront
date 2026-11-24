<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // A short message for the owner, for example "the AI was unavailable, try again".
            $table->string('notice', 300)->nullable()->after('status');
        });

        Schema::table('sources', function (Blueprint $table) {
            // Set when a file or page contained text aimed at the AI instead of facts about the business.
            $table->string('warning', 300)->nullable()->after('error');
            // Which business(es) the source describes, as the AI saw them, and what the owner decided.
            $table->json('identity')->nullable()->after('warning');
            // Set once the facts in this source have been pulled out, so a re-run never repeats the work.
            $table->timestamp('analysed_at')->nullable()->after('identity');
        });

        Schema::table('facts', function (Blueprint $table) {
            // One source can describe several businesses. This says which one the fact is about.
            $table->unsignedTinyInteger('business')->default(0)->after('field');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });
        Schema::table('facts', fn (Blueprint $table) => $table->dropColumn('business'));
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn(['warning', 'identity', 'analysed_at']));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('notice'));
    }
};
