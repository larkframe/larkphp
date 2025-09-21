<?php

namespace Lark\Core;


use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use function array_merge;
use function array_reverse;
use function is_array;
use function method_exists;

class Middleware
{

    /**
     * @var array
     */
    protected static $instances = [];

    /**
     * @param mixed $allMiddlewares
     * @return void
     */
    public static function load($middlewares)
    {
        if (!is_array($middlewares)) {
            return;
        }
        foreach ($middlewares as $className) {
            if (method_exists($className, 'process')) {
                static::$instances[] = [$className, 'process'];
            } else {
                // @todo Log
                echo "middleware $className::process not exsits\n";
            }
        }
    }

    /**
     * @param string|array|Closure $controller
     * @param Route|null $route
     * @return array
     */
    public static function getMiddleware(string|array|Closure $controller, Route|null $route): array
    {
        $isController = is_array($controller) && is_string($controller[0]);
        $middlewares = static::$instances;
        $routeMiddlewares = [];
        // Route middleware
        if ($route) {
            foreach (array_reverse($route->getMiddleware()) as $className) {
                $routeMiddlewares[] = [$className, 'process'];
            }
        }
        if ($isController && $controller[0] && class_exists($controller[0])) {
            // Controller middleware annotation
            $reflectionClass = new ReflectionClass($controller[0]);
            self::prepareAttributeMiddlewares($middlewares, $reflectionClass);
            // Controller middleware property
            if ($reflectionClass->hasProperty('middleware')) {
                $defaultProperties = $reflectionClass->getDefaultProperties();
                $middlewaresClasses = $defaultProperties['middleware'];
                foreach ((array)$middlewaresClasses as $className) {
                    $middlewares[] = [$className, 'process'];
                }
            }
            // Route middleware
            $middlewares = array_merge($middlewares, $routeMiddlewares);
            // Method middleware annotation
            if ($reflectionClass->hasMethod($controller[1])) {
                self::prepareAttributeMiddlewares($middlewares, $reflectionClass->getMethod($controller[1]));
            }
        } else {
            // Route middleware
            $middlewares = array_merge($middlewares, $routeMiddlewares);
        }
        return array_reverse($middlewares);

    }

    /**
     * @param array $middlewares
     * @param ReflectionClass|ReflectionMethod $reflection
     * @return void
     */
    private static function prepareAttributeMiddlewares(array &$middlewares, ReflectionClass|ReflectionMethod $reflection): void
    {
        $middlewareAttributes = $reflection->getAttributes(Annotation\Middleware::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($middlewareAttributes as $middlewareAttribute) {
            $middlewareAttributeInstance = $middlewareAttribute->newInstance();
            $middlewares = array_merge($middlewares, $middlewareAttributeInstance->getMiddlewares());
        }
    }
}
