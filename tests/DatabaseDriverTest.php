<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreConnector;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Illuminate\Database\QueryException;

class DatabaseDriverTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => '127.0.0.1', 'port' => 1,
        ]);
    }

    public function testDatabaseManagerResolvesTheDriverWithoutOpeningPdo(): void
    {
        $connection = DB::connection('manticore');
        $this->assertInstanceOf(ManticoreConnection::class, $connection);
        $this->assertSame('manticore', $connection->getName());
        $this->assertInstanceOf(\Closure::class, $connection->getRawPdo());
        $this->assertInstanceOf(ManticoreQueryBuilder::class, $connection->table('products'));
    }

    public function testNamedConnectionsAndPurgeUseTheLaravelPool(): void
    {
        config(['database.connections.other_search' => config('database.connections.manticore')]);
        $main = DB::connection('manticore');
        $other = DB::connection('other_search');
        $this->assertNotSame($main, $other);
        $this->assertSame($main, DB::connection('manticore'));
        DB::purge('manticore');
        $this->assertNotSame($main, DB::connection('manticore'));
        $this->assertSame($other, DB::connection('other_search'));
    }

    public function testSelectMatchAndRawBindingsFollowLaravelOrder(): void
    {
        $query = DB::connection('manticore')->table('products')
            ->select('id', 'title')->selectRaw('price * ? as adjusted', [2])
            ->match("galaxy's phone")
            ->where(fn ($q) => $q->where('price', '>', 100)->orWhere('brand', 'acme'))
            ->orderBy('id')->offset(20)->limit(10)->option('max_matches', 1000);

        $this->assertSame(
            'select `id`, `title`, price * ? as adjusted from `products` where MATCH(?) and (`price` > ? or `brand` = ?) order by `id` asc limit 20, 10 option max_matches=1000',
            $query->toSql(),
        );
        $this->assertSame([2, "galaxy's phone", 100, 'acme'], $query->getBindings());
        $this->assertSame($query, $query->select('id'));
        $this->assertSame(["galaxy's phone", 100, 'acme'], $query->getBindings());
    }

    public function testOptionsCanBeReplacedRemovedAndCloned(): void
    {
        $query = DB::connection('manticore')->table('products')->option('MAX_MATCHES', 100);
        $clone = (clone $query)->option('max_matches', 200)->option('cutoff', 10);
        $this->assertStringEndsWith('option max_matches=100', $query->toSql());
        $this->assertStringEndsWith('option max_matches=200, cutoff=10', $clone->toSql());
        $this->assertSame('select * from `products`', $query->option('max_matches', null)->toSql());
    }

    public function testRawSqlRenderingEscapesValuesWithoutOpeningPdo(): void
    {
        $query = DB::connection('manticore')->table('products')->where('brand', "O'Reilly\\books");
        $this->assertSame("select * from `products` where `brand` = 'O\\'Reilly\\\\books'", $query->toRawSql());
        $this->assertInstanceOf(\Closure::class, $query->getConnection()->getRawPdo());
    }

    public function testOptionNamesCannotInjectSql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::connection('manticore')->table('products')->option('max_matches; DROP TABLE products', 10);
    }

    public function testOptionsRejectNonFiniteNumbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::connection('manticore')->table('products')->option('max_matches', INF);
    }

    public function testPrefixAndGroupingUseTheStandardGrammarPipeline(): void
    {
        $connection = new ManticoreConnection(fn () => throw new LogicException('Unexpected connection'), '', 'app_');
        $query = $connection->table('products')->select('brand')->selectRaw('count(*) as n')
            ->whereIn('id', [1, 2])->groupBy('brand')->havingRaw('n > ?', [1]);
        $this->assertSame('select `brand`, count(*) as n from `app_products` where `id` in (?, ?) group by `brand` having n > ?', $query->toSql());
        $this->assertSame([1, 2, 1], $query->getBindings());
    }

    public function testPretendEmitsLaravelEventsWithoutConnecting(): void
    {
        $connection = DB::connection('manticore');
        $events = [];
        DB::listen(static function (QueryExecuted $event) use (&$events) { $events[] = $event; });
        $log = $connection->pretend(fn () => $connection->table('products')->match('galaxy')->get());
        $this->assertCount(1, $log);
        $this->assertSame(['galaxy'], $log[0]['bindings']);
        $this->assertCount(1, $events);
        $this->assertSame($connection, $events[0]->connection);
        $this->assertInstanceOf(\Closure::class, $connection->getRawPdo());
    }

    public function testLocksFailBeforeSendingUnsupportedSql(): void
    {
        $this->expectException(LogicException::class);
        DB::connection('manticore')->table('products')->lockForUpdate()->toSql();
    }

    public function testExistsSubqueriesAreRejected(): void
    {
        $this->expectException(LogicException::class);
        DB::connection('manticore')->table('products')->whereExists(fn ($q) => $q->from('other'))->toSql();
    }

    public function testOffsetRequiresALimit(): void
    {
        $this->expectException(LogicException::class);
        DB::connection('manticore')->table('products')->offset(20)->toSql();
    }

    public function testDeleteDoesNotSilentlyDiscardALimit(): void
    {
        $this->expectException(LogicException::class);
        DB::connection('manticore')->table('products')->limit(1)->delete();
    }

    public function testUpdateDoesNotSilentlyDiscardOrdering(): void
    {
        $this->expectException(LogicException::class);
        DB::connection('manticore')->table('products')->orderBy('id')->update(['price' => 100]);
    }

    public function testLaravelMacrosCanExtendTheNativeBuilder(): void
    {
        ManticoreQueryBuilder::macro('expensive', function () { return $this->where('price', '>', 100); });
        try {
            $query = DB::connection('manticore')->table('products')->expensive();
            $this->assertSame('select * from `products` where `price` > ?', $query->toSql());
            $this->assertSame([100], $query->getBindings());
        } finally {
            ManticoreQueryBuilder::flushMacros();
        }
    }

    public function testLostWriteResponsesAreNotReplayed(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')
            ->willThrowException(new PDOException('MySQL server has gone away'));
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($statement);
        $connection = new ManticoreConnection($pdo, '', '', ['driver' => 'manticore']);
        $connection->setReconnector(static function () { throw new LogicException('A write must not reconnect and replay'); });

        $this->expectException(QueryException::class);
        $connection->insert('INSERT INTO products (id) VALUES (?)', [1]);
    }

    public function testConnectorOnlyOpensPdoWithoutMysqlSessionSetup(): void
    {
        $connector = new class extends ManticoreConnector {
            public array $received;

            public function createConnection($dsn, array $config, array $options)
            {
                $this->received = [$dsn, $options];
                // No methods may be called on this PDO: there is no actual connection.
                return new class extends PDO { public function __construct() {} };
            }
        };
        $connector->connect(['host' => 'localhost', 'port' => 9306, 'timeout' => 3, 'database' => 'ignored']);
        $this->assertSame('mysql:host=localhost;port=9306', $connector->received[0]);
        $this->assertSame(3, $connector->received[1][PDO::ATTR_TIMEOUT]);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $connector->received[1][PDO::ATTR_ERRMODE]);
        $this->assertTrue($connector->received[1][PDO::ATTR_EMULATE_PREPARES]);
    }

    public function testNativePreparedStatementsCannotSilentlyRoundWrites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ManticoreConnector())->connect(['options' => [PDO::ATTR_EMULATE_PREPARES => false]]);
    }
}
