<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Support;

use OmarKhaled\Findex\Attributes\FindexColumns;
use ReflectionClass;

/**
 * Reads the #[FindexColumns] attribute of a class, once.
 *
 * @internal
 */
final class AttributeReader
{
    /** @var array<class-string, FindexColumns|null> */
    private static array $resolved = [];

    /**
     * The attribute declared on the class or, failing that, on its closest parent.
     *
     * @param  class-string  $class
     */
    public static function for(string $class): ?FindexColumns
    {
        if (array_key_exists($class, self::$resolved)) {
            return self::$resolved[$class];
        }

        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $attributes = $reflection->getAttributes(FindexColumns::class);

            if ($attributes !== []) {
                return self::$resolved[$class] = $attributes[0]->newInstance();
            }
        }

        return self::$resolved[$class] = null;
    }
}
