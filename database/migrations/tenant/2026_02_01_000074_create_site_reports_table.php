<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('site_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('project_id');
            $table->integer('number')->default(1);
            $table->date('week_start');
            $table->date('week_end');
            $table->string('status', 10)->default('draft');
            $table->decimal('planned_pct', 6, 2)->nullable();
            $table->decimal('actual_pct', 6, 2)->nullable();
            $table->string('weather', 255)->nullable();
            $table->text('work_done')->nullable();
            $table->text('equipment')->nullable();
            $table->text('quality')->nullable();
            $table->text('safety')->nullable();
            $table->text('challenges')->nullable();
            $table->text('actions')->nullable();
            $table->text('next_week')->nullable();
            $table->mediumText('auto_json')->nullable();
            $table->string('prepared_by', 150)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->string('approved_by', 150)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('return_note', 500)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_reports');
    }
};
