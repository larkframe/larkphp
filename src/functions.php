<?php

use Lark\Config;
use Lark\Consts;
use Lark\Core\App;
use Lark\Core\View\Raw;
use Lark\Core\View\Twig;
use Lark\Response;


if (!function_exists('is_phar')) {
    /**
     * Is phar
     * @return bool
     */
    function is_phar(): bool
    {
        return class_exists(Phar::class, false) && Phar::running();
    }
}
if (!function_exists('request')) {
    /**
     * Get request
     * @return Lark\Request|null
     */
    function request()
    {
        return App::request();
    }
}

if (!function_exists('config')) {
    /**
     * Get config
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    function config(?string $key = null, mixed $default = null)
    {
        return Config::get($key, $default);
    }
}


if (!function_exists('xml')) {
    /**
     * Xml response
     * @param $xml
     * @return Response
     */
    function xml($xml): Response
    {
        if ($xml instanceof SimpleXMLElement) {
            $xml = $xml->asXML();
        }
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            return new Response(200, ['Content-Type' => 'text/xml'], $xml);
        } else {
            return $xml;
        }
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect response
     * @param string $location
     * @param int $status
     * @param array $headers
     * @return Response
     */
    function redirect(string $location, int $status = 302, array $headers = []): Response
    {
        $response = new Response($status, ['Location' => $location]);
        if (!empty($headers)) {
            $response->withHeaders($headers);
        }
        return $response;
    }
}

if (!function_exists('view')) {
    /**
     * View response
     * @param mixed $template
     * @param array $vars
     * @param string|null $app
     * @param string|null $plugin
     * @return Response
     */
    function view(mixed $template = null, array $vars = [], ?string $app = null): Response|string
    {
        $handler = \config('view.handler');
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            return new Response(200, [], $handler::render($template, $vars, $app));
        } else {
            return $handler::render($template, $vars, $app);
        }
    }
}

if (!function_exists('raw_view')) {
    /**
     * Raw view response
     * @param mixed $template
     * @param array $vars
     * @param string|null $app
     * @param string|null $plugin
     * @return Response
     * @throws Throwable
     */
    function raw_view(mixed $template = null, array $vars = [], ?string $app = null): Response|string
    {
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            return new Response(200, [], Raw::render($template, $vars, $app));
        } else {
            return Raw::render($template, $vars, $app);
        }
    }
}

if (!function_exists('twig_view')) {
    /**
     * Twig view response
     * @param mixed $template
     * @param array $vars
     * @param string|null $app
     * @param string|null $plugin
     * @return Response
     */
    function twig_view(mixed $template = null, array $vars = [], ?string $app = null): Response|string
    {
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            return new Response(200, [], Twig::render($template, $vars, $app));
        } else {
            return Twig::render($template, $vars, $app);
        }
    }
}

if (!function_exists('cpu_count')) {
    /**
     * Get cpu count
     * @return int
     */
    function cpu_count(): int
    {
        // Windows does not support the number of processes setting.
        if (DIRECTORY_SEPARATOR === '\\') {
            return 1;
        }
        $count = 4;
        if (is_callable('shell_exec')) {
            if (strtolower(PHP_OS) === 'darwin') {
                $count = (int)shell_exec('sysctl -n machdep.cpu.core_count');
            } else {
                try {
                    $count = (int)shell_exec('nproc');
                } catch (\Throwable $ex) {
                    // Do nothing
                }
            }
        }
        return $count > 0 ? $count : 4;
    }
}

if (!function_exists('input')) {
    /**
     * Get request parameters, if no parameter name is passed, an array of all values is returned, default values is supported
     * @param string|null $param param's name
     * @param mixed $default default value
     * @return mixed
     */
    function input(?string $param = null, mixed $default = null): mixed
    {
        return is_null($param) ? request()->all() : request()->input($param, $default);
    }
}

/**
 * Get the base path of the application
 */
if (!defined('ROOT_PATH')) {
    if (!$rootPath = Phar::running()) {
        $rootPath = getcwd();
        while ($rootPath !== dirname($rootPath)) {
            if (@is_dir("$rootPath/vendor") && @is_file("$rootPath/start.php")) {
                break;
            }
            $rootPath = dirname($rootPath);
        }
        if ($rootPath === dirname($rootPath)) {
            $rootPath = __DIR__ . '/../../../../';
        }
    }
    define('ROOT_PATH', realpath($rootPath) ?: $rootPath);
}

if (!function_exists('run_path')) {
    /**
     * return the program execute directory
     * @param string $path
     * @return string
     */
    function run_path(string $path = ''): string
    {
        static $runPath = '';
        if (!$runPath) {
            $runPath = is_phar() ? dirname(Phar::running(false)) : ROOT_PATH;
        }
        return path_combine($runPath, $path);
    }
}


if (!function_exists('config_path')) {
    /**
     * Runtime path
     * @param string $path
     * @return string
     */
    function config_path(): string
    {
        static $configPath = '';
        if (!$configPath) {
            $configPath = run_path('config');
        }
        return $configPath;
    }
}

if (!function_exists('runtime_path')) {
    /**
     * Runtime path
     * @param string $path
     * @return string
     */
    function runtime_path(string $path = ''): string
    {
        static $runtimePath = '';
        if (!$runtimePath) {
            $runtimePath = \config('app.runtime_path') ?: run_path('runtime');
        }
        return path_combine($runtimePath, $path);
    }
}

if (!function_exists('path_combine')) {
    /**
     * Generate paths based on given information
     * @param string $front
     * @param string $back
     * @return string
     */
    function path_combine(string $front, string $back): string
    {
        return $front . ($back ? (DIRECTORY_SEPARATOR . ltrim($back, DIRECTORY_SEPARATOR)) : $back);
    }
}

if (!function_exists('response')) {
    /**
     * Response
     * @param int $status
     * @param array $headers
     * @param string $body
     * @return Response
     */
    function response(string $body = '', int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers, $body);
    }
}

if (!function_exists('json')) {
    /**
     * Json response
     * @param $data
     * @param int $options
     * @return Response|string|bool
     */
    function json($data, int $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR): Response|string|bool
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, $options));
    }
}

if (!function_exists('xml')) {
    /**
     * Xml response
     * @param $xml
     * @return Response
     */
    function xml($xml): Response
    {
        if ($xml instanceof SimpleXMLElement) {
            $xml = $xml->asXML();
        }
        return new Response(200, ['Content-Type' => 'text/xml'], $xml);
    }
}

if (!function_exists('jsonp')) {
    /**
     * Jsonp response
     * @param $data
     * @param string $callbackName
     * @return Response
     */
    function jsonp($data, string $callbackName = 'callback'): Response
    {
        if (!is_scalar($data) && null !== $data) {
            $data = json_encode($data);
        }
        return new Response(200, [], "$callbackName($data)");
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect response
     * @param string $location
     * @param int $status
     * @param array $headers
     * @return Response
     */
    function redirect(string $location, int $status = 302, array $headers = []): Response
    {
        $response = new Response($status, ['Location' => $location]);
        if (!empty($headers)) {
            $response->withHeaders($headers);
        }
        return $response;
    }
}

if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower($key))));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
}

function getRealHost()
{
    // 检查常见的代理头
    $possibleHeaders = [
        'HTTP_X_FORWARDED_HOST',
        'HTTP_X_FORWARDED_SERVER',
        'HTTP_HOST',
        'SERVER_NAME',
        'SERVER_ADDR'
    ];

    foreach ($possibleHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $host = $_SERVER[$header];

            // 处理逗号分隔的多个值（如 X-Forwarded-Host: example.com,proxy.com）
            if (str_contains($host, ',')) {
                $hosts = explode(',', $host);
                $host = trim(end($hosts)); // 取最后一个
            }

            // 移除端口号（可选）
            $host = strtok($host, ':');

            return $host;
        }
    }

    return 'unknown'; // 默认值
}

function getClientIp()
{
    $ip = "";
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(", ", $_SERVER['HTTP_X_FORWARDED_FOR']);
        if ($ip) {
            array_unshift($ips, $ip);
            $ip = FALSE;
        }
        for ($i = 0; $i < count($ips); $i++) {
            if (!preg_match("~^(10│172│192.168)\.~", $ips[$i])) {
                $ip = $ips[$i];
                break;
            }
        }
    }
    if (!$ip && isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    if (!$ip) {
        $ip = '127.0.0.1';
    }
    return $ip;
}