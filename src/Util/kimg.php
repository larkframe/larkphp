<?php
namespace Lark\Util;
class kimg
{
    public static function convertToIco($source_path, $destination_path, $width = 32, $height = 32): bool
    {
        // 检查源文件是否存在
        if (!file_exists($source_path)) {
            return false;
        }

        // 获取图片信息
        $image_info = getimagesize($source_path);
        if (!$image_info) {
            return false;
        }

        // 根据图片类型创建图像资源
        switch ($image_info[2]) {
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($source_path);
                // 特别处理PNG透明度
                imagealphablending($image, false);
                imagesavealpha($image, true);
                break;
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($source_path);
                // 为JPEG创建透明背景
                imagealphablending($image, false);
                imagesavealpha($image, true);
                break;
            default:
                return false; // 不支持的类型
        }

        if (!$image) {
            return false;
        }

        // 创建目标图像并设置透明度
        $resized_image = imagecreatetruecolor($width, $height);
        imagealphablending($resized_image, false);
        imagesavealpha($resized_image, true);

        // 填充透明背景
        $transparent = imagecolorallocatealpha($resized_image, 0, 0, 0, 127);
        imagefill($resized_image, 0, 0, $transparent);

        // 保持宽高比调整大小
        $src_width = imagesx($image);
        $src_height = imagesy($image);

        $ratio = min($width / $src_width, $height / $src_height);
        $new_width = (int)($src_width * $ratio);
        $new_height = (int)($src_height * $ratio);
        $x_pos = (int)(($width - $new_width) / 2);
        $y_pos = (int)(($height - $new_height) / 2);

        // 调整大小时保留透明度和颜色
        imagecopyresampled($resized_image, $image, $x_pos, $y_pos, 0, 0, $new_width, $new_height, $src_width, $src_height);

        // 生成ICO文件
        $ico_data = static::generateIcoData($resized_image, $width, $height);

        // 保存文件
        $result = file_put_contents($destination_path, $ico_data);

        // 释放内存
        imagedestroy($image);
        imagedestroy($resized_image);

        return $result !== false;
    }

    private static function generateIcoData($image, $width, $height): array|string
    {
        // ICO文件头
        $ico = pack('vvv', 0, 1, 1); // 保留字(0)、类型(1=ICO)、图像数量(1)

        // 计算AND掩码（用于ICO透明度）
        $and_stride = (int)(($width + 31) / 32) * 4; // 每行字节数（按4字节对齐）
        $and_size = $and_stride * $height;
        $and_mask = str_repeat("\x00", $and_size); // 创建全0的AND掩码

        // 图像目录条目
        $bpp = 32; // 32位每像素(ARGB)
        $size = 40 + ($width * $height * 4) + $and_size; // 头部(40字节) + 像素数据 + AND掩码
        $ico .= pack('CCCCvvVV',
            $width, $height, 0, 0, // 宽高(0=256px)、调色板(0=无)
            1, $bpp, $size, 0 // 颜色平面(1)、每像素位数、图像大小、文件偏移(稍后填充)
        );

        // BMP信息头
        $header_size = 40;
        $planes = 1;
        $compression = 0;
        $image_size = $width * $height * 4;
        $resolution = 0;

        $bmp_header = pack('VVVvvVVVVVV',
            $header_size, $width, $height * 2, // 高度*2因为BMP存储是倒置的
            $planes, $bpp, $compression, $image_size,
            $resolution, $resolution, 0, 0
        );

        // 像素数据（XOR位图）
        $pixels = '';
        for ($y = $height - 1; $y >= 0; $y--) { // BMP是倒置存储的
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $a = ($color >> 24) & 0xFF;
                $r = ($color >> 16) & 0xFF;
                $g = ($color >> 8) & 0xFF;
                $b = $color & 0xFF;

                // 正确转换透明度：GD的0-127范围转为ICO的0-255范围
                // GD: 0=不透明, 127=全透明 → ICO: 0=全透明, 255=不透明
                $ico_alpha = ($a === 127) ? 0 : (255 - (int)($a * 255 / 127));

                // ICO格式使用BGRA顺序
                $pixels .= pack('CCCC', $b, $g, $r, $ico_alpha);
            }
        }

        // 合并所有部分：文件头 + BMP头 + 像素数据 + AND掩码
        $ico_data = $ico . $bmp_header . $pixels . $and_mask;

        // 更新文件中的偏移量(16+16=32字节后)
        $offset = strlen($ico);
        $ico_data = substr_replace($ico_data, pack('V', $offset), 18, 4);

        return $ico_data;
    }
    
}