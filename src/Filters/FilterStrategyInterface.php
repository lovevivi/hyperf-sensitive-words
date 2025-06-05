<?php

namespace SensitiveWords\Filters;

interface FilterStrategyInterface
{
    /**
     * 检查词汇是否应该被过滤
     * @param string $word 待检查的词汇
     * @param string $content 原始文本内容
     * @return bool 是否应该被过滤（true=过滤掉，false=保留）
     */
    public function shouldFilter(string $word, string $content): bool;
    
    /**
     * 获取过滤器名称
     * @return string
     */
    public function getName(): string;
} 