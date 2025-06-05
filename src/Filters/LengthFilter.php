<?php

namespace SensitiveWords\Filters;

class LengthFilter implements FilterStrategyInterface
{
    public function shouldFilter(string $word, string $content): bool
    {
        $wordLen = mb_strlen($word, 'utf-8');
        
        // 极短词汇（1-2字符）需要更严格的检查
        if ($wordLen <= 2) {
            // 检查是否为纯英文短词且在中文语境中
            if (preg_match('/^[a-zA-Z]+$/', $word)) {
                // 如果是英文词汇在中文语境中，且周围有中文字符，则可能是误检
                $position = mb_strpos($content, $word, 0, 'utf-8');
                if ($position !== false) {
                    $contentLen = mb_strlen($content, 'utf-8');
                    
                    // 检查前后是否有中文字符
                    $hasChineseBefore = false;
                    $hasChineseAfter = false;
                    
                    // 检查前5个字符
                    for ($i = max(0, $position - 5); $i < $position; $i++) {
                        $char = mb_substr($content, $i, 1, 'utf-8');
                        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $char)) {
                            $hasChineseBefore = true;
                            break;
                        }
                    }
                    
                    // 检查后5个字符
                    for ($i = $position + $wordLen; $i < min($contentLen, $position + $wordLen + 5); $i++) {
                        $char = mb_substr($content, $i, 1, 'utf-8');
                        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $char)) {
                            $hasChineseAfter = true;
                            break;
                        }
                    }
                    
                    // 如果英文短词被中文字符包围，很可能是误检
                    if ($hasChineseBefore && $hasChineseAfter) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    public function getName(): string
    {
        return 'length_filter';
    }
} 