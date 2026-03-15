<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flashes', function (Blueprint $table) {
            $table->id();
            $table->string('vocabulary');
            $table->string('pinyin')->nullable();
            $table->text('example_sentence')->nullable();
            $table->string('group_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('group_id')
                ->references('id')
                ->on('flash_groups')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flashes');
    }
};
