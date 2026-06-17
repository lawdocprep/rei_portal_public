<?php
declare(strict_types=1);

namespace App\Container;

use PDO;
use ReflectionClass;
use RuntimeException;

final class Container
{
    /**
     * Cached service instances
     */
    private array $instances = [];

    /**
     * Explicit parameter / service bindings
     * (used for primitives, secrets, configs, prebuilt objects)
     */
    private array $bindings = [];

    /**
     * Bind a concrete value or instance to a key
     */
    public function set(string $key, mixed $value): void
    {
        $this->bindings[$key] = $value;
    }

    /**
     * Resolve a class or bound value
     */
    public function get(string $id)
    {
        // Explicit binding wins
        if (array_key_exists($id, $this->bindings)) {
            return $this->bindings[$id];
        }

        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $this->build($id);
        }

        return $this->instances[$id];
    }

    /**
     * Build a class via reflection-based autowiring
     */
    private function build(string $class)
    {
        // --------------------------------------------------
        // Special-case: PDO (infrastructure dependency)
        // --------------------------------------------------
        if ($class === PDO::class) {
            static $pdo = null;

            if ($pdo === null) {
                $pdo = new PDO(
                    'pgsql:host=127.0.0.1;port=5432;dbname=portal',
                    'portal',
                    'portal_pw',
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
            }

            return $pdo;
        }

        // --------------------------------------------------
        // Reflection-based autowiring
        // --------------------------------------------------
        if (!class_exists($class)) {
            throw new RuntimeException("Cannot resolve service: {$class} does not exist");
        }

        $ref  = new ReflectionClass($class);
        $ctor = $ref->getConstructor();

        if ($ctor === null) {
            return new $class();
        }

        $args = [];

        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();

            if ($type === null) {
                throw new RuntimeException(
                    "Cannot autowire {$class}: parameter \${$param->getName()} has no type"
                );
            }

            // 🚫 Reject primitives (string, int, bool, etc.)
            if ($type->isBuiltin()) {
                throw new RuntimeException(
                    "Cannot autowire {$class}: parameter \${$param->getName()} "
                    . "is a primitive ({$type->getName()}). "
                    . "Bind it explicitly in the container."
                );
            }

            $args[] = $this->get($type->getName());
        }

        return $ref->newInstanceArgs($args);
    }
}
