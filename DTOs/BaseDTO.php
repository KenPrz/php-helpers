<?php

namespace App\DTOs;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Represents a base Data Transfer Object with reflection-based hydration.
 *
 * Provides standardized handling for:
 * - Constructor-based instantiation from arrays
 * - Snake_case to camelCase key mapping
 * - Nested DTO resolution
 * - Primitive type casting
 * - Recursive serialization to array
 */
abstract class BaseDTO
{
    /**
     * Create a DTO instance from an associative array.
     *
     * @param  array<string, mixed>  $data
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if (! $constructor) {
            return $reflection->newInstance();
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $args[] = self::resolveParameter($param, $data);
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Resolve a constructor parameter value from input data.
     *
     * Handles key mapping, nullability, default values, and type casting.
     *
     * @param  ReflectionParameter  $param
     * @param  array<string, mixed>  $data
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected static function resolveParameter(ReflectionParameter $param, array $data): mixed
    {
        $name = $param->getName();
        $snake = self::camelToSnake($name);

        $value = $data[$name] ?? $data[$snake] ?? null;

        if ($value === null) {
            if ($param->allowsNull()) {
                return null;
            }

            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            throw new \InvalidArgumentException("Missing required field: {$name}");
        }

        return self::castValue($param, $value);
    }

    /**
     * Cast a value to its declared parameter type.
     *
     * Supports primitive types and nested DTO resolution.
     *
     * @param  ReflectionParameter  $param
     * @param  mixed  $value
     * @return mixed
     */
    protected static function castValue(ReflectionParameter $param, mixed $value): mixed
    {
        $type = $param->getType();

        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if (class_exists($typeName) && is_subclass_of($typeName, self::class)) {
            return $typeName::fromArray($value);
        }

        return match ($typeName) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            'array' => (array) $value,
            default => $value,
        };
    }

    /**
     * Convert a camelCase string to snake_case.
     *
     * @param  string  $input
     * @return string
     */
    protected static function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/[A-Z]/', '_$0', $input));
    }

    /**
     * Convert the DTO into an associative array.
     *
     * Recursively serializes nested DTOs and arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        $data = [];

        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $this->$name;

            $data[$name] = $this->normalizeValue($value);
        }

        return $data;
    }

    /**
     * Normalize a value for array serialization.
     *
     * Converts nested DTOs and arrays of DTOs recursively.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map(
                fn ($item) => $item instanceof self ? $item->toArray() : $item,
                $value
            );
        }

        return $value;
    }
}