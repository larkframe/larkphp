<?php

namespace Lark;

use function config;

class View
{
    public static function assign($name, mixed $value = null)
    {
        $handler = config('view.handler');
        $handler::assign($name, $value);
    }
}