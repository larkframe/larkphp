<?php

namespace Lark;

use Throwable;
use function filemtime;
use function gmdate;

class Response extends Core\Response
{
    /**
     * @var Throwable
     */
    protected $exception = null;

    /**
     * File
     * @param string $file
     * @return $this
     */
    public function file(string $file): Response
    {
        if (RUN_TYPE == Consts::RUN_TYPE_SERVER && $this->notModifiedSince($file)) {
            return $this->withStatus(304);
        }
        return $this->withFile($file);
    }

    /**
     * Download
     * @param string $file
     * @param string $downloadName
     * @return $this
     */
    public function download(string $file, string $downloadName = ''): Response
    {
        $this->withFile($file);
        if ($downloadName) {
            $this->header('Content-Disposition', "attachment; filename=\"$downloadName\"");
        }
        return $this;
    }

    /**
     * NotModifiedSince
     * @param string $file
     * @return bool
     */
    protected function notModifiedSince(string $file): bool
    {
        $ifModifiedSince = \Lark\Core\App::request()->header('if-modified-since');
        if ($ifModifiedSince === null || !is_file($file) || !($mtime = filemtime($file))) {
            return false;
        }
        return $ifModifiedSince === gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    }

    /**
     * Exception
     * @param Throwable|null $exception
     * @return Throwable|null
     */
    public function exception(?Throwable $exception = null): ?Throwable
    {
        if ($exception) {
            $this->exception = $exception;
        }
        return $this->exception;
    }
}
