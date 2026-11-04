<?php

declare(strict_types=1);

namespace EasyAdminMessengerManager\Payload;

final class SensitiveProperties
{
    /** @var array<class-string, list<string>> */
    private static array $cache = [];

    /**
     * @return list<string>
     */
    public static function of(object $object): array
    {
        return self::$cache[$object::class] ??= self::scan(new \ReflectionClass($object));
    }

    /**
     * @return list<string>
     */
    private static function scan(\ReflectionClass $class): array
    {
        $parameters = $class->getConstructor()?->getParameters() ?? [];
        $sensitive = array_filter($parameters, static fn (\ReflectionParameter $parameter): bool => $parameter->isPromoted() && [] !== $parameter->getAttributes(\SensitiveParameter::class));

        return array_values(array_map(static fn (\ReflectionParameter $parameter): string => strtolower($parameter->getName()), $sensitive));
    }
}
