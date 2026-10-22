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

    public function testAlterSettingsKeepDeclarationOrderAndGroupAdjacentSettings(): void
    {
        $db = $this->connection();
        $log = $db->pretend(fn () => $db->getSchemaBuilder()->table('products', function ($table) {
            $table->setting('min_infix_len', 3)->setting('morphology', 'stem_en');
            $table->integer('qty');
            $table->setting('rt_mem_limit', '32M');
            $table->dropColumn('old_qty');
            $table->setting('charset_table', "a..z, 'quoted'");
        }));
        $this->assertSame([
            "alter table `app_products` min_infix_len='3' morphology='stem_en'",
            'alter table `app_products` add column `qty` int',
            "alter table `app_products` rt_mem_limit='32M'",
            'alter table `app_products` drop column `old_qty`',
            "alter table `app_products` charset_table='a..z, \\'quoted\\''",
        ], array_column($log, 'query'));
    }

    public function testInvalidAlterSettingsAreRejectedBeforeEarlierDdl(): void
    {
        foreach ([['type', 'distributed'], ['min_word_len', -1], ['html_strip', 2],
            ['rt_mem_limit', '0M'], ['morphology', "stem_en\ninvalid"], ['min_infix_len', INF],
            ['invalid setting', 'x'], ['dict', 'unknown']] as [$name, $value]) {
            $db = $this->connection();
            $db->enableQueryLog();
            try {
                $db->getSchemaBuilder()->table('products', function ($table) use ($name, $value) {
                    $table->integer('qty');
                    $table->setting($name, $value);
                });
                $this->fail('Invalid ALTER setting was accepted.');
            } catch (\LogicException|\InvalidArgumentException $error) {
                $this->assertStringContainsString('setting', $error->getMessage());
            }
            $this->assertSame([], $db->getQueryLog());
        }
    }

    public function testDropCannotBeMixedWithSettingsAndSettingsReadsSupportPretend(): void
    {
        $db = $this->connection();
        $log = $db->pretend(function () use ($db) {
            $this->assertSame([], $db->getSchemaBuilder()->getSettings('products'));
        });
        $this->assertSame('SHOW TABLE `app_products` SETTINGS', $log[0]['query']);
        $this->expectException(LogicException::class);
        $db->getSchemaBuilder()->table('products', function ($table) {
            $table->setting('min_word_len', 2);
            $table->drop();
        });
    }

    public function testChangeCompilesWithoutConnectingAndRejectsOtherTargets(): void
    {
        $db = $this->connection();
        $log = $db->pretend(fn () => $db->getSchemaBuilder()->table('products', fn ($table) => $table->bigInteger('qty')->change()));
        $this->assertSame('alter table `app_products` modify column `qty` bigint', $log[0]['query']);
        foreach ([fn ($table) => $table->string('qty')->change(), fn ($table) => $table->integer('qty')->change(),
            fn ($table) => $table->bigInteger('id')->change(), fn ($table) => $table->bigInteger('qty')->unsigned()->change()] as $definition) {
            try {
                $db->getSchemaBuilder()->table('products', $definition);
                $this->fail('Unsupported type change was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        $db->getSchemaBuilder()->create('products', fn ($table) => $table->bigInteger('qty')->change());
    }

    public function testRenameAppliesPrefixesAndEscapesBothNames(): void
    {
        $db = $this->connection();
        $log = $db->pretend(fn () => $db->getSchemaBuilder()->rename('old`products', 'new`products'));
        $this->assertSame('alter table `app_old``products` rename `app_new``products`', $log[0]['query']);
        foreach (['', 'products', 'Products', 'db.products', 'new as alias', '*'] as $target) {
            try {
                $db->getSchemaBuilder()->rename('products', $target);
                $this->fail('Invalid rename target was accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('RENAME', $error->getMessage());
            }
        }
    }

    public function testRenameCannotBeMixedWithOtherOperations(): void
    {
        foreach ([fn ($table) => $table->integer('qty'), fn ($table) => $table->setting('min_word_len', 2),
            fn ($table) => $table->dropColumn('qty'), fn ($table) => $table->rename('second')] as $operation) {
            $db = $this->connection();
            $db->enableQueryLog();
            try {
                $db->getSchemaBuilder()->table('products', function ($table) use ($operation) {
                    $operation($table);
                    $table->rename('new_products');
                });
                $this->fail('Mixed rename blueprint was accepted.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('RENAME', $error->getMessage());
            }
            $this->assertSame([], $db->getQueryLog());
        }
    }
}
