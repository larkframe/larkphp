<?php

namespace Lark\Core;

use ArrayObject;
use Closure;
use Exception;
use FastRoute\Dispatcher;
use Lark\Config;
use Lark\Log;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Worker;
use function array_merge;
use function array_values;
use function clearstatcache;
use function count;
use function gettype;
use function is_a;
use function is_array;
use function is_file;
use function is_string;
use function key;
use function method_exists;
use function strpos;
use function strtolower;
use function substr;

class App
{

    /**
     * @var callable[]
     */
    protected static $callbacks = [];

    /**
     * @var Worker
     */
    protected static $worker = null;

    /**
     * @var ?LoggerInterface
     */
    protected static ?LoggerInterface $logger = null;

    /**
     * @var string
     */
    protected static $requestClass = '';

    /**
     * App constructor.
     * @param string $requestClass
     * @param LoggerInterface $logger
     */
    public function __construct(string $requestClass, LoggerInterface $logger)
    {
        static::$requestClass = $requestClass;
        static::$logger = $logger;
    }

    /**
     * OnMessage.
     * @param TcpConnection|mixed $connection
     * @param Request|mixed $request
     * @return null
     * @throws Throwable
     */
    public function onMessage($connection, $request)
    {
        try {
            Context::reset(new ArrayObject([\Lark\Request::class => $request]));
            $request->initRequestIdAndStartTime();
            $path = $request->path();
            $path = preg_replace("~[\/]{2,}~", "/", $path);
            $key = $request->method() . $path;

            if (isset(static::$callbacks[$key])) {
                [$callback, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
                static::send($connection, $callback($request), $request);
                return null;
            }

            $status = 200;
            if (
                static::unsafeUri($connection, $path, $request) ||
                static::findFile($connection, $path, $key, $request) ||
                static::findRoute($connection, $path, $key, $request, $status)
            ) {
                return null;
            }
            static::send($connection, static::notFound($request), $request);

        } catch (Throwable $e) {
            echo $e->getTraceAsString();
            static::send($connection, static::exceptionResponse($e, $request), $request);
        }
        return null;
    }

    /**
     * OnWorkerStart.
     * @param $worker
     * @return void
     */
    public function onWorkerStart($worker)
    {
        static::$worker = $worker;
        Http::requestClass(static::$requestClass);
    }

    /**
     * CollectCallbacks.
     * @param string $key
     * @param array $data
     * @return void
     */
    protected static function collectCallbacks(string $key, array $data)
    {
        static::$callbacks[$key] = $data;
        if (count(static::$callbacks) >= 1024) {
            unset(static::$callbacks[key(static::$callbacks)]);
        }
    }

    /**
     * UnsafeUri.
     * @param TcpConnection $connection
     * @param string $path
     * @param $request
     * @return bool
     */
    protected static function unsafeUri(TcpConnection $connection, string $path, $request): bool
    {
        if (
            !$path ||
            $path[0] !== '/' ||
            strpos($path, '/../') !== false ||
            substr($path, -3) === '/..' ||
            strpos($path, "\\") !== false ||
            strpos($path, "\0") !== false
        ) {
            $callback = static::getFallback(400);
            $request->app = $request->controller = $request->action = '';
            static::send($connection, $callback($request, 400), $request);
            return true;
        }
        return false;
    }

    /**
     * GetFallback.
     * @param int $status
     * @return Closure
     */
    protected static function getFallback(int $status = 404): Closure
    {
        return function () use ($status) {
            throw new \RuntimeException("404 Not Found", $status);
        };
    }

    protected static function notFound($request): Response
    {
        $errorPage = config('error_page.404', null);
        if ($errorPage) {
            return redirect($errorPage);
        } else {
            return new \Lark\Response(404, [], "404 Not Found");
        }
    }

    /**
     * ExceptionResponse.
     * @param Throwable $e
     * @param $request
     * @return Response
     */
    protected static function exceptionResponse(Throwable $e, $request): Response
    {
        $response = new \Lark\Response(500, [], static::config('app.debug', true) ? (string)$e : $e->getMessage());
        $response->exception($e);
        return $response;
    }

    public static function getCallback($call, array $args = [], ?Route $route = null)
    {
        $isController = is_array($call) && is_string($call[0]);
        $middlewares = Middleware::getMiddleware($call, $route);
        $container = self::container();
        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = call_user_func($middleware, $container);
            }
            $middlewares[$key][0] = $middleware;
        }

        $anonymousArgs = array_values($args);

        if ($isController) {
            $call[0] = $container->get($call[0]);
        }
        if ($middlewares) {
            $callback = array_reduce($middlewares, function ($carry, $pipe) {
                return function ($request) use ($carry, $pipe) {
                    try {
                        return $pipe($request, $carry);
                    } catch (Throwable $e) {
                        return static::exceptionResponse($e, $request);
                    }
                };
            }, function ($request) use ($call, $anonymousArgs) {
                try {
                    $response = $call($request, ...$anonymousArgs);
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
                if (!$response instanceof \Lark\Response) {
                    if (!is_string($response)) {
                        $response = static::stringify($response);
                    }
                    $response = new \Lark\Response(200, [], $response);
                }
                return $response;
            });
        } else {
            if (!$anonymousArgs) {
                $callback = $call;
            } else {
                $callback = function ($request) use ($call, $anonymousArgs) {
                    return $call($request, ...$anonymousArgs);
                };
            }
        }
        return $callback;
    }

    /**
     * Container.
     * @return ContainerInterface
     */
    public static function container()
    {
        return static::config('container');
    }

    /**
     * Get request.
     * @return \Lark\Request
     */
    public static function request()
    {
        return Context::get(\Lark\Request::class);
    }

    /**
     * Get worker.
     * @return Worker
     */
    public static function worker(): ?Worker
    {
        return static::$worker;
    }

    /**
     * Find Route.
     * @param TcpConnection $connection
     * @param string $path
     * @param string $key
     * @param $request
     * @param $status
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException|Throwable
     */
    protected static function findRoute(TcpConnection $connection, string $path, string $key, $request, &$status): bool
    {
        $routeInfo = \Lark\Route::dispatch($request->method(), $path);
        if ($routeInfo[0] === Dispatcher::FOUND) {
            $status = 200;
            $routeInfo[0] = 'route';
            $callback = $routeInfo[1]['callback'];
            $route = clone $routeInfo[1]['route'];
            $controller = $action = '';
            $args = !empty($routeInfo[2]) ? $routeInfo[2] : [];
            if ($args) {
                $route->setParams($args);
            }
            $args = array_merge($route->param(), $args);

            if (is_array($callback)) {
                $controller = $callback[0];
                $action = $callback[1] ?? '';
                $action = $action ?? "index";
                if (!str_contains($action, 'Action')) {
                    $action .= 'Action';
                }
                $callback[1] = $action;
            }

            $callback = static::getCallback($callback, $args, $route);
            static::collectCallbacks($key, [$callback, $controller ?: '', $action, $route]);
            [$callback, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
            static::send($connection, $callback($request), $request);
            return true;
        }

        $status = $routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED ? 405 : 404;
        return false;
    }

    protected static function findFile(TcpConnection $connection, string $path, string $key, $request): bool
    {
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if (static::unsafeUri($connection, $path, $request)) {
                return true;
            }
        }

        // todo 通过配置获取
        $publicDir = ROOT_PATH . "/public";
        $file = "$publicDir" . "$path";

        if (!is_file($file)) {
            return false;
        }

        static::collectCallbacks($key, [static::getCallback(function ($request) use ($file) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                $callback = static::getFallback();
                return $callback($request);
            }
            return (new \Lark\Response())->file($file);
        }), '', '', '']);
        [$callback, $request->controller, $request->action, $request->route] = static::$callbacks[$key];
        static::send($connection, $callback($request), $request);
        return true;
    }

    /**
     * Send.
     * @param TcpConnection|mixed $connection
     * @param mixed|Response $response
     * @param Request|mixed $request
     * @return void
     */
    protected static function send(mixed $connection, mixed $response, mixed $request): void
    {
        // todo 性能优化
        Log::info("");

        Context::destroy();

        $keepAlive = $request->header('connection');
        if (($keepAlive === null && $request->protocolVersion() === '1.1')
            || $keepAlive === 'keep-alive' || $keepAlive === 'Keep-Alive'
            || (is_a($response, Lark\Response::class) && $response->getHeader('Transfer-Encoding') === 'chunked')
        ) {
            $connection->send($response);
            return;
        }

        $connection->close($response);
    }

    /**
     * ExecPhpFile.
     * @param string $file
     * @return false|string
     */
    public static function execPhpFile(string $file)
    {
        ob_start();
        // Try to include php file.
        try {
            include $file;
        } catch (Exception $e) {
            echo $e;
        }
        return ob_get_clean();
    }

    /**
     * Config.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected static function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }


    /**
     * @param mixed $data
     * @return string
     */
    protected static function stringify(mixed $data): string
    {
        $type = gettype($data);
        switch ($type) {
            case 'boolean':
                return $data ? 'true' : 'false';
            case 'NULL':
                return 'NULL';
            case 'array':
                return 'Array';
            case 'object':
                if (!method_exists($data, '__toString')) {
                    return 'Object';
                }
            default:
                return (string)$data;
        }
    }
}
