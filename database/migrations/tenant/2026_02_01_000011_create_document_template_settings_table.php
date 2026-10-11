<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('document_template_settings', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('template_id')->default(1);
            $table->boolean('template_default_applied')->default(0);
            $table->string('cf1_label', 100)->nullable();
            $table->string('cf1_docs', 50)->nullable();
            $table->string('cf2_label', 100)->nullable();
            $table->string('cf2_docs', 50)->nullable();
            $table->string('cf3_label', 100)->nullable();
            $table->string('cf3_docs', 50)->nullable();
            $table->string('cf4_label', 100)->nullable();
            $table->string('cf4_docs', 50)->nullable();
            $table->string('cf5_label', 100)->nullable();
            $table->string('cf5_docs', 50)->nullable();
            $table->string('signature_image', 255)->nullable();
            $table->string('stamp_image', 255)->nullable();
            $table->string('signature_name', 150)->nullable();
            $table->string('signature_title', 150)->nullable();
            $table->string('footer_address', 255)->nullable();
            $table->string('footer_phone', 100)->nullable();
            $table->string('footer_email', 150)->nullable();
            $table->string('footer_website', 150)->nullable();
            $table->string('qty_label', 30)->nullable();
            $table->string('price_label', 30)->nullable();
            $table->string('payment_extra', 1000)->nullable();
            $table->string('footer_note', 255)->nullable();
            $table->text('style_json')->nullable();
            $table->boolean('template_retrofit_applied')->default(0);
            $table->primary(['id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_settings');
    }
};
