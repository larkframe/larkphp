<?php

namespace Lark\Util;

class kstr
{
    public static function camelToUnderscore(string $input): string
    {
        $input = lcfirst($input);

        // 在大写字母前添加下划线（处理数字后的大写字母）
        $output = preg_replace_callback(
            '/(?<=\w)(?=[A-Z])|(?<=[a-z])(?=[A-Z])|(?<=\d)(?=[A-Za-z])/',
            function ($matches) {
                return '_';
            },
            $input
        );

        return strtolower($output);
    }
}