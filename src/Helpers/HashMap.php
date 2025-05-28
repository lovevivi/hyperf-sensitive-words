<?php

declare(strict_types=1);

namespace SensitiveWords\Helpers;

class HashMap
{
    /**
     * 哈希表变量
     *
     * @var array
     */
    protected $hashTable = array();

    public function __construct(){}

    /**
     * 向HashMap中添加一个键值对。
     *
     * @param mixed $key
     * @param mixed $value
     */
    public function put($key, $value)
    {
        $this->hashTable[$key] = $value;
    }

    /**
     * 根据key获取对应的value
     *
     * @param mixed $key
     * @return mixed|null
     */
    public function get($key)
    {
        return $this->hashTable[$key] ?? null;
    }

    /**
     * 删除指定key的键值对
     *
     * @param mixed $key
     * @return mixed|null 返回被删除的值，如果键不存在则返回null。
     */
    public function remove($key)
    {
        if (array_key_exists($key, $this->hashTable)) {
            $tempValue = $this->hashTable[$key];
            unset($this->hashTable[$key]);
            return $tempValue;
        }
        return null;
    }

    /**
     * 获取HashMap的所有键值
     *
     * @return array
     */
    public function keys()
    {
        return array_keys($this->hashTable);
    }

    /**
     * 获取HashMap的所有value值
     *
     * @return array
     */
    public function values()
    {
        return array_values($this->hashTable);
    }

    /**
     * 将一个HashMap的值全部put到当前HashMap中
     *
     * @param \SensitiveWords\Helpers\HashMap $map
     */
    public function putAll($map)
    {
        if (! $map->isEmpty() && $map->size() > 0) {
            $keys = $map->keys();
            foreach ($keys as $key) {
                $this->put($key, $map->get($key));
            }
        }
    }

    /**
     * 移除HashMap中所有元素
     *
     * @return bool
     */
    public function removeAll()
    {
        $this->hashTable = array(); // 重置为空数组
        return true;
    }

    /**
     * 判断HashMap中是否包含指定的值
     *
     * @param mixed $value
     * @return bool
     */
    public function containsValue($value)
    {
        return in_array($value, $this->hashTable);
    }

    /**
     * 判断HashMap中是否包含指定的键key
     *
     * @param mixed $key
     * @return bool
     */
    public function containsKey($key)
    {
        if (array_key_exists($key, $this->hashTable)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取HashMap中元素个数
     *
     * @return int
     */
    public function size()
    {
        return count($this->hashTable);
    }

    /**
     * 判断HashMap是否为空
     *
     * @return bool
     */
    public function isEmpty()
    {
        return (count($this->hashTable) == 0);
    }
} 