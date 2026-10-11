<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('estimate_sections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('estimate_id');
            $table->string('name', 255);
            $table->integer('sort')->default(0);
            $table->index(['estimate_id'], 'estimate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_sections');
    }
};
