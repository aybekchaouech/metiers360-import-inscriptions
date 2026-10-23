<?php

namespace App\Tests\Support;

/**
 * Typed access to the test container: PHPStan (and the IDE) know which class comes back.
 */
trait GetsServices
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
