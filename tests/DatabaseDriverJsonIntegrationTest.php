<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;

class DatabaseDriverJsonIntegrationTest extends DatabaseTestCase
{
    private ?string $jsonTable = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 3,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->jsonTable = $this->tableName('json');
        $db = DB::connection('manticore');
        $db->statement("CREATE TABLE {$this->jsonTable} (title text, details json, stock int)");
        foreach ([
            1 => ['color' => 'red', 'tags' => ['sale', 'new'], 'codes' => [1, 2], 'dimensions' => ['width' => 120], 'active' => true, 'nullable' => null, 'odd.key' => "o'brien"],
            2 => ['color' => 'blue', 'tags' => ['sale'], 'codes' => [2, 3], 'dimensions' => ['width' => 80], 'active' => false, 'nullable' => 0],
            3 => ['color' => 'red', 'tags' => [], 'codes' => [], 'active' => false],
            4 => ['color' => 'green', 'tags' => ['old'], 'codes' => [4], 'nullable' => false],
            5 => ['color' => 'black'],
        ] as $id => $details) {
            $db->table($this->jsonTable)->insert(['id' => $id, 'title' => 'phone', 'details' => $details, 'stock' => $id]);
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->jsonTable !== null) {
                DB::connection('manticore')->statement("DROP TABLE IF EXISTS {$this->jsonTable}");
            }
        } finally {
            parent::tearDown();
        }
    }

    private function baseQuery()
    {
        return DB::connection('manticore')->table($this->jsonTable);
    }

    public function testScalarPathsBooleansIndexesAndQuotedKeys(): void
    {
        $this->assertSame([1, 3], $this->baseQuery()->where('details->color', 'red')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1], $this->baseQuery()->where('details->dimensions->width', '>', 100)->pluck('id')->all());
        $this->assertSame([1], $this->baseQuery()->where('details->tags[1]', 'new')->pluck('id')->all());
        $this->assertSame([1, 2], $this->baseQuery()->whereIn('details->color', ['red', 'blue'])->where('id', '<', 3)->orderBy('id')->pluck('id')->all());
        $this->assertSame([2, 3], $this->baseQuery()->where('details->active', false)->orderBy('id')->pluck('id')->all());
        $this->assertSame([1], $this->baseQuery()->where('details->"odd.key"', "o'brien")->pluck('id')->all());
    }

    public function testContainsRequiresAllValuesAndOverlapsRequiresAny(): void
    {
        $this->assertSame([1, 2], $this->baseQuery()->whereJsonContains('details->tags', 'sale')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1], $this->baseQuery()->whereJsonContains('details->tags', ['sale', 'new'])->pluck('id')->all());
        $this->assertSame([3, 4, 5], $this->baseQuery()->whereJsonDoesntContain('details->tags', 'sale')->orderBy('id')->pluck('id')->all());
        $this->assertSame([2, 3, 4, 5], $this->baseQuery()->whereJsonDoesntContain('details->tags', ['sale', 'new'])->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 4], $this->baseQuery()->whereJsonOverlaps('details->tags', ['new', 'old'])->orderBy('id')->pluck('id')->all());
        $this->assertSame([2, 3, 5], $this->baseQuery()->whereJsonDoesntOverlap('details->tags', ['new', 'old'])->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 2], $this->baseQuery()->whereJsonContains('details->codes', 2)->orderBy('id')->pluck('id')->all());
        $this->assertSame([], $this->baseQuery()->whereJsonOverlaps('details->tags', [])->pluck('id')->all());
        $this->assertSame(5, $this->baseQuery()->whereJsonDoesntOverlap('details->tags', [])->count());
    }

    public function testKeyPresenceDocumentsNativeNullSemanticsAndArrayBounds(): void
    {
        $this->assertSame([2, 4], $this->baseQuery()->whereJsonContainsKey('details->nullable')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 3, 5], $this->baseQuery()->whereJsonDoesntContainKey('details->nullable')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1], $this->baseQuery()->whereJsonContainsKey('details->tags[1]')->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4], $this->baseQuery()->whereJsonContainsKey('details->tags')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 3, 5], $this->baseQuery()->whereNull('details->nullable')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 2], $this->baseQuery()->where('id', 2)->orWhereJsonContainsKey('details->tags[1]')->orderBy('id')->pluck('id')->all());
    }

    public function testLengthsNestedOrAndFullTextSurviveAllReadMethods(): void
    {
        $this->assertSame([3], $this->baseQuery()->whereJsonLength('details->tags', 0)->pluck('id')->all());
        $this->assertSame([1], $this->baseQuery()->whereJsonLength('details->tags', '>', 1)->pluck('id')->all());
        $this->assertSame([1, 3], $this->baseQuery()->whereJsonLength('details->tags', 0)->orWhereJsonLength('details->tags', '>', 1)->orderBy('id')->pluck('id')->all());
        $query = $this->baseQuery()->whereFullText('title', 'phone')->where(function ($q) {
            $q->whereJsonContains('details->tags', 'new')->orWhereJsonContains('details->codes', 3);
        });
        $this->assertSame([1, 2], (clone $query)->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, (clone $query)->count());
        $this->assertSame(2, (clone $query)->search()->meta()->totalFound());
        $this->assertSame(2, (clone $query)->orderBy('id')->paginate(1)->total());
        $this->assertSame([1, 2], (clone $query)->orderBy('id')->cursor()->pluck('id')->all());
        $this->assertSame([1, 2], (clone $query)->orderBy('id')->lazy(1)->pluck('id')->all());
        $this->assertSame(['id'], array_keys((array) (clone $query)->first(['id'])));
    }

    public function testPathsFilterWritesWithoutReplacingTheJsonDocument(): void
    {
        $this->assertSame(2, $this->baseQuery()->where('details->color', 'red')->update(['stock' => 9]));
        $this->assertSame(1, $this->baseQuery()->whereJsonDoesntContainKey('details->tags')->delete());
        $this->assertSame([1, 3], $this->baseQuery()->where('stock', 9)->orderBy('id')->pluck('id')->all());
    }

    public function testParametersKeepQuotesFloatsAndBooleansAndSupportRootJson(): void
    {
        $needle = "sale' OR 1=1 -- ? \\";
        $this->baseQuery()->insert(['id' => 6, 'title' => 'phone', 'stock' => 6,
            'details' => ['tags' => [$needle], 'prices' => [1.5, 3.25], 'flags' => [true]]]);
        $this->assertSame([6], $this->baseQuery()->whereJsonContains('details->tags', $needle)->pluck('id')->all());
        $this->assertSame([6], $this->baseQuery()->whereJsonContains('details->prices', 1.5)->pluck('id')->all());
        $this->assertSame([6], $this->baseQuery()->whereJsonContains('details->flags', true)->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->baseQuery()->whereJsonLength('details', '>', 0)->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->baseQuery()->whereJsonContainsKey('details')->orderBy('id')->pluck('id')->all());
    }

    public function testJsonFiltersApplyToBothHybridBranchesAndKnn(): void
    {
        $db = DB::connection('manticore');
        $name = $this->tableName('jsonhybrid');
        try {
            $db->getSchemaBuilder()->create($name, function ($table) {
                $table->text('title');
                $table->json('details');
                $table->floatVector('embedding', 3, 'l2');
            });
            foreach ([1 => ['phone', ['sale'], [10, 0, 0]], 2 => ['semantic', ['sale'], [0, 0, 0]],
                3 => ['phone', ['old'], [0, 0, 0]]] as $id => [$title, $tags, $vector]) {
                $db->table($name)->insert(['id' => $id, 'title' => $title, 'details' => ['tags' => $tags, 'active' => true], 'embedding' => $vector]);
            }
            $query = $db->table($name)->whereJsonContains('details->tags', 'sale')
                ->whereJsonLength('details->tags', '>=', 1)->whereJsonContainsKey('details->active')->where('details->active', true);
            $bindings = $query->getBindings();
            $result = $query->hybridSearch('phone', 'embedding', [0, 0, 0]);
            $this->assertSame([1, 2], $result->rows()->pluck('id')->sort()->values()->all());
            $this->assertSame(1, $result->textMeta->totalFound());
            $this->assertSame(2, $result->vectorMeta->totalFound());
            $this->assertSame([2, 1], (clone $query)->knn('embedding', [0, 0, 0], 3)->orderByKnnDistance()->pluck('id')->all());
            $this->assertSame($bindings, $query->getBindings());
        } finally {
            $db->statement('DROP TABLE IF EXISTS '.$name);
        }
    }
}
