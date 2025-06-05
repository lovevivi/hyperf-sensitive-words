<?php

namespace SensitiveWords\Filters;

class BoundaryFilter implements FilterStrategyInterface
{
    public function shouldFilter(string $word, string $content): bool
    {
        // 对于单字敏感词，进行边界检测
        if (mb_strlen($word, 'utf-8') === 1) {
            return !$this->isSingleCharIndependent($word, $content);
        }
        
        return false;
    }
    
    public function getName(): string
    {
        return 'boundary_filter';
    }
    
    /**
     * 检查单字敏感词是否独立出现（不是其他词汇的一部分）
     * @param string $singleChar 单字敏感词
     * @param string $content 原始文本内容
     * @return bool 是否独立出现
     */
    private function isSingleCharIndependent(string $singleChar, string $content): bool
    {
        $contentLen = mb_strlen($content, 'utf-8');
        $charPositions = [];
        
        // 找到所有该字符的位置
        for ($i = 0; $i < $contentLen; $i++) {
            if (mb_substr($content, $i, 1, 'utf-8') === $singleChar) {
                $charPositions[] = $i;
            }
        }
        
        // 检查每个位置是否独立出现
        foreach ($charPositions as $pos) {
            $isIndependent = true;
            
            // 检查前一个字符
            if ($pos > 0) {
                $prevChar = mb_substr($content, $pos - 1, 1, 'utf-8');
                // 如果前一个字符是中文字符或字母，则不独立
                if (preg_match('/[\x{4e00}-\x{9fff}a-zA-Z]/u', $prevChar)) {
                    $isIndependent = false;
                }
            }
            
            // 检查后一个字符
            if ($pos < $contentLen - 1) {
                $nextChar = mb_substr($content, $pos + 1, 1, 'utf-8');
                // 如果后一个字符是中文字符或字母，则不独立
                if (preg_match('/[\x{4e00}-\x{9fff}a-zA-Z]/u', $nextChar)) {
                    $isIndependent = false;
                }
            }
            
            // 如果找到一个独立出现的位置，就返回true
            if ($isIndependent) {
                return true;
            }
        }
        
        return false;
    }
} 