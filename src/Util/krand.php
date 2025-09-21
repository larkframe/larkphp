<?php

namespace Lark\Util;

class krand
{
    public static function numberStr(int $length = 10): string
    {
        return self::any($length, '0123456789');
    }

    public static function numberInt(int $min = 0, int $max = 100): int
    {
        try {
            return random_int($min, $max);
        } catch (\Exception $e) {
            return mt_rand($min, $max);
        }
    }

    public static function numberFloat(int $min = 0, int $max = 100, $decimalPlaces = 2): float
    {
        if ($min >= $max) {
            return 0;
        }

        $factor = pow(10, $decimalPlaces);

        $minInt = (int)round($min * $factor);
        $maxInt = (int)round($max * $factor);

        $randomInt = self::numberInt($minInt, $maxInt);

        return number_format($randomInt/$factor, $decimalPlaces,'.','');
    }

    public static function str(int $length = 10): string
    {
        return self::any($length, '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }
    public static function str_easy(int $length = 10): string
    {
        return self::any($length, '23456789abcdefghjklmnpqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ');
    }

    public static function any(int $length = 10, string $characters = ''): string
    {
        if (!$characters) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+-=[]{}|;:,.<>?';
        }

        $charactersLength = strlen($characters);

        // 生成加密安全的随机字节
        $bytes = random_bytes($length);
        $result = '';

        // 将字节转换为字符
        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[ord($bytes[$i]) % $charactersLength];
        }
        return $result;
    }

}