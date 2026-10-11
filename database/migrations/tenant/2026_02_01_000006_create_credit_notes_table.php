<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('side', 2);
            $table->bigInteger('party_id');
            $table->bigInteger('source_doc_id')->nullable();
            $table->bigInteger('number');
            $table->date('date');
            $table->string('status', 20)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->decimal('subtotal', 25, 2)->default(0.00);
            $table->decimal('tax_percent', 6, 3)->nullable();
            $table->decimal('tax_total', 25, 2)->default(0.00);
            $table->decimal('total', 25, 2)->default(0.00);
            $table->bigInteger('entry_id')->nullable();
            $table->string('kind', 10)->default('credit');
            $table->dateTime('created_at')->nullable();
            $table->index(['side', 'party_id'], 'side_party');
            $table->index(['side', 'source_doc_id'], 'side_source_doc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
