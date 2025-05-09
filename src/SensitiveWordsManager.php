<?php

declare(strict_types=1);

namespace SensitiveWords;

use Hyperf\Contract\ConfigInterface;
use SensitiveWords\Helpers\SensitiveHelper;
use SensitiveWords\Exceptions\SensitiveWordException;

class SensitiveWordsManager
{
    /**
     * @var ConfigInterface
     */
    protected $config;
    
    /**
     * @var SensitiveHelper
     */
    protected $sensitiveHelper;
    
    public function __construct(ConfigInterface $config, SensitiveHelper $sensitiveHelper)
    {
        $this->config = $config;
        $this->sensitiveHelper = $sensitiveHelper;
    }
    
    /**
     * 检测内容是否包含敏感词
     * 
     * @throws SensitiveWordException
     */
    public function check(string $content): bool
    {
        return !$this->sensitiveHelper->islegal($content);
    }
    
    /**
     * 替换敏感词
     * 
     * @throws SensitiveWordException
     */
    public function replace(string $content, string $replaceChar = '*', bool $repeat = true): string
    {
        return $this->sensitiveHelper->replace($content, $replaceChar, $repeat);
    }
    
    /**
     * 获取内容中的敏感词
     * 
     * @throws SensitiveWordException
     */
    public function getBadWords(string $content, int $wordNum = 0): array
    {
        return $this->sensitiveHelper->getBadWord($content, 1, $wordNum);
    }
    
    /**
     * 高亮标记敏感词
     * 
     * @throws SensitiveWordException
     */
    public function mark(string $content, string $sTag = '<span style="color:red">', string $eTag = '</span>'): string
    {
        return $this->sensitiveHelper->mark($content, $sTag, $eTag);
    }
    
    /**
     * 更新词库
     *
     * @throws SensitiveWordException
     */
    public function updateWordLibrary(array $words = []): bool
    {
        if (!empty($words)) {
            $this->sensitiveHelper->setTree($words);
            return true;
        }
        return false;
    }
    
    /**
     * 预热词库，可在应用启动时调用
     * 
     * @return bool 预热是否成功
     */
    public function warmup(): bool
    {
        return $this->sensitiveHelper->warmup();
    }
    
    /**
     * 清除词库缓存
     * 
     * @return bool 清除是否成功
     */
    public function clearCache(): bool
    {
        return $this->sensitiveHelper->clearCache();
    }
    
    /**
     * 设置表情符号处理策略
     * 
     * @param string $strategy 策略 (ignore, remove, replace, include)
     * @param string $placeholder 替换占位符 (当strategy为replace时生效)
     * @return bool
     */
    public function setEmojiStrategy(string $strategy, string $placeholder = '[表情]'): bool
    {
        // 验证策略是否有效
        if (!in_array($strategy, ['ignore', 'remove', 'replace', 'include'])) {
            return false;
        }
        
        // 更新配置
        $this->config->set('sensitive_words.emoji_strategy', $strategy);
        if ($strategy === 'replace') {
            $this->config->set('sensitive_words.emoji_placeholder', $placeholder);
        }
        
        return true;
    }
    
    /**
     * 启用或禁用变形文本检测
     * 
     * @param bool $enable 是否启用
     * @return bool
     */
    public function enableVariantTextDetection(bool $enable = true): bool
    {
        $this->config->set('sensitive_words.detect_variant_text', $enable);
        return true;
    }
    
    /**
     * 设置变形文本映射表路径
     * 
     * @param string $path 映射表路径
     * @return bool
     */
    public function setVariantMapPath(string $path): bool
    {
        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }
        
        $this->config->set('sensitive_words.variant_map_path', $path);
        return true;
    }
    
    /**
     * 添加词库中不存在的敏感词
     * 
     * @param array $words 要添加的词列表
     * @return bool
     */
    public function addWords(array $words): bool
    {
        if (empty($words)) {
            return false;
        }
        
        return $this->sensitiveHelper->addWords($words);
    }
} 