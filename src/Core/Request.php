<?php

namespace Lark\Core;

use Exception;
use Lark\Consts;
use Lark\Util\krand;
use RuntimeException;
use Stringable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use function array_walk_recursive;
use function bin2hex;
use function clearstatcache;
use function count;
use function explode;
use function file_put_contents;
use function is_file;
use function json_decode;
use function ltrim;
use function microtime;
use function pack;
use function parse_str;
use function parse_url;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function strstr;
use function strtolower;
use function substr;
use function tempnam;
use function trim;
use function unlink;
use function urlencode;


class Request implements Stringable
{
    /**
     * Connection.
     *
     * @var ?TcpConnection
     */
    public ?TcpConnection $connection = null;

    /**
     * @var int
     */
    public static int $maxFileUploads = 1024;

    /**
     * Maximum string length for cache
     *
     * @var int
     */
    public const MAX_CACHE_STRING_LENGTH = 4096;

    /**
     * Maximum cache size.
     *
     * @var int
     */
    public const MAX_CACHE_SIZE = 256;

    /**
     * Properties.
     *
     * @var array
     */
    public array $properties = [];

    /**
     * Request data.
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Is safe.
     *
     * @var bool
     */
    protected bool $isSafe = true;

    /**
     * Context.
     *
     * @var array
     */
    public array $context = [];

    /**
     * Request constructor.
     *
     */
    public function __construct(protected string $buffer = "")
    {
        if (RUN_TYPE == Consts::RUN_TYPE_WEB || RUN_TYPE == Consts::RUN_TYPE_SHELL) {
            $requestId = strtolower(substr(md5(microtime() . uniqid(gethostname() . '_', true)), 8, 16) . krand::str(16));
            $startTime = microtime(true);
            $_SHELL_PARAMS = $_RAW_PARAMS = [];
            $uri = '';
            if (RUN_TYPE == Consts::RUN_TYPE_SHELL) {
                if (isset($_SERVER['argv'][2])) {
                    parse_str($_SERVER['argv'][2], $_SHELL_PARAMS);
                    $data = [
                        'get' => $_SHELL_PARAMS,
                        'post' => $_SHELL_PARAMS,
                    ];
                    $uri = ROUTE_VALUE . '?' . $_SERVER['argv'][2];
                } else {
                    $uri = ROUTE_VALUE;
                }
            } else {
                $_RAW_DATA = file_get_contents('php://input');
                if ($_RAW_DATA) {
                    $tmpValue = json_decode($_RAW_DATA, true);
                    if (is_array($tmpValue)) {
                        $_RAW_PARAMS = $tmpValue;
                    }
                }
                $uri = $_SERVER['REQUEST_URI'];
            }
            if (!isset($data['get'])) {
                $data = [
                    'get' => $_GET,
                    'post' => array_merge($_POST, $_RAW_PARAMS),
                ];
            }
            $tmpHeader = getallheaders();
            foreach ($tmpHeader as $k => $v) {
                $data['headers'][strtolower($k)] = $v;
            }
            $data['headers']['user-agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $data['cookie'] = $_COOKIE;
            $data['uri'] = $uri;
            $data['requestId'] = $requestId;
            $data['startTime'] = $startTime;
            $this->data = $data;

        }
    }

    public function initRequestIdAndStartTime(): void
    {
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $this->data['requestId'] = strtolower(substr(md5(microtime() . uniqid(gethostname() . '_', true)), 8, 16) . krand::str(16));
            $this->data['startTime'] = microtime(true);
        }
    }

    /**
     * Get query.
     *
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function get(?string $name = null, mixed $default = null): mixed
    {

        if (!isset($this->data['get']) && RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $this->parseGet();
        }
        if (null === $name) {
            return $this->data['get'];
        }
        if (!isset($this->data['get'][$name])) {
            return $default;
        }
        return htmlspecialchars(strip_tags(trim($this->data['get'][$name]))) ?? $default;
    }

    /**
     * Get post.
     *
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function post(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['post']) && RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $this->parsePost();
        }
        if (null === $name) {
            return $this->data['post'];
        }
        if (!isset($this->data['post'][$name])) {
            return $default;
        }
        return htmlspecialchars(strip_tags(trim($this->data['post'][$name]))) ?? $default;
    }

    /**
     * Get header item by name.
     *
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function header(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['headers']) && RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $this->parseHeaders();
        }
        if (null === $name) {
            return $this->data['headers'];
        }
        $name = strtolower($name);
        if (!isset($this->data['headers'][$name])) {
            return $default;
        }
        return $this->data['headers'][$name] ?? $default;
    }

    /**
     * Get cookie item by name.
     *
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function cookie(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['cookie']) && RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $cookies = explode(';', $this->header('cookie', ''));
            $mapped = array();

            foreach ($cookies as $cookie) {
                $cookie = explode('=', $cookie, 2);
                if (count($cookie) !== 2) {
                    continue;
                }
                $mapped[trim($cookie[0])] = $cookie[1];
            }
            $this->data['cookie'] = $mapped;
        }
        if ($name === null) {
            return $this->data['cookie'];
        }
        if (!isset($this->data['cookie'][$name])) {
            return $default;
        }
        return $this->data['cookie'][$name] ?? $default;
    }

    /**
     * Get upload files.
     *
     * @param string|null $name
     * @return array|null
     */
    public function file(?string $name = null): mixed
    {
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            clearstatcache();
            if (!empty($this->data['files'])) {
                array_walk_recursive($this->data['files'], function ($value, $key) {
                    if ($key === 'tmp_name' && !is_file($value)) {
                        $this->data['files'] = [];
                    }
                });
            }
        }
        if (empty($this->data['files'])) {
            $this->parsePost();
        }
        if (null === $name) {
            return $this->data['files'];
        }
        if (!isset($this->data['files'][$name])) {
            return null;
        }
        return $this->data['files'][$name] ?? null;
    }

    /**
     * Get method.
     *
     * @return string
     */
    public function method(): string
    {
        if (!isset($this->data['method']) && RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $this->parseHeadFirstLine();
        }
        return $this->data['method'];
    }

    /**
     * Get http protocol version.
     *
     * @return string
     */
    public function protocolVersion(): string
    {
        if (!isset($this->data['protocolVersion']) && RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $this->parseProtocolVersion();
        }
        return $this->data['protocolVersion'] ?? '';
    }

    /**
     * Get host.
     *
     * @param bool $withoutPort
     * @return string|null
     */
    public function host(bool $withoutPort = false): ?string
    {
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $host = $this->header('host');
            if ($host && $withoutPort) {
                return preg_replace('/:\d{1,5}$/', '', $host);
            }
            return $host;
        } else {
            return getRealHost($withoutPort);
        }
    }


    /**
     * Get uri.
     *
     * @return string
     */
    public function uri(): string
    {
        if (!isset($this->data['uri']) && RUN_TYPE == Consts::RUN_TYPE_SERVER) {
            $this->parseHeadFirstLine();
        }
        return $this->data['uri'];
    }

    public function requestId(): string
    {
        return $this->data['requestId'] ?? '';
    }

    public function usedTime(): float|int
    {
        $usedTime = sprintf("%.6f", (microtime(true) - $this->data['startTime'] ?? 0)) * 1000;
        if ($usedTime < 1) {
            // 防止小数点后数字超出
            $usedTime = intval($usedTime * 1000) / 1000;
        } else {
            $usedTime = intval($usedTime);
        }
        return $usedTime;
    }

    /**
     * Get path.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->data['path'] ??= (string)parse_url($this->uri(), PHP_URL_PATH);
    }

    /**
     * Get query string.
     *
     * @return string
     */
    public function queryString(): string
    {
        return $this->data['query_string'] ??= (string)parse_url($this->uri(), PHP_URL_QUERY);
    }

    /**
     * Get http raw head.
     *
     * @return string
     */
    public function rawHead(): string
    {
        if (RUN_TYPE != Consts::RUN_TYPE_SERVER) {
            return '';
        }
        return $this->data['head'] ??= strstr($this->buffer, "\r\n\r\n", true);
    }

    /**
     * Get http raw body.
     *
     * @return string
     */
    public function rawBody(): string
    {
        if (RUN_TYPE != Consts::RUN_TYPE_SERVER) {
            return '';
        }
        return substr($this->buffer, strpos($this->buffer, "\r\n\r\n") + 4);
    }

    /**
     * Get raw buffer.
     *
     * @return string
     */
    public function rawBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Parse first line of http header buffer.
     *
     * @return void
     */
    protected function parseHeadFirstLine(): void
    {
        if (RUN_TYPE != Consts::RUN_TYPE_SERVER) {
            return;
        }
        $firstLine = strstr($this->buffer, "\r\n", true);
        $tmp = explode(' ', $firstLine, 3);
        $this->data['method'] = $tmp[0];
        $this->data['uri'] = $tmp[1] ?? '/';
    }

    /**
     * Parse protocol version.
     *
     * @return void
     */
    protected function parseProtocolVersion(): void
    {
        $firstLine = strstr($this->buffer, "\r\n", true);
        $httpStr = strstr($firstLine, 'HTTP/');
        $protocolVersion = $httpStr ? substr($httpStr, 5) : '1.0';
        $this->data['protocolVersion'] = $protocolVersion;
    }

    /**
     * Parse headers.
     *
     * @return void
     */
    protected function parseHeaders(): void
    {
        static $cache = [];
        $this->data['headers'] = [];
        $rawHead = $this->rawHead();
        $endLinePosition = strpos($rawHead, "\r\n");
        if ($endLinePosition === false) {
            return;
        }
        $headBuffer = substr($rawHead, $endLinePosition + 2);
        $cacheable = !isset($headBuffer[static::MAX_CACHE_STRING_LENGTH]);
        if ($cacheable && isset($cache[$headBuffer])) {
            $this->data['headers'] = $cache[$headBuffer];
            return;
        }
        $headData = explode("\r\n", $headBuffer);
        foreach ($headData as $content) {
            if (str_contains($content, ':')) {
                [$key, $value] = explode(':', $content, 2);
                $key = strtolower($key);
                $value = ltrim($value);
            } else {
                $key = strtolower($content);
                $value = '';
            }
            if (isset($this->data['headers'][$key])) {
                $this->data['headers'][$key] = "{$this->data['headers'][$key]},$value";
            } else {
                $this->data['headers'][$key] = $value;
            }
        }
        if ($cacheable) {
            $cache[$headBuffer] = $this->data['headers'];
            if (count($cache) > static::MAX_CACHE_SIZE) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse head.
     *
     * @return void
     */
    protected function parseGet(): void
    {
        static $cache = [];
        $queryString = $this->queryString();
        $this->data['get'] = [];
        if ($queryString === '') {
            return;
        }
        $cacheable = !isset($queryString[static::MAX_CACHE_STRING_LENGTH]);
        if ($cacheable && isset($cache[$queryString])) {
            $this->data['get'] = $cache[$queryString];
            return;
        }
        parse_str($queryString, $this->data['get']);
        if ($cacheable) {
            $cache[$queryString] = $this->data['get'];
            if (count($cache) > static::MAX_CACHE_SIZE) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse post.
     *
     * @return void
     */
    protected function parsePost(): void
    {
        static $cache = [];
        $this->data['post'] = $this->data['files'] = [];
        $contentType = $this->header('content-type', '');
        if (preg_match('/boundary="?(\S+)"?/', $contentType, $match)) {
            $httpPostBoundary = '--' . $match[1];
            $this->parseUploadFiles($httpPostBoundary);
            return;
        }
        $bodyBuffer = $this->rawBody();
        if ($bodyBuffer === '') {
            return;
        }
        $cacheable = !isset($bodyBuffer[static::MAX_CACHE_STRING_LENGTH]);
        if ($cacheable && isset($cache[$bodyBuffer])) {
            $this->data['post'] = $cache[$bodyBuffer];
            return;
        }
        if (preg_match('/\bjson\b/i', $contentType)) {
            $this->data['post'] = (array)json_decode($bodyBuffer, true);
        } else {
            parse_str($bodyBuffer, $this->data['post']);
        }
        if ($cacheable) {
            $cache[$bodyBuffer] = $this->data['post'];
            if (count($cache) > static::MAX_CACHE_SIZE) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse upload files.
     *
     * @param string $httpPostBoundary
     * @return void
     */
    protected function parseUploadFiles(string $httpPostBoundary): void
    {
        $httpPostBoundary = trim($httpPostBoundary, '"');
        $buffer = $this->buffer;
        $postEncodeString = '';
        $filesEncodeString = '';
        $files = [];
        $bodyPosition = strpos($buffer, "\r\n\r\n") + 4;
        $offset = $bodyPosition + strlen($httpPostBoundary) + 2;
        $maxCount = static::$maxFileUploads;
        while ($maxCount-- > 0 && $offset) {
            $offset = $this->parseUploadFile($httpPostBoundary, $offset, $postEncodeString, $filesEncodeString, $files);
        }
        if ($postEncodeString) {
            parse_str($postEncodeString, $this->data['post']);
        }

        if ($filesEncodeString) {
            parse_str($filesEncodeString, $this->data['files']);
            array_walk_recursive($this->data['files'], function (&$value) use ($files) {
                $value = $files[$value];
            });
        }
    }

    /**
     * Parse upload file.
     *
     * @param string $boundary
     * @param int $sectionStartOffset
     * @param string $postEncodeString
     * @param string $filesEncodeStr
     * @param array $files
     * @return int
     */
    protected function parseUploadFile(string $boundary, int $sectionStartOffset, string &$postEncodeString, string &$filesEncodeStr, array &$files): int
    {
        $file = [];
        $boundary = "\r\n$boundary";
        if (strlen($this->buffer) < $sectionStartOffset) {
            return 0;
        }
        $sectionEndOffset = strpos($this->buffer, $boundary, $sectionStartOffset);
        if (!$sectionEndOffset) {
            return 0;
        }
        $contentLinesEndOffset = strpos($this->buffer, "\r\n\r\n", $sectionStartOffset);
        if (!$contentLinesEndOffset || $contentLinesEndOffset + 4 > $sectionEndOffset) {
            return 0;
        }
        $contentLinesStr = substr($this->buffer, $sectionStartOffset, $contentLinesEndOffset - $sectionStartOffset);
        $contentLines = explode("\r\n", trim($contentLinesStr . "\r\n"));
        $boundaryValue = substr($this->buffer, $contentLinesEndOffset + 4, $sectionEndOffset - $contentLinesEndOffset - 4);
        $uploadKey = false;
        foreach ($contentLines as $contentLine) {
            if (!strpos($contentLine, ': ')) {
                return 0;
            }
            [$key, $value] = explode(': ', $contentLine);
            switch (strtolower($key)) {

                case "content-disposition":
                    // Is file data.
                    if (preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match)) {
                        $error = 0;
                        $tmpFile = '';
                        $fileName = $match[1];
                        $size = strlen($boundaryValue);
                        $tmpUploadDir = HTTP::uploadTmpDir();
                        if (!$tmpUploadDir) {
                            $error = UPLOAD_ERR_NO_TMP_DIR;
                        } else if ($boundaryValue === '' && $fileName === '') {
                            $error = UPLOAD_ERR_NO_FILE;
                        } else {
                            $tmpFile = tempnam($tmpUploadDir, 'workerman.upload.');
                            if ($tmpFile === false || false === file_put_contents($tmpFile, $boundaryValue)) {
                                $error = UPLOAD_ERR_CANT_WRITE;
                            }
                        }
                        $uploadKey = $fileName;
                        // Parse upload files.
                        $file = [...$file, 'name' => $match[2], 'tmp_name' => $tmpFile, 'size' => $size, 'error' => $error, 'full_path' => $match[2]];
                        $file['type'] ??= '';
                        break;
                    }
                    // Is post field.
                    // Parse $POST.
                    if (preg_match('/name="(.*?)"$/', $value, $match)) {
                        $k = $match[1];
                        $postEncodeString .= urlencode($k) . "=" . urlencode($boundaryValue) . '&';
                    }
                    return $sectionEndOffset + strlen($boundary) + 2;

                case "content-type":
                    $file['type'] = trim($value);
                    break;

                case "webkitrelativepath":
                    $file['full_path'] = trim($value);
                    break;
            }
        }
        if ($uploadKey === false) {
            return 0;
        }
        $filesEncodeStr .= urlencode($uploadKey) . '=' . count($files) . '&';
        $files[] = $file;

        return $sectionEndOffset + strlen($boundary) + 2;
    }

    /**
     * __toString.
     */
    public function __toString(): string
    {
        return $this->buffer;
    }

    /**
     * Setter.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->properties[$name] = $value;
    }

    /**
     * Getter.
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Isset.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    /**
     * Unset.
     *
     * @param string $name
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->properties[$name]);
    }

    /**
     * __wakeup.
     *
     * @return void
     */
    public function __wakeup(): void
    {
        $this->isSafe = false;
    }

    /**
     * Destroy.
     *
     * @return void
     */
    public function destroy(): void
    {
        if ($this->context) {
            $this->context = [];
        }
        if ($this->properties) {
            $this->properties = [];
        }
        if (isset($this->data['files']) && $this->isSafe) {
            clearstatcache();
            array_walk_recursive($this->data['files'], function ($value, $key) {
                if ($key === 'tmp_name' && is_file($value)) {
                    unlink($value);
                }
            });
        }
    }

}
