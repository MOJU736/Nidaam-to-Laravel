<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('site_report_photos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('report_id');
            $table->string('file', 255);
            $table->string('caption', 255)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['report_id'], 'report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_report_photos');
    }
};
