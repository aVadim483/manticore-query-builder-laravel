<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'manticore';
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::connection('manticore')->create(config('manticore_driver_migration_table'), function ($table) {
            $table->text('title');
            $table->integer('qty');
            $table->setting('min_infix_len', 2);
        });
    }

    public function down(): void
    {
        Schema::connection('manticore')->drop(config('manticore_driver_migration_table'));
    }
};
