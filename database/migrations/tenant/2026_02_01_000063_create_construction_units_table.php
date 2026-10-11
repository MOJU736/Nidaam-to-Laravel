<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('construction_units', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 20);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_units');
    }
};
