<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('sales_certificates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('number', 64);
            $table->date('cert_date');
            $table->string('doc_type', 16);
            $table->bigInteger('doc_id');
            $table->string('doc_number', 64)->nullable();
            $table->bigInteger('customer_id')->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->string('vehicle_label', 500)->nullable();
            $table->mediumText('snapshot')->nullable();
            $table->string('pdf_file', 100)->nullable();
            $table->string('created_by', 100)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['customer_id'], 'customer_id');
            $table->index(['doc_type', 'doc_id'], 'doc');
            $table->unique(['number'], 'number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_certificates');
    }
};
