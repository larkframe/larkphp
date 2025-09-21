<?php

namespace Lark\Core\Database;

use Illuminate\Container\Container as IlluminateContainer;
use Lark\Container;
use Lark\Core\Database\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Jenssegers\Mongodb\Connection as JenssegersMongodbConnection;
use MongoDB\Laravel\Connection as LaravelMongodbConnection;

class Initializer
{

    /**
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * @param $config
     * @return void
     */
    public static function init($config): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $connections = $config['connections'] ?? [];
        if (!$connections) {
            return;
        }

        $capsule = new Capsule(IlluminateContainer::getInstance());

        $capsule->getDatabaseManager()->extend('mongodb', function ($config, $name) {
            $config['name'] = $name;
            return class_exists(LaravelMongodbConnection::class) ? new LaravelMongodbConnection($config) : new JenssegersMongodbConnection($config);
        });

        $default = $config['default'] ?? false;
        if ($default) {
            $defaultConfig = $connections[$default] ?? false;
            if ($defaultConfig) {
                $capsule->addConnection($defaultConfig, $default);
                $capsule->getDatabaseManager()->setDefaultConnection($default);
                unset($connections[$default]);
            }
        }

        foreach ($connections as $name => $config) {
            $capsule->addConnection($config, $name);
        }

        if (class_exists(Dispatcher::class) && !$capsule->getEventDispatcher()) {
            $capsule->setEventDispatcher(Container::make(Dispatcher::class, [IlluminateContainer::getInstance()]));
        }

        $capsule->setAsGlobal();

        $capsule->bootEloquent();
        
        // Paginator
        if (class_exists(Paginator::class)) {
            if (method_exists(Paginator::class, 'queryStringResolver')) {
                Paginator::queryStringResolver(function () {
                    $request = request();
                    return $request?->queryString();
                });
            }
            Paginator::currentPathResolver(function () {
                $request = request();
                return $request ? $request->path(): '/';
            });
            Paginator::currentPageResolver(function ($pageName = 'page') {
                $request = request();
                if (!$request) {
                    return 1;
                }
                $page = (int)($request->input($pageName, 1));
                return $page > 0 ? $page : 1;
            });
            if (class_exists(CursorPaginator::class)) {
                CursorPaginator::currentCursorResolver(function ($cursorName = 'cursor') {
                    return Cursor::fromEncoded(request()->input($cursorName));
                });
            }
        }
    }

}
