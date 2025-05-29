<?php

namespace SensitiveWords\Helpers;

use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use SensitiveWords\Exceptions\SensitiveWordException;

class SensitiveHelper
{
    /**
     * 待检测语句长度
     *
     * @var int
     */
    protected $contentLength = 0;

    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * 默认敏感词库路径
     *
     * @var string
     */
    protected $defaultWordPath;

    /**
     * 敏感词库树
     *
     * @var HashMap|null
     */
    protected $wordTree = null;

    /**
     * 存放待检测语句敏感词
     *
     * @var array|null
     */
    protected static $badWordList = null;
    
    /**
     * 词库缓存文件路径
     *
     * @var string
     */
    protected $cacheFilePath;
    
    /**
     * 是否启用缓存
     *
     * @var bool
     */
    protected $enableCache = false;
    
    /**
     * 缓存过期时间（秒）
     *
     * @var int
     */
    protected $cacheExpire = 86400; // 默认1天

    /**
     * 敏感词前缀索引，用于快速过滤
     * 格式：['首字符' => [词1, 词2...]]
     *
     * @var array
     */
    protected $prefixIndex = [];
    
    /**
     * 是否启用前缀索引加速
     *
     * @var bool
     */
    protected $enablePrefixIndex = true;

    /**
     * 表情符号处理策略
     *
     * @var string
     */
    protected $emojiStrategy = 'ignore';
    
    /**
     * 表情符号替换占位符
     *
     * @var string
     */
    protected $emojiPlaceholder = '[表情]';
    
    /**
     * 是否启用变形文本检测
     *
     * @var bool
     */
    protected $detectVariantText = false;
    
    /**
     * 变形文本映射表
     *
     * @var array
     */
    protected $variantMap = [];

    /**
     * 白名单词库
     * 格式：['白名单词' => true]
     *
     * @var array
     */
    protected $whitelist = [];

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
        // 设置默认敏感词库路径
        $this->defaultWordPath = dirname(dirname(__DIR__)) . '/data/sensitive_words.txt';
        
        // 设置缓存相关配置
        $this->enableCache = $this->config->get('sensitive_words.enable_cache', true);
        $this->cacheExpire = $this->config->get('sensitive_words.cache_expire', 86400);
        
        // 设置表情符号和变形文本处理配置
        $this->emojiStrategy = $this->config->get('sensitive_words.emoji_strategy', 'ignore');
        $this->emojiPlaceholder = $this->config->get('sensitive_words.emoji_placeholder', '[表情]');
        $this->detectVariantText = $this->config->get('sensitive_words.detect_variant_text', false);
        
        // 加载白名单
        $whitelistConfig = $this->config->get('sensitive_words.whitelist', []);
        if (!empty($whitelistConfig) && is_array($whitelistConfig)) {
            $this->whitelist = array_fill_keys($whitelistConfig, true);
        }
        
        // 加载变形文本映射表
        if ($this->detectVariantText) {
            $this->loadVariantMap();
        }
        
        // 获取缓存路径：优先使用用户配置，否则尝试使用框架缓存目录，最后降级到系统临时目录
        $userCachePath = $this->config->get('sensitive_words.cache_path', '');
        if (!empty($userCachePath)) {
            $cachePath = $userCachePath;
        } elseif (defined('BASE_PATH')) {
            // 使用框架的缓存目录
            $cachePath = BASE_PATH . '/runtime/cache';
        } else {
            // 降级使用系统临时目录
            $cachePath = sys_get_temp_dir() . '/sensitive-words';
        }
        
        // 确保缓存目录存在
        if (!is_dir($cachePath) && $this->enableCache) {
            mkdir($cachePath, 0755, true);
        }
        
        $this->cacheFilePath = $cachePath . '/sensitive_words_tree.cache';
        
        // 如果需要预热，立即初始化词库
        if ($this->config->get('sensitive_words.preload', false)) {
            $this->initWordLibrary();
        }
    }

    /**
     * 获取实例
     */
    public function init(): self
    {
        // 从Context中获取或创建实例，支持协程隔离
        $instance = Context::get(static::class);
        if (!$instance instanceof self) {
            $instance = $this;
            Context::set(static::class, $instance);
        }
        
        return $instance;
    }

    /**
     * 初始化敏感词库
     * @throws SensitiveWordException
     */
    protected function initWordLibrary()
    {
        // 尝试从缓存加载
        if ($this->enableCache && $this->loadFromCache()) {
            return;
        }
        
        // 获取用户配置的词库路径
        $userWordPath = $this->config->get('sensitive_words.word_path', '');
        // 获取词库合并模式：override-覆盖模式，append-追加模式
        $mergeMode = $this->config->get('sensitive_words.merge_mode', 'append');
        // 定义是否需要加载默认词库
        $loadDefault = true;
        
        // 如果用户配置了词库路径并且文件存在
        if (!empty($userWordPath) && file_exists($userWordPath)) {
            // 如果是覆盖模式，则不加载默认词库
            if ($mergeMode === 'override') {
                $loadDefault = false;
                $this->setTreeByFile($userWordPath);
            } elseif ($mergeMode === 'append' && file_exists($this->defaultWordPath)) {  // 如果是追加模式且默认词库存在
                // 先加载默认词库
                $words = [];
                foreach ($this->yieldToReadFile($this->defaultWordPath) as $line) {
                    $word = trim($line);
                    if (!empty($word)) {
                        $words[] = $word;
                    }
                }
                
                // 再加载用户词库，合并词库数据
                foreach ($this->yieldToReadFile($userWordPath) as $line) {
                    $word = trim($line);
                    if (!empty($word) && !in_array($word, $words)) {
                        $words[] = $word;
                    }
                }
                
                // 设置合并后的词库
                $this->setTree($words);
                $loadDefault = false;
            }
        }
        
        // 如果需要加载默认词库且默认词库文件存在
        if ($loadDefault && file_exists($this->defaultWordPath)) {
            $this->setTreeByFile($this->defaultWordPath);
        }
        
        // 词库加载完成后保存到缓存
        if ($this->enableCache && $this->wordTree !== null && !$this->wordTree->isEmpty()) {
            $this->saveToCache();
        }
    }
    
    /**
     * 从缓存加载词库树
     * @return bool 是否成功加载
     */
    protected function loadFromCache(): bool
    {
        if (!file_exists($this->cacheFilePath)) {
            return false;
        }
        
        // 检查缓存是否过期
        if ($this->cacheExpire > 0 && (time() - filemtime($this->cacheFilePath)) > $this->cacheExpire) {
            return false;
        }
        
        try {
            $cacheData = file_get_contents($this->cacheFilePath);
            if ($cacheData === false) {
                return false;
            }
            
            $cacheArray = unserialize($cacheData);
            if (!is_array($cacheArray)) {
                return false;
            }
            
            // 从缓存加载词库树、前缀索引和白名单
            $this->wordTree = $cacheArray['wordTree'] ?? null;
            $this->prefixIndex = $cacheArray['prefixIndex'] ?? [];
            
            // 加载缓存中的白名单，但不覆盖配置文件中的白名单
            $cachedWhitelist = $cacheArray['whitelist'] ?? [];
            if (!empty($cachedWhitelist) && is_array($cachedWhitelist)) {
                // 合并配置文件中的白名单和缓存中的白名单
                $this->whitelist = array_merge($this->whitelist, $cachedWhitelist);
            }
            
            if (!($this->wordTree instanceof HashMap)) {
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            // 缓存读取失败，返回false以触发重新构建词库
            return false;
        }
    }
    
    /**
     * 保存词库树到缓存
     * @return bool 是否成功保存
     */
    protected function saveToCache(): bool
    {
        if ($this->wordTree === null) {
            return false;
        }
        
        try {
            // 保存词库树和前缀索引
            $cacheData = serialize([
                'wordTree' => $this->wordTree,
                'prefixIndex' => $this->prefixIndex,
                'whitelist' => $this->whitelist
            ]);
            return file_put_contents($this->cacheFilePath, $cacheData) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 预热词库（手动触发）
     * @return bool 预热是否成功
     */
    public function warmup(): bool
    {
        try {
            $this->initWordLibrary();
            return $this->wordTree !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清除词库缓存
     *
     * @return bool 是否成功清除
     */
    public function clearCache(): bool
    {
        if (file_exists($this->cacheFilePath)) {
            return @unlink($this->cacheFilePath);
        }
        return true;
    }

    /**
     * 构建敏感词树【文件模式】
     * @param string $filepath
     *
     * @return $this
     * @throws \SensitiveWords\Exceptions\SensitiveWordException
     */
    public function setTreeByFile($filepath = '')
    {
        if (empty($filepath)) {
            throw new SensitiveWordException('Sensitive word file path cannot be empty');
        }
        if (!file_exists($filepath) || !is_readable($filepath)) {
            throw new SensitiveWordException("Sensitive word file does not exist or is not readable: {$filepath}");
        }

        $this->wordTree = new HashMap();
        $this->prefixIndex = [];
        $this->enablePrefixIndex = $this->config->get('sensitive_words.enable_prefix_index', true);

        foreach ($this->yieldToReadFile($filepath) as $line) {
            $trimmedWord = trim($line);
            if (empty($trimmedWord)) {
                continue;
            }
            
            $this->buildWordToTree($trimmedWord);
            
            if ($this->enablePrefixIndex) {
                $prefix = mb_substr($trimmedWord, 0, 1, 'utf-8');
                if (!isset($this->prefixIndex[$prefix])) {
                    $this->prefixIndex[$prefix] = [];
                }
                $this->prefixIndex[$prefix][] = $trimmedWord; 
            }
        }
        
        if ($this->enableCache && $this->wordTree !== null && !$this->wordTree->isEmpty()) {
            $this->saveToCache();
        }
        return $this;
    }

    /**
     * 构建敏感词树【数组模式】
     * @param null $sensitiveWords
     *
     * @return $this
     * @throws \SensitiveWords\Exceptions\SensitiveWordException
     */
    public function setTree($sensitiveWords = null)
    {
        if ($sensitiveWords === null) {
            $this->wordTree = new HashMap();
            $this->prefixIndex = [];
            if ($this->enableCache) {
                $this->saveToCache(); 
            }
            return $this;
        }

        if (!is_array($sensitiveWords)) {
            throw new SensitiveWordException('敏感词库必须是数组类型', SensitiveWordException::EMPTY_WORD_POOL);
        }

        $this->wordTree = new HashMap();
        $this->prefixIndex = [];
        $this->enablePrefixIndex = $this->config->get('sensitive_words.enable_prefix_index', true);

        foreach ($sensitiveWords as $word) {
            $word = trim($word);
            if (empty($word)) {
                continue;
            }
            $this->buildWordToTree($word);

            if ($this->enablePrefixIndex && mb_strlen($word) > 0) {
                $firstChar = mb_substr($word, 0, 1, 'utf-8');
                if (!isset($this->prefixIndex[$firstChar])) {
                    $this->prefixIndex[$firstChar] = [];
                }
                if (!in_array($word, $this->prefixIndex[$firstChar])) {
                    $this->prefixIndex[$firstChar][] = $word;
                }
            }
        }

        if ($this->enableCache && $this->wordTree !== null && !$this->wordTree->isEmpty()) {
            $this->saveToCache();
        }
        return $this;
    }

    /**
     * 使用上下文白名单过滤已识别的潜在敏感词列表
     * @param string $processedContent 预处理后的文本内容
     * @param array  $potentialBadWordsInfo 潜在的敏感词信息数组，每个元素包含 ['word' => string, 'offset' => int, 'len' => int]
     * @return array 经过上下文白名单过滤后的敏感词信息数组 (保留 offset, len)
     */
    private function filterWithContextualWhitelist(string $processedContent, array $potentialBadWordsInfo): array
    {
        if (empty($potentialBadWordsInfo) || empty($this->whitelist)) {
            return $potentialBadWordsInfo; 
        }

        $finalFilteredWordsInfo = []; // 将存储包含详细信息的元素

        foreach ($potentialBadWordsInfo as $badWordItem) {
            $word = $badWordItem['word'];
            $offset = $badWordItem['offset'];

            // 1. 检查 $word 本身是否是直接白名单
            if (isset($this->whitelist[$word])) {
                continue; 
            }

            // 2. 检查上下文：$word 是否是更长白名单词的一部分
            $isWhitelistedByContext = false;
            foreach (array_keys($this->whitelist) as $whitelistedWord) {
                $whitelistedWordLen = mb_strlen($whitelistedWord, 'utf-8');

                // $word 是 $whitelistedWord 的前缀，且 $whitelistedWord 从 $offset 开始匹配
                if (mb_strpos($whitelistedWord, $word, 0, 'utf-8') === 0) { 
                    if (($offset + $whitelistedWordLen) <= mb_strlen($processedContent, 'utf-8') &&
                        mb_substr($processedContent, $offset, $whitelistedWordLen, 'utf-8') === $whitelistedWord)
                    {
                        $isWhitelistedByContext = true;
                        break;
                    }
                }

                // $word 是 $whitelistedWord 的子串（非前缀）或后缀
                $relativePos = mb_strpos($whitelistedWord, $word, 0, 'utf-8');
                if ($relativePos !== false) { 
                    $expectedWhitelistedWordStartInText = $offset - $relativePos;
                    if ($expectedWhitelistedWordStartInText >= 0 && 
                        ($expectedWhitelistedWordStartInText + $whitelistedWordLen) <= mb_strlen($processedContent, 'utf-8') && 
                        mb_substr($processedContent, $expectedWhitelistedWordStartInText, $whitelistedWordLen, 'utf-8') === $whitelistedWord)
                    {
                        $isWhitelistedByContext = true;
                        break; 
                    }
                }
            }

            if (!$isWhitelistedByContext) {
                $finalFilteredWordsInfo[] = $badWordItem; // 保留完整的 $badWordItem (包含 offset, len)
            }
        }
        return $finalFilteredWordsInfo; 
    }

    /**
     * 获取所有敏感词列表
     * @return array 返回所有敏感词的数组
     */
    public function getAllSensitiveWords(): array
    {
        // 如果词库树为null且前缀索引未初始化，则初始化词库
        if ($this->wordTree === null && !is_array($this->prefixIndex)) {
            $this->initWordLibrary();
        }
        
        // 如果启用了前缀索引，直接从前缀索引中获取所有词语
        if ($this->enablePrefixIndex && !empty($this->prefixIndex)) {
            $allWords = [];
            foreach ($this->prefixIndex as $words) {
                $allWords = array_merge($allWords, $words);
            }
            return array_values(array_unique($allWords));
        }
        
        // 如果没有前缀索引，从DFA树中提取所有敏感词
        if ($this->wordTree === null || $this->wordTree->isEmpty()) {
            return [];
        }
        
        return $this->extractWordsFromTree();
    }

    /**
     * 检测文字中的敏感词
     * @param string   $content    待检测内容
     * @param int      $matchType  匹配类型 [默认为最小匹配规则]
     * @param int      $wordNum    需要获取的敏感词数量 [默认获取全部]
     * @param bool     $returnDetails 是否返回包含偏移量和长度的详细信息 [默认false，返回string[]]
     * @return array 返回敏感词列表。默认是字符串数组 string[]。如果 $returnDetails 为 true，则返回 array{word: string, offset: int, len: int}[]
     * @throws \SensitiveWords\Exceptions\SensitiveWordException
     */
    public function getBadWord($content, $matchType = 1, $wordNum = 0, bool $returnDetails = false)
    {
        if ($this->wordTree === null) {
            $this->initWordLibrary();
        }
        
        if ($this->wordTree === null || $this->wordTree->isEmpty()) {
            return []; 
        }

        $processedContent = $this->preprocessContent($content);
        $this->contentLength = mb_strlen($processedContent, 'utf-8');
        $initialBadWordListWithDetails = [];

        // 如果启用了前缀索引加速，并且文本长度在合理范围
        if ($this->enablePrefixIndex && count($this->prefixIndex) > 0 && $this->contentLength > 0 && $this->contentLength < 10000) {
            $possiblePrefixes = [];
            for ($i = 0; $i < $this->contentLength; $i++) {
                $char = mb_substr($processedContent, $i, 1, 'utf-8');
                $possiblePrefixes[$char] = true;
            }
            $tempBadWordListFromPrefix = [];
            foreach (array_keys($possiblePrefixes) as $prefix) {
                if (isset($this->prefixIndex[$prefix])) {
                    $resultDetails = $this->checkWordsWithPrefix($processedContent, $prefix);
                    if (!empty($resultDetails)) {
                        $tempBadWordListFromPrefix = array_merge($tempBadWordListFromPrefix, $resultDetails);
                    }
                }
            }
            $initialBadWordListWithDetails = array_map("unserialize", array_unique(array_map("serialize", $tempBadWordListFromPrefix)));

            if (($matchType === 1 || $matchType === 0) && count($initialBadWordListWithDetails) > 1) {
                $initialBadWordListWithDetails = $this->_alignPrefixIndexWords($initialBadWordListWithDetails, $matchType);
            }
        } else { 
            for ($length = 0; $length < $this->contentLength; $length++) {
                $matchFlag = 0;
                $flag = false;
                $tempMap = $this->wordTree;
                for ($i = $length; $i < $this->contentLength; $i++) {
                    $keyChar = mb_substr($processedContent, $i, 1, 'utf-8');
                    $nowMap = $tempMap->get($keyChar);
                    if (empty($nowMap)) {
                        break;
                    }
                    $tempMap = $nowMap;
                    $matchFlag++;
                    if (false === $nowMap->get('ending')) {
                        continue;
                    }
                    $flag = true;
                    if (1 === $matchType) { 
                        break;
                    }
                }
                if (!$flag) $matchFlag = 0;
                if ($matchFlag <= 0) continue;
                $currentBadWord = mb_substr($processedContent, $length, $matchFlag, 'utf-8');
                $initialBadWordListWithDetails[] = [
                    'word' => $currentBadWord,
                    'offset' => $length, 
                    'len' => $matchFlag,
                ];
                $length = $length + $matchFlag - 1; 
            }
            $initialBadWordListWithDetails = array_map("unserialize", array_unique(array_map("serialize", $initialBadWordListWithDetails)));
        }
        
        $finalBadWordsWithDetails = $this->filterWithContextualWhitelist($processedContent, $initialBadWordListWithDetails);

        // 统一处理 $wordNum，如果需要限制数量，先排序再截取
        if ($wordNum > 0 && count($finalBadWordsWithDetails) > $wordNum) {
            usort($finalBadWordsWithDetails, function ($a, $b) {
                if ($a['offset'] == $b['offset']) {
                    return $b['len'] - $a['len']; 
                }
                return $a['offset'] - $b['offset']; 
            });
            $finalBadWordsWithDetails = array_slice($finalBadWordsWithDetails, 0, $wordNum);
        }

        if ($returnDetails) {
            // 确保返回的数组是标准的、从0开始的数字索引数组
            return array_values($finalBadWordsWithDetails); 
        } else {
            // 默认情况：返回敏感词字符串列表 (string[])
            $resultWords = [];
            foreach ($finalBadWordsWithDetails as $item) { 
                $resultWords[] = $item['word'];
            }
            // 返回唯一的词语字符串列表
            return array_values(array_unique($resultWords)); 
        }
    }


    /**
     * 替换敏感字字符
     * @param        $content      文本内容
     * @param string $replaceChar  替换字符
     * @param bool   $repeat       true=>重复替换为敏感词相同长度的字符
     * @param int    $matchType
     *
     * @return mixed
     * @throws \SensitiveWords\Exceptions\SensitiveWordException
     */
    public function replace($content, $replaceChar = '', $repeat = false, $matchType = 1)
    {
        $detailedBadWords = $this->getBadWord($content, $matchType, 0, true);
        $processedContent = $this->preprocessContent($content);
  
        if (empty($detailedBadWords)) {
            return $processedContent; 
        }

        // 从后往前替换，避免 offset 错乱
        for ($i = count($detailedBadWords) - 1; $i >= 0; $i--) {
            $badWordInfo = $detailedBadWords[$i];
            $wordToReplace = $badWordInfo['word'];
            $offset = $badWordInfo['offset'];
            $len = $badWordInfo['len'];

            $actualReplaceChar = $replaceChar;
            if ($repeat) {
                $actualReplaceChar = str_repeat($replaceChar, mb_strlen($wordToReplace));
            }

            $processedContent = self::mb_substr_replace($processedContent, $actualReplaceChar, $offset, $len);
        }

        return $processedContent;
    }

    /**
     * 标记敏感词
     * @param string $content 待检测内容
     * @param string $sTag 敏感词包裹开始标签
     * @param string $eTag 敏感词包裹结束标签
     * @param int $matchType 匹配类型，默认为 1 (最长匹配)
     * @return string 返回标记后的内容 (在预处理后的内容上操作)
     * @throws SensitiveWordException
     */
    public function mark($content, $sTag, $eTag, $matchType = 1)
    {
        $detailedBadWords = $this->getBadWord($content, $matchType, 0, true);
        $processedContent = $this->preprocessContent($content);
  
        if (empty($detailedBadWords)) {
            return $processedContent; 
        }

        // 从后往前标记，避免 offset 错乱
        for ($i = count($detailedBadWords) - 1; $i >= 0; $i--) {
            $badWordInfo = $detailedBadWords[$i];
            $wordToMark = $badWordInfo['word']; 
            $offset = $badWordInfo['offset'];
            $len = $badWordInfo['len'];

            $replacement = $sTag . $wordToMark . $eTag;
            $processedContent = self::mb_substr_replace($processedContent, $replacement, $offset, $len);
        }

        return $processedContent;
    }

    /**
     * 被检测内容是否合法
     * @param $content
     * @return bool
     * @throws \SensitiveWords\Exceptions\SensitiveWordException
     */
    public function islegal($content)
    {
        return empty($this->getBadWord($content, 1, 1)); 
    }

     /**
     * 添加增量更新词库
     * @param array $words
     *
     * @return bool
     */
    public function addWords(array $words): bool
    {
        if (empty($words)) {
            return false;
        }

        if ($this->wordTree === null) {
            $this->initWordLibrary();
        }
        
        if (!$this->wordTree instanceof HashMap) {
             $this->wordTree = new HashMap();
             $this->prefixIndex = []; 
        }
        if (!is_array($this->prefixIndex)) { 
            $this->prefixIndex = [];
        }

        $this->enablePrefixIndex = $this->config->get('sensitive_words.enable_prefix_index', true);
        
        $wordsAdded = false; 
        foreach ($words as $word) {
            $word = trim($this->preprocessContent($word)); 
            if (empty($word)) {
                continue;
            }
            
            $this->buildWordToTree($word);
            $wordsAdded = true; 

            if ($this->enablePrefixIndex) {
                if (!is_array($this->prefixIndex)) { 
                    $this->prefixIndex = [];
                }
                $prefix = mb_substr($word, 0, 1, 'utf-8');
                if (!isset($this->prefixIndex[$prefix])) {
                    $this->prefixIndex[$prefix] = [];
                }
                if (!in_array($word, $this->prefixIndex[$prefix])) { 
                    $this->prefixIndex[$prefix][] = $word;
                }
            }
        }

        if ($wordsAdded && $this->enableCache) {
            $this->saveToCache();
        }
        
        return $wordsAdded;
    }

    /**
     * 添加白名单词语
     * @param array $words 要添加的白名单词语数组
     * @return bool 是否成功添加
     */
    public function addWhitelistWords(array $words): bool
    {
        if (empty($words)) {
            return false;
        }

        if ($this->wordTree === null) {
            $this->initWordLibrary();
        }
        
        if (!$this->wordTree instanceof HashMap) {
             $this->wordTree = new HashMap();
             $this->prefixIndex = []; 
        }

        $wordsAdded = false;
        foreach ($words as $word) {
            $word = trim($word);
            if (empty($word)) {
                continue;
            }

            // 预处理白名单词语，确保与敏感词检测时的处理一致
            $processedWord = $this->preprocessContent($word);
            if (!empty($processedWord) && !isset($this->whitelist[$processedWord])) {
                $this->whitelist[$processedWord] = true;
                $wordsAdded = true;
            }
        }

        // 如果有新增白名单词语且启用了缓存，更新缓存
        if ($wordsAdded && $this->enableCache) {
            $this->saveToCache();
        }

        return $wordsAdded;
    }

    /**
     * 删除白名单词语
     * @param array $words 要删除的白名单词语数组
     * @return bool 是否成功删除
     */
    public function removeWhitelistWords(array $words): bool
    {
        if (empty($words) || empty($this->whitelist)) {
            return false;
        }

        $wordsRemoved = false;
        foreach ($words as $word) {
            $word = trim($word);
            if (empty($word)) {
                continue;
            }

            // 预处理白名单词语
            $processedWord = $this->preprocessContent($word);
            if (isset($this->whitelist[$processedWord])) {
                unset($this->whitelist[$processedWord]);
                $wordsRemoved = true;
            }
        }

        // 如果有删除白名单词语且启用了缓存，更新缓存
        if ($wordsRemoved && $this->enableCache) {
            $this->saveToCache();
        }

        return $wordsRemoved;
    }

    /**
     * 清空所有白名单词语
     * @return bool 是否成功清空
     */
    public function clearWhitelist(): bool
    {
        if (empty($this->whitelist)) {
            return false;
        }

        $this->whitelist = [];

        // 如果启用了缓存，更新缓存
        if ($this->enableCache) {
            $this->saveToCache();
        }

        return true;
    }

    /**
     * 获取当前所有白名单词语
     * @return array 白名单词语数组
     */
    public function getWhitelistWords(): array
    {
        return array_keys($this->whitelist);
    }

    /**
     * 设置白名单词语（覆盖现有白名单）
     * @param array $words 白名单词语数组
     * @return bool 是否成功设置
     */
    public function setWhitelistWords(array $words): bool
    {
        $this->whitelist = [];

        if (empty($words)) {
            // 如果启用了缓存，更新缓存
            if ($this->enableCache) {
                $this->saveToCache();
            }
            return true;
        }

        return $this->addWhitelistWords($words);
    }

    /**
     * 检查词语是否在白名单中
     * @param string $word 要检查的词语
     * @return bool 是否在白名单中
     */
    public function isWhitelisted(string $word): bool
    {
        if (empty($word) || empty($this->whitelist)) {
            return false;
        }

        $processedWord = $this->preprocessContent($word);
        return isset($this->whitelist[$processedWord]);
    }

    /**
     * 读取文件
     * @param $filepath
     * @return \Generator
     */
    protected function yieldToReadFile($filepath)
    {
        $handle = fopen($filepath, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
            fclose($handle);
        }
    }

    /**
     * 将单个敏感词构建成树结构
     * @param string $word
     *
     * @return mixed
     */
    protected function buildWordToTree($word = '')
    {
        if ('' === $word) {
            return false;
        }
        $tree = $this->wordTree;

        $wordLength = mb_strlen($word, 'utf-8');
        for ($i = 0; $i < $wordLength; $i++) {
            $keyChar = mb_substr($word, $i, 1, 'utf-8');

            // 获取子节点树结构
            $tempTree = $tree->get($keyChar);

            if ($tempTree) {
                $tree = $tempTree;
            } else {
                // 设置标志位
                $newTree = new HashMap();
                $newTree->put('ending', false);

                // 添加到集合
                $tree->put($keyChar, $newTree);
                $tree = $newTree;
            }

            // 到达最后一个节点
            if ($i == $wordLength - 1) {
                $tree->put('ending', true);
            }
        }

        return true;
    }

    /**
     * 敏感词替换为对应长度的字符
     * @param string $word
     * @param string $char
     * @return string
     */
    protected function dfaBadWordConversChars($word, $char)
    {
        $str = '';
        $length = mb_strlen($word, 'utf-8');
        for ($counter = 0; $counter < $length; ++$counter) {
            $str .= $char;
        }

        return $str;
    }

    /**
     * 在给定内容中查找所有以特定字符开头并存在于前缀索引中的敏感词。
     * 此方法是前缀索引优化路径的一部分。它接收一个字符（前缀），
     * 然后在内容中搜索前缀索引中所有以该字符开头的敏感词的完整匹配。
     *
     * @param string $content         待检测的内容（通常是预处理后的）。
     * @param string $prefix          单个字符，作为查找的前缀。
     *
     * @return array 返回一个扁平数组，包含所有在内容中找到的、以指定前缀开头的敏感词字符串。
     */
    protected function checkWordsWithPrefix($content, $prefix)
    {
        if (!isset($this->prefixIndex[$prefix]) || empty($this->prefixIndex[$prefix])) {
            return [];
        }
        
        $badWordList = []; // This will now store arrays, not strings
        $wordsToCheck = $this->prefixIndex[$prefix];
        $contentLength = mb_strlen($content, 'utf-8'); 
        foreach ($wordsToCheck as $word) {
            $wordLength = mb_strlen($word, 'utf-8');
            if ($wordLength == 0) continue;
            if ($wordLength > $contentLength) continue;
            
            $pos = 0;
            while (($currentPos = mb_strpos($content, $word, $pos, 'utf-8')) !== false) { 
                $badWordList[] = [
                    'word' => $word,
                    'offset' => $currentPos,
                    'len' => $wordLength
                ];
                $pos = $currentPos + $wordLength;
            }
        }
        return $badWordList; 
    }

    /**
     * 内容预处理，处理表情符号和特殊字符
     * @param string $content 原始内容
     * @return string 预处理后的内容
     */
    protected function preprocessContent(string $content): string
    {
        // 跳过空内容
        if (empty($content)) {
            return $content;
        }
        
        // 处理表情符号
        if ($this->emojiStrategy !== 'ignore' && $this->emojiStrategy !== 'include') {
            // 表情符号的Unicode范围
            $pattern = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';
            
            if ($this->emojiStrategy === 'remove') {
                $content = preg_replace($pattern, '', $content);
            } elseif ($this->emojiStrategy === 'replace') {
                $content = preg_replace($pattern, $this->emojiPlaceholder, $content);
            }
        }
        
        // 处理变形文本
        if ($this->detectVariantText && !empty($this->variantMap)) {
            // 删除常见分隔字符
            $content = preg_replace('/[\s\.\-_\*\+\~\!\@\#\$\%\^\&]/u', '', $content);
            
            // 应用变形文本映射
            $content = strtr($content, $this->variantMap);
        }
        
        return $content;
    }
    
    /**
     * 加载变形文本映射表
     */
    protected function loadVariantMap(): void
    {
        // 用户自定义映射表路径
        $mapPath = $this->config->get('sensitive_words.variant_map_path', '');
        
        // 尝试从用户自定义路径加载
        if (!empty($mapPath) && file_exists($mapPath)) {
            $customMap = include $mapPath;
            if (is_array($customMap)) {
                $this->variantMap = $customMap;
                return;
            }
        }
        
        // 使用内置的基础映射表
        $this->variantMap = [
            // 全角转半角
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
            'ａ' => 'a', 'ｂ' => 'b', 'ｃ' => 'c', 'ｄ' => 'd', 'ｅ' => 'e',
            'ｆ' => 'f', 'ｇ' => 'g', 'ｈ' => 'h', 'ｉ' => 'i', 'ｊ' => 'j',
            'ｋ' => 'k', 'ｌ' => 'l', 'ｍ' => 'm', 'ｎ' => 'n', 'ｏ' => 'o',
            'ｐ' => 'p', 'ｑ' => 'q', 'ｒ' => 'r', 'ｓ' => 's', 'ｔ' => 't',
            'ｕ' => 'u', 'ｖ' => 'v', 'ｗ' => 'w', 'ｘ' => 'x', 'ｙ' => 'y', 'ｚ' => 'z',
            
            // 特殊数字
            '⓪' => '0', '①' => '1', '②' => '2', '③' => '3', '④' => '4', 
            '⑤' => '5', '⑥' => '6', '⑦' => '7', '⑧' => '8', '⑨' => '9',
            '⑴' => '1', '⑵' => '2', '⑶' => '3', '⑷' => '4', '⑸' => '5',
            '⑹' => '6', '⑺' => '7', '⑻' => '8', '⑼' => '9', '⑽' => '10',
            
            // 常见谐音/拼音映射（示例，可根据需要扩展）
            '2' => '2|to|too|two',
            '4' => '4|for|four',
            
            // 特殊替换字符
            '.' => '',
            '。' => '',
            ',' => '',
            '，' => '',
            '!' => '',
            '！' => '',
            '?' => '',
            '？' => '',
            '@' => '',
            '#' => '',
            '$' => '',
            '%' => '',
            '^' => '',
            '&' => '',
            '*' => '',
            '(' => '',
            ')' => '',
            '-' => '',
            '_' => '',
            '+' => '',
            '=' => '',
            '[' => '',
            ']' => '',
            '{' => '',
            '}' => '',
            '|' => '',
            '\\' => '',
            '/' => '',
            ':' => '',
            ';' => '',
            '"' => '',
            '\'' => '',
            '<' => '',
            '>' => '',
            '`' => '',
            '~' => '',
        ];
    }

    /**
     * 多字节安全的字符串替换
     * @param string $string 原始字符串
     * @param string $replacement 替换内容
     * @param int $start 开始位置
     * @param int|null $length 替换长度，如果为null，则替换到字符串末尾
     * @param string|null $encoding 字符编码
     * @return string 替换后的字符串
     */
    protected static function mb_substr_replace($string, $replacement, $start, $length = null, $encoding = null)
    {
        if ($encoding === null) {
            $encoding = mb_internal_encoding();
        }
        if ($length === null) {
            return mb_substr($string, 0, $start, $encoding) . $replacement;
        }
        return mb_substr($string, 0, $start, $encoding) . $replacement . mb_substr($string, $start + $length, mb_strlen($string, $encoding) - ($start + $length), $encoding);
    }

    /**
     * 根据匹配类型对齐通过前缀索引找到的词语列表
     * 用于处理从前缀索引初步筛选出的敏感词列表，确保其符合指定的匹配规则（最小匹配或最大匹配），
     * 并排除重叠的词语，其行为应与DFA主扫描路径的匹配逻辑一致。
     * @param array $wordsList 包含词语详细信息（offset, len, word）的数组。
     * @param int   $matchType 匹配类型（0 表示最大匹配，1 表示最小匹配）。
     * @return array 对齐和过滤后的词语详细信息数组。
     */
    protected function _alignPrefixIndexWords(array $wordsList, int $matchType): array
    {
        usort($wordsList, function($a, $b) use ($matchType) {
            if ($a['offset'] !== $b['offset']) {
                return $a['offset'] - $b['offset'];
            }
            return ($matchType === 1) ? ($a['len'] - $b['len']) : ($b['len'] - $a['len']);
        });

        $alignedWords = [];
        $lastCoveredPosition = -1;
        foreach ($wordsList as $word) {
            if ($word['offset'] >= $lastCoveredPosition) {
                $alignedWords[] = $word;
                $lastCoveredPosition = $word['offset'] + $word['len'];
            }
        }
        return $alignedWords;
    }

     /**
     * 从DFA树中提取所有敏感词（当前缀索引不可用时的备用方案）
     * @return array 所有敏感词的数组
     */
    protected function extractWordsFromTree(): array
    {
        if ($this->wordTree === null || $this->wordTree->isEmpty()) {
            return [];
        }
        
        $words = [];
        $this->traverseTree($this->wordTree, '', $words);
        return array_values(array_unique($words));
    }

    /**
     * 递归遍历DFA树，提取所有完整的敏感词
     * @param HashMap $node 当前节点
     * @param string $currentWord 当前构建的词语
     * @param array &$words 结果数组（引用传递）
     */
    protected function traverseTree(HashMap $node, string $currentWord, array &$words): void
    {
        // 检查当前节点是否是一个完整词语的结尾
        if ($node->get('ending') === true) {
            $words[] = $currentWord;
        }
        
        // 遍历所有子节点
        $keys = $node->keys();
        foreach ($keys as $key) {
            // 跳过特殊标记键
            if ($key === 'ending') {
                continue;
            }
            
            $childNode = $node->get($key);
            if ($childNode instanceof HashMap) {
                $this->traverseTree($childNode, $currentWord . $key, $words);
            }
        }
    }



}