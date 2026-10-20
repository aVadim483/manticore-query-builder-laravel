<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'manticore';

    public function up(): void
    {
        Schema::connection('manticore')->create(config('manticore_driver_migration_table'), function ($table) {
            $table->id();
            $table->text('title');
            $table->json('info');
        });
    }

    public function down(): void
    {
        Schema::connection('manticore')->dropIfExists(config('manticore_driver_migration_table'));
    }
};
