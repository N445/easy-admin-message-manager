<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Payload;

final readonly class PayloadNormalizer
{
    public const string MASK = '********';
    private const int MAX_DEPTH = 8;
    private const int MAX_ITEMS = 500;
    private const int MAX_STRING = 20000;

    /** @var list<string> */
    private array $redactedKeys;

    /**
     * @param list<string> $redactedKeys
     */
    public function __construct(array $redactedKeys)
    {
        $this->redactedKeys = array_map(strtolower(...), $redactedKeys);
    }

    public function json(mixed $value): string
    {
        return json_encode($this->normalize($value), \JSON_PRETTY_PRINT | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function normalize(mixed $value): mixed
    {
        return $this->value($value, 0, []);
    }

    /**
     * @param list<object> $parents
     */
    private function value(mixed $value, int $depth, array $parents): mixed
    {
        return match (true) {
            null === $value, \is_bool($value), \is_int($value) => $value,
            \is_float($value) => is_finite($value) ? $value : (string) $value,
            \is_string($value) => $this->string($value),
            \is_array($value) => $this->collection($value, $depth, $parents),
            $value instanceof \UnitEnum => $this->enum($value),
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            $value instanceof \Closure => 'Closure',
            \is_object($value) => $this->object($value, $depth, $parents),
            default => get_debug_type($value),
        };
    }

    private function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return \sprintf('binary (%d bytes)', \strlen($value));
        }

        return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value;
    }

    /**
     * @param array<mixed>  $values
     * @param list<object>  $parents
     *
     * @return array<mixed>|string
     */
    private function collection(array $values, int $depth, array $parents): array|string
    {
        if ($depth >= self::MAX_DEPTH) {
            return \sprintf('array (%d items)', \count($values));
        }

        $normalized = [];
        foreach (\array_slice($values, 0, self::MAX_ITEMS, true) as $key => $item) {
            $normalized[$key] = $this->isRedacted((string) $key, []) ? self::MASK : $this->value($item, $depth + 1, $parents);
        }

        return $normalized;
    }

    private function enum(\UnitEnum $enum): string|int
    {
        return $enum instanceof \BackedEnum ? $enum->value : $enum::class.'::'.$enum->name;
    }

    /**
     * @param list<object> $parents
     *
     * @return array<string, mixed>|string
     */
    private function object(object $object, int $depth, array $parents): array|string
    {
        $class = $this->classOf($object);
        if (\in_array($object, $parents, true)) {
            return \sprintf('%s (recursion)', $class);
        }

        if ($depth >= self::MAX_DEPTH) {
            return $class;
        }

        return ['@class' => $class, ...$this->properties($object, $depth, [...$parents, $object])];
    }

    /**
     * @param list<object> $parents
     *
     * @return array<string, mixed>
     */
    private function properties(object $object, int $depth, array $parents): array
    {
        $sensitive = $object instanceof \__PHP_Incomplete_Class ? [] : SensitiveProperties::of($object);
        $properties = [];
        foreach ((array) $object as $key => $value) {
            $name = substr((string) $key, (int) strrpos("\0".$key, "\0"));
            $properties[$name] = $this->isRedacted($name, $sensitive) ? self::MASK : $this->value($value, $depth + 1, $parents);
        }

        unset($properties['__PHP_Incomplete_Class_Name']);

        return $properties;
    }

    private function classOf(object $object): string
    {
        if ($object instanceof \__PHP_Incomplete_Class) {
            return (string) ((array) $object)['__PHP_Incomplete_Class_Name'];
        }

        return $object::class;
    }

    /**
     * @param list<string> $sensitive
     */
    private function isRedacted(string $name, array $sensitive): bool
    {
        $name = strtolower($name);

        return \in_array($name, $this->redactedKeys, true) || \in_array($name, $sensitive, true);
    }
}
