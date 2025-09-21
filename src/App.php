<?php
declare(strict_types=1);

namespace Lark;

use Exception;
use FastRoute\Dispatcher;
use Lark\Core\Context;
use Lark\Core\Database\Initializer;
use Lark\Core\Middleware;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Worker;

class App
{
    public static function run()
    {
        if (!defined('ROOT_PATH')) {
            exit('Please define ROOT_PATH constant');
        }
        ini_set('display_errors', 'on');
        error_reporting(E_ALL);
        class_alias("\Lark\Core\Request", "\Workerman\Protocols\Http\Request");
        define('RUN_START_TIME', microtime(true));
        $env = [];
        try {
            $envFile = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
            if (!file_exists($envFile)) {
                file_put_contents($envFile, "APP_NAME=app\r\nTIME_ZONE=Asia/Shanghai\r\nRUN_MODE=dev\r\n");
            }
            $env = Config::loadEnv($envFile);
        } catch (Exception $e) {
            // nothing
        }

        date_default_timezone_set($env['TIME_ZONE'] ?? 'Asia/Shanghai');
        define('RUN_MODE', strtolower($env['RUN_MODE']) ?? 'dev');
        define('APP_NAME', $env['APP_NAME'] ?? 'app');

        // 加载config
        Config::load();
        Initializer::init(config('database', []));

        $runType = static::getRunType();
        define('RUN_TYPE', $runType);

        switch (RUN_TYPE) {
            case Consts::RUN_TYPE_SERVER:
                static::runAsServer();
                break;
            case Consts::RUN_TYPE_SHELL:
            case Consts::RUN_TYPE_WEB:
                $response = static::runAsNormal();
                echo $response;
                break;
            default:
                static::runAsTask();
        }
    }

    private static function runAsServer()
    {
        $config = config('server');
        Worker::$pidFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($config['pidFile'] ?? 'server.pid');
        Worker::$stdoutFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($config['stdoutFile'] ?? 'server.stdout.log');
        Worker::$logFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($config['logFile'] ?? 'server.log');
        Worker::$eventLoopClass = $config['eventLoopClass'] ?? '';
        Worker::$daemonize = $config['daemonize'] ?? false;
        TcpConnection::$defaultMaxPackageSize = 10 * 1024 * 1024;

        $listen = $config['socketName'] ?? '127.0.0.1:8080';
        $worker = new Worker($listen, []);
        $config = config('server');
        $worker->name = config('app.name', 'lark-server');
        $worker->count = $config['worker']['count'] ?? 1;
        $worker->reusePort = $config['worker']['reusePort'] ?? true;

        $accessLogName = $config['accessLog'] ?? 'default';

        $worker->onWorkerStart = function ($worker) use ($accessLogName) {
            $worker = $worker ?? null;
            if (empty(Worker::$eventLoopClass)) {
                Worker::$eventLoopClass = Select::class;
            }

            set_error_handler(function ($level, $message, $file = '', $line = 0) {
                if (error_reporting() & $level) {
                    throw new \ErrorException($message, 0, $level, $file, $line);
                }
            });

            if ($worker) {
                register_shutdown_function(function ($startTime) {
                    if (time() - $startTime <= 0.1) {
                        sleep(1);
                    }
                }, time());
            }

            Config::clear();
            Config::load();
            Route::load();
            Middleware::load(config('server.middleware', []));
            $app = new Core\App(Request::class, Log::channel($accessLogName));
            $worker->onMessage = [$app, 'onMessage'];
            call_user_func([$app, 'onWorkerStart'], $worker);
        };

        Worker::runAll();
    }

    private static function runAsNormal()
    {
        if (in_array(RUN_TYPE, [Consts::RUN_TYPE_SHELL, Consts::RUN_TYPE_WEB])) {
            if (config('error.catch', false)) {
                $errorHandler = config("error.handler", null);
                if ($errorHandler !== null) {
                    if (method_exists($errorHandler, "register")) {
                        $errorHandlerOption = config("error.options", []);
                        call_user_func([$errorHandler, 'register'], $errorHandlerOption);
                    }
                }
            }

            Route::load();
            // 路由处理
            if (RUN_TYPE == Consts::RUN_TYPE_WEB) {
                $method = strtoupper($_SERVER['REQUEST_METHOD']);
                $route = str_replace($_SERVER['QUERY_STRING'], "", $_SERVER['REQUEST_URI']);
                if (str_ends_with($route, "/") || str_ends_with($route, "?")) {
                    $route = substr($route, 0, strlen($route) - 1);
                }
                if (!$route) {
                    $route = '/';
                }

                if (str_starts_with($route, "/.")) {
                    return '403 Forbidden';
                }
            } else {
                //
                $method = "SHELL";
                $route = $_SERVER['argv'][1] ?? '';
                if (!$route) {
                    $route = '/';
                }
            }
            $route = preg_replace("~[\/]{2,}~", "/", $route);
            define('ROUTE_VALUE', $route);
            $request = new Request();
            Context::set(Request::class, $request);

            $routeInfo = Route::dispatch($method, $route);
            if ($routeInfo[0] === Dispatcher::FOUND) {
                $routeInfo[0] = 'route';
                $callback = $routeInfo[1]['callback'];

                $controller = $callback[0];
                $action = $callback[1] ?? '';
                $action = $action ?? "index";
                if (!str_contains($action, 'Action')) {
                    $action .= 'Action';
                }
                $callback[1] = $action;

                $container = Core\App::container();
                $call = [$controller, $action];
                $call[0] = $container->get($call[0]);
                $callback = $call;
                $result = $callback($request);
                Log::info("");
                return $result;
            } else {
                $filePath = realpath(ROOT_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $route);
                if ($filePath && file_exists($filePath) && is_file($filePath)) {
                    $result = (new \Lark\Response())->file($filePath);
                    Log::info("");
                    return $result;
                }
            }
            return "404 Not Found";
        } else {
            return "Error Run Type";
        }
    }

    private static function runAsTask()
    {
        if (PHP_SAPI != 'cli') {
            return;
        }
        $taskName = $_SERVER['argv']['1'] ?? '';
        $taskArgs = $_SERVER['argv']['2'] ?? '';
        $taskConfig = config('task.' . $taskName, []);

        if (empty($taskConfig) || !isset($taskConfig['handler'])
            || !class_exists($taskConfig['handler']) || !method_exists($taskConfig['handler'], 'run')) {
            echo $taskConfig['handler']." not useable\n";
            return;
        }

        $call = new \ReflectionMethod($taskConfig['handler'], 'run');
        if (!$call->isStatic()) {
            echo $taskConfig['handler'].".run not statically\n";
            return;
        }

        try {
            parse_str($taskArgs, $_TASK_ARGS);
            call_user_func([$taskConfig['handler'], 'run'], $taskConfig['options'] ?? [], $_TASK_ARGS);
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    private static function getRunType(): string
    {
        if (PHP_SAPI === 'cli') {
            $argv = $_SERVER['argv'][1] ?? '';
            if (str_starts_with($argv, '/')) {
                return 'shell';
            } else {
                return strtolower($argv);
            }
        }
        return 'web';
    }
}