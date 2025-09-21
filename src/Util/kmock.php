<?php

namespace Lark\Util;

class kmock
{

    /**
     * 生成模拟数据
     * 支持@id、@datetime、@timestamp、@int等指令，例如：
     * list([
     *   'id' => '@id',
     *   'title' => 'xxxxxx@id',
     *   'status|1' => ['published', 'draft', 'deleted'], // |1表示随机选择1条
     *   'author' => 'name',
     *   'display_time' => '@datetime',
     *   'pageviews' => '@int(300, 5000)',
     *  ]
     *
     * @param $itemTemplate
     * @param array $keys
     * @param int $count
     * @return array
     */
    public static function list($itemTemplate, int $count): array
    {
        $mockList = [];
        for ($i = 1; $i <= $count; $i++) {
            $mockItem = [];
            foreach ($itemTemplate as $key => $item) {
                // NOTICE:mock纯逻辑处理且用于非线上环境,忽略部分函数重复调用带来的性能影响
                if (str_contains($key, '|')) {
                    list($k, $c) = explode('|', $key);
                    $c = intval($c) == 0 ? 1 : intval($c);
                    $c = min($c, count($item));
                    $mockItem[$k] = $c !== 1 ? array_rand(array_flip($item), $c) : $item[array_rand($item, $c)];
                } else {
                    if (str_contains($item, '@id')) {
                        $item = str_replace('@id', '', $item);
                        $mockItem[$key] = $item . $i;
                    } else if (str_contains($item, '@datetime')) {
                        $mockItem[$key] = date('Y-m-d H:i:s');
                    } else if (str_contains($item, '@timestamp')) {
                        $mockItem[$key] = time();
                    } else if (str_contains($item, '@int')) {
                        list($p, $r) = explode('@int', $item);
                        list($intStart, $intEnd) = explode(',', str_replace(array('(', ')'), '', $r));
                        $mockItem[$key] = $p . mt_rand(intval($intStart), intval(trim($intEnd)));
                    } else {
                        $mockItem[$key] = $item;
                    }
                }
            }
            $mockList[] = $mockItem;
        }
        return $mockList;
    }
}