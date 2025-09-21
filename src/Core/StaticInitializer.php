<?php

namespace Lark\Core;

trait StaticInitializer
{
    protected static $initialized = false;
    protected static function initialize() {
        if (!static::$initialized) {
            if (method_exists(static::class,'doInitialize')) {
                static::doInitialize();
                static::$initialized = true;
            }
        }
    }
}