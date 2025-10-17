<?php
declare(strict_types=1);

namespace Lark;

use Exception;
use FastRoute\Dispatcher;
use Lark\Core\Context;
use Lark\Core\Database\Initializer;
use Lark\Core\Middleware;
use Lark\Util\krand;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http;
use Workerman\Worker;
use Workerman\Protocols\Http\Session as SessionBase;

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

        $listen = $config['socketName'] ?? '127.0.0.1:8088';
        $worker = new Worker($listen, []);
        $config = config('server');
        $worker->name = config('app.name', 'server');
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

            $sessionConfig = config('session');
            if ($sessionConfig['enabled'] ?? false) {
                if (property_exists(SessionBase::class, 'name')) {
                    SessionBase::$name = $sessionConfig['session_name'];
                } else {
                    Http::sessionName($sessionConfig['session_name']);
                }
                SessionBase::handlerClass($sessionConfig['handler'], $sessionConfig['config'][$sessionConfig['type']]);
                $map = [
                    'auto_update_timestamp' => 'autoUpdateTimestamp',
                    'cookie_lifetime' => 'cookieLifetime',
                    'gc_probability' => 'gcProbability',
                    'cookie_path' => 'cookiePath',
                    'http_only' => 'httpOnly',
                    'same_site' => 'sameSite',
                    'lifetime' => 'lifetime',
                    'domain' => 'domain',
                    'secure' => 'secure',
                ];

                foreach ($map as $key => $name) {
                    if (isset($sessionConfig[$key]) && property_exists(SessionBase::class, $name)) {
                        SessionBase::${$name} = $sessionConfig[$key];
                    }
                }
            }

            $app = new Core\App(Request::class, Log::channel($accessLogName));
            $worker->onMessage = [$app, 'onMessage'];
            call_user_func([$app, 'onWorkerStart'], $worker);
        };

        Worker::runAll();
    }

    private static function runAsNormal()
    {
        if (in_array(RUN_TYPE, [Consts::RUN_TYPE_SHELL, Consts::RUN_TYPE_WEB])) {
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

            if (config('error.catch', false)) {
                $errorHandler = config("error.handler", null);
                if ($errorHandler !== null && !str_contains(ROUTE_VALUE, '.')) {
                    if (method_exists($errorHandler, "register")) {
                        $errorHandlerOption = config("error.options", []);
                        call_user_func([$errorHandler, 'register'], $errorHandlerOption);
                    }
                }
            }

            Route::load();
            $request = new Request();
            Context::set(Request::class, $request);

            $sessionConfig = config('session');
            if ($sessionConfig['enabled'] ?? false) {
                session_start([
                    'save_handler' => 'files',
                    'save_path' => $sessionConfig['config']['file']['save_path'] ?? '',
                    'name' => $sessionConfig['session_name'] ?? 'PHPSESSID',
                    'cookie_lifetime' => $sessionConfig['cookie_lifetime'] ?? 0,
                    'cookie_path' => $sessionConfig['cookie_path'] ?? '/',
                    'cookie_domain' => $sessionConfig['domain'] ?? '',
                    'cookie_secure' => $sessionConfig['secure'] ?? false,
                    'cookie_httponly' => $sessionConfig['http_only'] ?? false,
                    'cookie_samesite' => $sessionConfig['same_site'] ?? '',
                    'gc_maxlifetime' => $sessionConfig['lifetime'] ?? 1440,
                    'gc_probability' => krand::numberInt($sessionConfig['gc_probability'][0], $sessionConfig['gc_probability'][1]) ?? 1,
                ]);
            }

            $routeInfo = Route::dispatch($method, $route);
            if ($routeInfo[0] === Dispatcher::FOUND) {
                $routeInfo[0] = 'route';
                $callback = $routeInfo[1]['callback'];
                $args = !empty($routeInfo[2]) ? $routeInfo[2] : [];
                $anonymousArgs = [];
                if ($args) {
                    $anonymousArgs = array_values($args);
                }
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
                if (!empty($anonymousArgs)) {
                    $result = $call($request, ...$anonymousArgs);
                } else {
                    $result = $call($request);
                }

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
            echo $taskConfig['handler'] . " not useable\n";
            return;
        }

        $call = new \ReflectionMethod($taskConfig['handler'], 'run');
        if (!$call->isStatic()) {
            echo $taskConfig['handler'] . ".run not statically\n";
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