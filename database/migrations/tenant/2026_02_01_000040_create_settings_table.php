<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name', 255);
            $table->string('address', 255);
            $table->string('email', 255);
            $table->date('fy_start');
            $table->date('fy_end');
            $table->string('currency_symbol', 100);
            $table->string('currency_format', 100);
            $table->integer('decimal_places')->default(2);
            $table->string('date_format', 100);
            $table->string('timezone', 100);
            $table->integer('manage_inventory')->default(0);
            $table->integer('account_locked')->default(0);
            $table->integer('email_use_default')->default(0);
            $table->string('email_protocol', 10);
            $table->string('email_host', 255);
            $table->integer('email_port');
            $table->integer('email_tls')->default(0);
            $table->string('email_username', 255);
            $table->string('email_password', 255);
            $table->string('email_from', 255);
            $table->decimal('print_paper_height', 10, 3)->default(0.000);
            $table->decimal('print_paper_width', 10, 3)->default(0.000);
            $table->decimal('print_margin_top', 10, 3)->default(0.000);
            $table->decimal('print_margin_bottom', 10, 3)->default(0.000);
            $table->decimal('print_margin_left', 10, 3)->default(0.000);
            $table->decimal('print_margin_right', 10, 3)->default(0.000);
            $table->char('print_orientation', 1);
            $table->char('print_page_format', 1);
            $table->integer('database_version')->nullable();
            $table->binary('settings')->nullable();
            $table->string('logo', 25)->nullable();
            $table->boolean('commissions_enabled')->default(1);
            $table->date('lock_date')->nullable();
            $table->boolean('construction_enabled')->default(0);
            $table->boolean('vehicles_enabled')->default(1);
            $table->boolean('billable_expenses_enabled')->default(0);
            $table->string('reminder_subject', 255)->nullable();
            $table->text('reminder_body')->nullable();
            $table->boolean('datasharing_enabled')->default(0);
            $table->boolean('datasharing_only')->default(0);
            $table->primary(['id']);
            $table->index(['id'], 'id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
