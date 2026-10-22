<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    protected $connection = 'manticore';
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection('manticore');
        $name = config('manticore_driver_migration_table');
        $schema->table($name, function ($table) {
            $table->bigInteger('qty')->change();
            $table->boolean('active');
            $table->setting('min_infix_len', 3);
        });
        $schema->rename($name, $name.'_renamed');
    }

    public function down(): void
    {
        $schema = Schema::connection('manticore');
        $name = config('manticore_driver_migration_table');
        $schema->rename($name.'_renamed', $name);
        $schema->table($name, function ($table) {
            $table->dropColumn('active');
            $table->setting('min_infix_len', 2);
        });
        // bigint -> int is unsupported; preserve the widened type and its data.
    }
};
