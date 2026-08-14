<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Facade;
use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\QueryBuilder\Connection;
use ReflectionClass;
use ReflectionMethod;

/**
 * The "@method" annotations of Manager and Facade.
 *
 * Both hand unknown calls over to the connection, so an IDE only knows what the annotations
 * say - and annotations rot silently. These tests fail instead: when the query builder gains
 * or renames a method of Connection, the list has to be updated with it.
 */
class AnnotationsTest extends TestCase
{
    /**
     * Method names of the "@method" lines of a class docblock
     *
     * @param string $class
     *
     * @return array
     */
    private function annotated(string $class): array
    {
        $doc = (new ReflectionClass($class))->getDocComment();
        $this->assertNotFalse($doc, $class . ' has no docblock');

        preg_match_all('/@method\s+(?:static\s+)?[^\s]+\s+(\w+)\s*\(/', (string)$doc, $matches);

        return $matches[1];
    }

    /**
     * Public methods a call is proxied to
     *
     * @return array
     */
    private function connectionMethods(): array
    {
        $names = [];
        foreach ((new ReflectionClass(Connection::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && !$method->isConstructor()) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    public function testEveryAnnotatedMethodExists(): void
    {
        $known = array_merge($this->connectionMethods(), get_class_methods(Manager::class));

        foreach ([Manager::class, Facade::class] as $class) {
            foreach ($this->annotated($class) as $name) {
                $this->assertContains($name, $known, $class . ' annotates "' . $name . '", which does not exist');
            }
        }
    }

    public function testEveryProxiedMethodIsAnnotated(): void
    {
        $annotated = $this->annotated(Manager::class);

        foreach ($this->connectionMethods() as $name) {
            $this->assertContains($name, $annotated, 'Manager does not annotate "' . $name . '" of the connection');
        }
    }

    /**
     * The facade resolves to the Manager, so its own methods have to be there as well
     */
    public function testTheFacadeAnnotatesTheMethodsOfTheManager(): void
    {
        $annotated = $this->annotated(Facade::class);

        foreach (['connection', 'getDefaultConnection', 'setDefaultConnection', 'getConnections'] as $name) {
            $this->assertContains($name, $annotated);
        }
    }

    /**
     * A method the facade lists but the manager does not proxy would be a lie of the same kind
     */
    public function testTheTwoListsAgree(): void
    {
        $manager = $this->annotated(Manager::class);
        $facade = $this->annotated(Facade::class);
        $ownOfManager = ['connection', 'getDefaultConnection', 'setDefaultConnection', 'getConnections'];

        $this->assertSame([], array_values(array_diff($manager, $facade)));
        $this->assertSame($ownOfManager, array_values(array_diff($facade, $manager)));
    }
}
