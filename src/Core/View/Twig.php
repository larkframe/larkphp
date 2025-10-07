<?php


namespace Lark\Core\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use function array_merge;
use function config;
use function is_array;
use function request;

class Twig implements View
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
     * @param string|null $viewSuffix
     * @return string
     */
    public static function render(string $template, array $vars, ?string $viewSuffix = null): string
    {
        static $views = [];
        $request = request();
        if ($viewSuffix == null) {
            $viewSuffix = config("view.options.view_suffix", 'html');
        }
        $viewPath = ROOT_PATH . DIRECTORY_SEPARATOR . "template" . DIRECTORY_SEPARATOR;
        if (!file_exists($viewPath . $template . "." . $viewSuffix)) {
            return "template " . $template . "." . $viewSuffix . " not found";
        }
        if (!isset($views[$viewPath])) {
            $views[$viewPath] = new Environment(new FilesystemLoader($viewPath), config("view.options", []));
            $extension = config("view.extension");
            if ($extension) {
                $extension($views[$viewPath]);
            }
        }
        if (isset($request->_view_vars)) {
            $vars = array_merge((array)$request->_view_vars, $vars);
        }
        return $views[$viewPath]->render("$template.$viewSuffix", $vars);
    }
}
