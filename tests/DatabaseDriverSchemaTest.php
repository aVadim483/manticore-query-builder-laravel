<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use avadim\Manticore\Laravel\Database\ManticoreConnection;
use Illuminate\Database\Schema\Blueprint;
use LogicException;

class DatabaseDriverSchemaTest extends DatabaseTestCase
{
    private function connection(): ManticoreConnection
    {
        return new ManticoreConnection(fn () => throw new LogicException('Unexpected PDO connection'), '', 'app_');
    }

    public function testCreateUsesLaravelBlueprintAndManticoreTypesWithoutConnecting(): void
    {
        $connection = $this->connection();
        $log = $connection->pretend(fn () => $connection->getSchemaBuilder()->create('products', function (Blueprint $table) {
            $table->id();
            $table->text('title');
            $table->string('brand');
            $table->float('price');
            $table->boolean('active');
            $table->timestamp('published_at');
            $table->json('info');
            $table->multi('tags');
            $table->multi64('categories');
            $table->floatVector('embedding');
            $table->setting('min_infix_len', 2);
        }));
        $this->assertSame("create table `app_products` (`title` text, `brand` string, `price` float, `active` bool, `published_at` timestamp, `info` json, `tags` multi, `categories` multi64, `embedding` float_vector) min_infix_len='2'", $log[0]['query']);
        $this->assertFalse($connection->getSchemaGrammar()->supportsSchemaTransactions());
    }

    public function testAlterCompilesOneStatementPerColumn(): void
    {
        $connection = $this->connection();
        $log = $connection->pretend(fn () => $connection->getSchemaBuilder()->table('products', function ($table) {
            $table->integer('qty');
            $table->float('rating');
            $table->dropColumn(['old_qty', 'old_rating']);
        }));
        $this->assertSame([
            'alter table `app_products` add column `qty` int', 'alter table `app_products` add column `rating` float',
            'alter table `app_products` drop column `old_qty`', 'alter table `app_products` drop column `old_rating`',
        ], array_column($log, 'query'));
    }

    public function testUnsupportedConstraintsAreRejectedBeforeAnyDdl(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();
        try {
            $connection->getSchemaBuilder()->create('products', function ($table) {
                $table->text('title');
                $table->string('sku')->unique();
            });
            $this->fail('A unique constraint was silently ignored.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('unique', $e->getMessage());
        }
        $this->assertSame([], $connection->getQueryLog());
    }

    public function testUnsupportedTypesAndModifiersAreRejected(): void
    {
        $definitions = [
            fn ($table) => $table->double('amount'), fn ($table) => $table->json('data')->nullable(),
            fn ($table) => $table->integer('qty')->default(1), fn ($table) => $table->string('sku')->change(),
            fn ($table) => $table->foreign('category_id')->references('id')->on('categories'),
        ];
        foreach ($definitions as $definition) {
            try {
                $this->connection()->getSchemaBuilder()->create('products', $definition);
                $this->fail('An unsupported schema definition was accepted.');
            } catch (LogicException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testSettingsAndTextModifiersAreEscaped(): void
    {
        $connection = $this->connection();
        $blueprint = new ManticoreBlueprint($connection, 'products');
        $blueprint->create();
        $blueprint->text('title')->stored(false)->indexed(true);
        $blueprint->setting('morphology', "stem_en' injected");
        $this->assertSame(["create table `app_products` (`title` text indexed) morphology='stem_en\\' injected'"], $blueprint->toSql());
    }
}
