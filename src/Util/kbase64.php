<?php

namespace Lark\Util;

class kbase64
{
    public static function encode(string $data): string {
        return base64_encode($data);
    }
    public static function decode(string $data): string {
        return base64_decode($data);
    }

    /**
     *
     * @param string $string 要加密/解密的字符串
     * @param string $operation 操作类型 'ENCODE' 或 'DECODE'
     * @param string $key 加密密钥
     * @param int $expiry 密文有效期(秒)，0为永久有效
     * @return string|false 处理后的字符串或失败时返回false
     */
    public static function authcode(string $string, string $operation = 'ENCODE', string $key = '', int $expiry = 0): string|false {
        // 验证操作类型
        if ($operation !== 'ENCODE' && $operation !== 'DECODE') {
            return false;
        }

        if ($key === '') {
            $key = 'b03aae926e9d664b'; // 默认密钥
        }

        // 固定盐值长度
        $salt_length = 16;

        // 密钥派生
        $key = md5($key);
        $keya = md5(substr($key, 0, 16));
        $keyb = md5(substr($key, 16, 16));

        if ($operation === 'ENCODE') {
            // 生成随机盐值
            try {
                $salt = random_bytes($salt_length);
            } catch (\Exception $e) {
                $salt = krand::str($salt_length);
            }

            // 准备数据：过期时间 + 数据
            $expiry_time = $expiry ? time() + $expiry : 0;
            $data = pack('N', $expiry_time) . substr(md5($string . $keyb), 0, 16) . $string;

            // 使用流加密
            $encrypted = static::rc4_crypt($data, $keya . md5($keya . $salt));

            // 组合并使用Base64URL编码
            return static::base64url_encode($salt . $encrypted);
        }

        // DECODE操作
        $data = static::base64url_decode($string);
        if ($data === false || strlen($data) < $salt_length) {
            return false;
        }

        // 提取盐值和加密数据
        $salt = substr($data, 0, $salt_length);
        $encrypted = substr($data, $salt_length);

        // 解密数据
        $decrypted = static::rc4_crypt($encrypted, $keya . md5($keya . $salt));

        // 验证数据完整性
        if (strlen($decrypted) < 20) {
            return false;
        }

        // 提取过期时间和验证码
        $expiry_time = unpack('N', substr($decrypted, 0, 4))[1];
        $md5_check = substr($decrypted, 4, 16);
        $result = substr($decrypted, 20);

        // 验证MD5
        if (substr(md5($result . $keyb), 0, 16) !== $md5_check) {
            return false;
        }

        // 检查是否过期
        if ($expiry_time > 0 && $expiry_time < time()) {
            return false;
        }

        return $result;
    }

    /**
     * RC4流加密算法实现
     * 使用S-box改进安全性
     */
    private static function rc4_crypt(string $data, string $key): string {
        $s = [];
        $out = '';
        $key_length = strlen($key);
        $data_length = strlen($data);

        // 初始化S-box
        for ($i = 0; $i < 256; $i++) {
            $s[$i] = $i;
        }

        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $key_length])) % 256;
            // 交换值
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }

        $i = $j = 0;
        for ($k = 0; $k < $data_length; $k++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            // 交换值
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            // 使用异或运算加解密
            $out .= $data[$k] ^ chr($s[($s[$i] + $s[$j]) % 256]);
        }

        return $out;
    }

    /**
     * Base64URL 编码 - 避免特殊字符
     * 将 + 替换为 -，/ 替换为 _，并移除末尾的 =
     */
    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL 解码
     * 将 - 替换为 +，_ 替换为 /，并添加必要的 =
     */
    private static function base64url_decode(string $data): string|false {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'));
        return $decoded !== false ? $decoded : false;
    }
}