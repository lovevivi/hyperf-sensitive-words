<?php

namespace SensitiveWords\Matchers;

class FuzzyMatcher
{
    /**
     * 简单的模糊检测结果缓存
     */
    private static $fuzzyCache = [];
    
    /**
     * 子序列匹配：检查敏感词的字符是否按顺序出现在输入文本中
     */
    public function isSubsequenceMatch(string $pattern, string $text): bool
    {
        // 快速失败检查
        $patternLen = mb_strlen($pattern, 'utf-8');
        if ($patternLen === 0) {
            return false;
        }
        
        // 预处理：只保留中文字符和字母，移除数字和符号
        $cleanText = preg_replace('/[\s\.\-_\*\+\~\!\@\#\$\%\^\&\d]/u', '', $text);
        $textLen = mb_strlen($cleanText, 'utf-8');
        
        if ($patternLen > $textLen) {
            return false;
        }
        
        // 快速检查：第一个和最后一个字符是否存在
        $firstChar = mb_substr($pattern, 0, 1, 'utf-8');
        $lastChar = mb_substr($pattern, -1, 1, 'utf-8');
        
        if (mb_strpos($cleanText, $firstChar, 0, 'utf-8') === false || 
            mb_strpos($cleanText, $lastChar, 0, 'utf-8') === false) {
            return false;
        }
        
        // 根据敏感词长度设置距离限制
        $maxDistance = $this->getMaxDistanceForPattern($patternLen);
        
        // 双指针算法进行子序列匹配（带距离约束）
        $i = 0; 
        $j = 0;
        $lastMatchPos = -1;
        
        while ($i < $patternLen && $j < $textLen) {
            $patternChar = mb_substr($pattern, $i, 1, 'utf-8');
            $textChar = mb_substr($cleanText, $j, 1, 'utf-8');
            
            if ($patternChar === $textChar) {
                // 检查距离约束
                if ($lastMatchPos >= 0 && ($j - $lastMatchPos) > $maxDistance) {
                    return false; // 字符间距太大，可能是误检
                }
                
                $lastMatchPos = $j;
                $i++;
                if ($i === $patternLen) {
                    return true;
                }
            }
            $j++;
        }
        
        return $i === $patternLen;
    }
    
    /**
     * 根据敏感词长度计算最大允许的字符间距
     * @param int $patternLen 敏感词长度
     * @return int 最大允许间距
     */
    private function getMaxDistanceForPattern(int $patternLen): int
    {
        if ($patternLen <= 2) {
            return 4; 
        } elseif ($patternLen <= 3) {
            return 10; 
        } elseif ($patternLen <= 5) {
            return 8; 
        } else {
            return 12; 
        }
    }
    
    /**
     * 清除模糊检测缓存
     */
    public function clearCache(): void
    {
        self::$fuzzyCache = [];
    }
    
    /**
     * 缓存检查 - 避免重复计算相同内容
     */
    public function getCacheKey(string $content): string
    {
        return md5($content);
    }
    
    /**
     * 检查缓存
     */
    public function checkCache(string $cacheKey): ?bool
    {
        return self::$fuzzyCache[$cacheKey] ?? null;
    }
    
    /**
     * 设置缓存
     */
    public function setCache(string $cacheKey, bool $result): void
    {
        // 限制缓存大小，避免内存泄漏
        if (count(self::$fuzzyCache) > 500) {
            self::$fuzzyCache = array_slice(self::$fuzzyCache, -250, null, true);
        }
        
        self::$fuzzyCache[$cacheKey] = $result;
    }
} 