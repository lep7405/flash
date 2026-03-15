<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flash_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_id')
                ->constrained('flashes')
                ->cascadeOnDelete();
            $table->text('sentence');
            $table->text('pinyin')->nullable();
            $table->text('translation_vi')->nullable();
            $table->timestamps();
        });

        if (Schema::hasColumn('flashes', 'example_sentence')) {
            $legacyExamples = DB::table('flashes')
                ->select('id', 'example_sentence')
                ->whereNotNull('example_sentence')
                ->where('example_sentence', '!=', '')
                ->get();

            $now = now();

            foreach ($legacyExamples as $legacyExample) {
                DB::table('flash_examples')->insert([
                    'flash_id' => $legacyExample->id,
                    'sentence' => $legacyExample->example_sentence,
                    'pinyin' => null,
                    'translation_vi' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('flashes', function (Blueprint $table) {
                $table->dropColumn('example_sentence');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('flashes', 'example_sentence')) {
            Schema::table('flashes', function (Blueprint $table) {
                $table->text('example_sentence')->nullable()->after('pinyin');
            });
        }

        if (Schema::hasTable('flash_examples')) {
            $firstExamples = DB::table('flash_examples')
                ->select('flash_id', 'sentence')
                ->orderBy('id')
                ->get()
                ->unique('flash_id');

            foreach ($firstExamples as $firstExample) {
                DB::table('flashes')
                    ->where('id', $firstExample->flash_id)
                    ->update([
                        'example_sentence' => $firstExample->sentence,
                    ]);
            }
        }

        Schema::dropIfExists('flash_examples');
    }
};
