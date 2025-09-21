<?php

namespace Lark\Core\View;

use Throwable;
use function array_merge;
use function config;
use function extract;
use function is_array;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function request;

class Raw implements View
{
    /**
     * Assign.
     * @param string|array $name
     * @param mixed $value
     */
    public static function assign(string|array $name, mixed $value = null): void
    {
        $request = request();
        $request->_view_vars = array_merge((array)$request->_view_vars, is_array($name) ? $name : [$name => $value]);
    }

    /**
     * Render.
     * @param string $template
     * @param array $vars
     * @param string|null $app
     * @return string
     */
    public static function render(string $template, array $vars, ?string $app = null): string
    {
        $request = request();
        $viewSuffix = config("view.options.view_suffix", 'html');
        $__template_path__ = ROOT_PATH . DIRECTORY_SEPARATOR . 'template' . $template . '.' . $viewSuffix;
        if (!file_exists($__template_path__)) {
            return "template" . $template .".". $viewSuffix . " not found";
        }
        if (isset($request->_view_vars)) {
            extract((array)$request->_view_vars);
        }
        extract($vars);
        ob_start();
        // Try to include php file.
        try {
            include $__template_path__;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean();
    }
}
