<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tag_id')->nullable();
            $table->bigInteger('entrytype_id');
            $table->bigInteger('number')->nullable();
            $table->date('date');
            $table->decimal('dr_total', 25, 2)->default(0.00);
            $table->decimal('cr_total', 25, 2)->default(0.00);
            $table->string('notes', 500);
            $table->index(['entrytype_id'], 'entrytype_id');
            $table->index(['id'], 'id');
            $table->index(['tag_id'], 'tag_id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
