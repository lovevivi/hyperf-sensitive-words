<?php

namespace SensitiveWords\Helpers;

use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use SensitiveWords\Helpers\Exceptions\SensitiveWordException;

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
     * 词库是否已加载
     *
     * @var bool
     */
    protected $isLibraryLoaded = false;
    
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
     * 
     * @throws SensitiveWordException
     */
    protected function initWordLibrary()
    {
        // 如果词库已加载，则直接返回
        if ($this->isLibraryLoaded && $this->wordTree !== null) {
            return;
        }
        
        // 尝试从缓存加载
        if ($this->enableCache && $this->loadFromCache()) {
            $this->isLibraryLoaded = true;
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
        if ($this->enableCache && $this->wordTree !== null) {
            $this->saveToCache();
        }
        
        $this->isLibraryLoaded = true;
    }
    
    /**
     * 从缓存加载词库树
     *
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
            
            // 从缓存加载词库树和前缀索引
            $this->wordTree = $cacheArray['wordTree'] ?? null;
            $this->prefixIndex = $cacheArray['prefixIndex'] ?? [];
            
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
     *
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
                'prefixIndex' => $this->prefixIndex
            ]);
            return file_put_contents($this->cacheFilePath, $cacheData) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 预热词库（手动触发）
     *
     * @return bool 预热是否成功
     */
    public function warmup(): bool
    {
        try {
            $this->isLibraryLoaded = false; // 强制重新加载
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
     *
     * @param string $filepath
     *
     * @return $this
     * @throws \SensitiveWords\Helpers\Exceptions\SensitiveWordException
     */
    public function setTreeByFile($filepath = '')
    {
        if (!file_exists($filepath)) {
            throw new SensitiveWordException('词库文件不存在', SensitiveWordException::CANNOT_FIND_FILE);
        }

        // 词库树初始化
        $this->wordTree = $this->wordTree ?: new HashMap();
        
        // 清空并重建前缀索引
        $this->prefixIndex = [];
        $this->enablePrefixIndex = $this->config->get('sensitive_words.enable_prefix_index', true);

        foreach ($this->yieldToReadFile($filepath) as $word) {
            $trimmedWord = trim($word);
            if (empty($trimmedWord)) {
                continue;
            }
            
            $this->buildWordToTree($trimmedWord);
            
            // 如果启用了前缀索引，则构建索引
            if ($this->enablePrefixIndex) {
                $prefix = mb_substr($trimmedWord, 0, 1, 'utf-8');
                if (!isset($this->prefixIndex[$prefix])) {
                    $this->prefixIndex[$prefix] = [];
                }
                $this->prefixIndex[$prefix][] = $trimmedWord;
            }
        }
        
        // 更新词库后更新缓存
        if ($this->enableCache) {
            $this->saveToCache();
        }

        return $this;
    }

    /**
     * 构建敏感词树【数组模式】
     *
     * @param null $sensitiveWords
     *
     * @return $this
     * @throws \SensitiveWords\Helpers\Exceptions\SensitiveWordException
     */
    public function setTree($sensitiveWords = null)
    {
        if (empty($sensitiveWords)) {
            throw new SensitiveWordException('词库不能为空', SensitiveWordException::EMPTY_WORD_POOL);
        }

        $this->wordTree = new HashMap();
        
        // 清空并重建前缀索引
        $this->prefixIndex = [];
        $this->enablePrefixIndex = $this->config->get('sensitive_words.enable_prefix_index', true);

        foreach ($sensitiveWords as $word) {
            $this->buildWordToTree($word);
            
            // 如果启用了前缀索引，则构建索引
            if ($this->enablePrefixIndex && !empty($word)) {
                $prefix = mb_substr($word, 0, 1, 'utf-8');
                if (!isset($this->prefixIndex[$prefix])) {
                    $this->prefixIndex[$prefix] = [];
                }
                $this->prefixIndex[$prefix][] = $word;
            }
        }
        
        // 更新词库后更新缓存
        if ($this->enableCache) {
            $this->saveToCache();
        }
        
        return $this;
    }

    /**
     * 检测文字中的敏感词
     *
     * @param string   $content    待检测内容
     * @param int      $matchType  匹配类型 [默认为最小匹配规则]
     * @param int      $wordNum    需要获取的敏感词数量 [默认获取全部]
     * @return array
     * @throws \SensitiveWords\Helpers\Exceptions\SensitiveWordException
     */
    public function getBadWord($content, $matchType = 1, $wordNum = 0)
    {
        // 确保词库已加载
        if (!$this->isLibraryLoaded || $this->wordTree === null) {
            $this->initWordLibrary();
        }
        
        if ($this->wordTree === null) {
            throw new SensitiveWordException('词库树未初始化', SensitiveWordException::SYSTEM_ERROR);
        }
        
        // 对内容进行预处理
        $processedContent = $this->preprocessContent($content);
        $this->contentLength = mb_strlen($processedContent, 'utf-8');
        $badWordList = array();
        
        // 如果启用了前缀索引且内容长度适中，则先进行前缀过滤
        if ($this->enablePrefixIndex && count($this->prefixIndex) > 0 && $this->contentLength > 0 && $this->contentLength < 10000) {
            // 提取内容中的所有字符做为可能的前缀
            $possiblePrefixes = [];
            for ($i = 0; $i < $this->contentLength; $i++) {
                $char = mb_substr($processedContent, $i, 1, 'utf-8');
                $possiblePrefixes[$char] = true;
            }
            
            // 对可能命中的前缀进行精确检测
            foreach (array_keys($possiblePrefixes) as $prefix) {
                if (isset($this->prefixIndex[$prefix])) {
                    // 针对这个前缀的词进行检测
                    $result = $this->checkWordsWithPrefix($processedContent, $prefix, $matchType, $wordNum);
                    if (!empty($result)) {
                        $badWordList = array_merge($badWordList, $result);
                        
                        // 如果有数量限制且已达到，则提前返回
                        if ($wordNum > 0 && count($badWordList) >= $wordNum) {
                            return array_slice($badWordList, 0, $wordNum);
                        }
                    }
                }
            }
            
            return $badWordList;
        }
        
        // 如果没有启用前缀索引或内容过长，则使用原有的完整检测
        for ($length = 0; $length < $this->contentLength; $length++) {
            $matchFlag = 0;
            $flag = false;
            $tempMap = $this->wordTree;
            for ($i = $length; $i < $this->contentLength; $i++) {
                $keyChar = mb_substr($processedContent, $i, 1, 'utf-8');

                // 获取指定节点树
                $nowMap = $tempMap->get($keyChar);

                // 不存在节点树，直接返回
                if (empty($nowMap)) {
                    break;
                }

                // 存在，则判断是否为最后一个
                $tempMap = $nowMap;

                // 找到相应key，偏移量+1
                $matchFlag++;

                // 如果为最后一个匹配规则,结束循环，返回匹配标识数
                if (false === $nowMap->get('ending')) {
                    continue;
                }

                $flag = true;

                // 最小规则，直接退出
                if (1 === $matchType)  {
                    break;
                }
            }

            if (! $flag) {
                $matchFlag = 0;
            }

            // 找到相应key
            if ($matchFlag <= 0) {
                continue;
            }

            $badWordList[] = mb_substr($processedContent, $length, $matchFlag, 'utf-8');

            // 有返回数量限制
            if ($wordNum > 0 && count($badWordList) == $wordNum) {
                return $badWordList;
            }

            // 需匹配内容标志位往后移
            $length = $length + $matchFlag - 1;
        }
        return $badWordList;
    }

    /**
     * 替换敏感字字符
     *
     * @param        $content      文本内容
     * @param string $replaceChar  替换字符
     * @param bool   $repeat       true=>重复替换为敏感词相同长度的字符
     * @param int    $matchType
     *
     * @return mixed
     * @throws \SensitiveWords\Helpers\Exceptions\SensitiveWordException
     */
    public function replace($content, $replaceChar = '', $repeat = false, $matchType = 1)
    {
        if (empty($content)) {
            throw new SensitiveWordException('请填写检测的内容', SensitiveWordException::EMPTY_CONTENT);
        }
        
        // 确保词库已加载
        if (!$this->isLibraryLoaded || $this->wordTree === null) {
            $this->initWordLibrary();
        }
        
        // 对内容进行预处理
        $processedContent = $this->preprocessContent($content);
        
        $badWordList = self::$badWordList ? self::$badWordList : $this->getBadWord($processedContent, $matchType);
        
        // 未检测到敏感词，直接返回原始内容
        if (empty($badWordList)) {
            return $content;
        }
        
        // 在原始内容中替换敏感词
        $result = $content;
        foreach ($badWordList as $badWord) {
            $hasReplacedChar = $replaceChar;
            if ($repeat) {
                $hasReplacedChar = $this->dfaBadWordConversChars($badWord, $replaceChar);
            }
            $result = str_replace($badWord, $hasReplacedChar, $result);
        }
        
        return $result;
    }

    /**
     * 标记敏感词
     *
     * @param        $content    文本内容
     * @param string $sTag       标签开头，如<mark>
     * @param string $eTag       标签结束，如</mark>
     * @param int    $matchType
     *
     * @return mixed
     * @throws \SensitiveWords\Helpers\Exceptions\SensitiveWordException
     */
    public function mark($content, $sTag, $eTag, $matchType = 1)
    {
        if (empty($content)) {
            throw new SensitiveWordException('请填写检测的内容', SensitiveWordException::EMPTY_CONTENT);
        }
        
        // 确保词库已加载
        if (!$this->isLibraryLoaded || $this->wordTree === null) {
            $this->initWordLibrary();
        }
        
        // 对内容进行预处理
        $processedContent = $this->preprocessContent($content);
        
        $badWordList = self::$badWordList ? self::$badWordList : $this->getBadWord($processedContent, $matchType);
        
        // 未检测到敏感词，直接返回原始内容
        if (empty($badWordList)) {
            return $content;
        }
        
        $badWordList = array_unique($badWordList);
        
        // 在原始内容中标记敏感词
        $result = $content;
        foreach ($badWordList as $badWord) {
            $replaceChar = $sTag . $badWord . $eTag;
            $result = str_replace($badWord, $replaceChar, $result);
        }
        
        return $result;
    }

    /**
     * 被检测内容是否合法
     *
     * @param $content
     *
     * @return bool
     * @throws \SensitiveWords\Helpers\Exceptions\SensitiveWordException
     */
    public function islegal($content)
    {
        // 确保词库已加载
        if (!$this->isLibraryLoaded || $this->wordTree === null) {
            $this->initWordLibrary();
        }
        
        if ($this->wordTree === null) {
            throw new SensitiveWordException('词库树未初始化', SensitiveWordException::SYSTEM_ERROR);
        }
        
        // 对内容进行预处理
        $processedContent = $this->preprocessContent($content);
        
        $this->contentLength = mb_strlen($processedContent, 'utf-8');

        for ($length = 0; $length < $this->contentLength; $length++) {
            $matchFlag = 0;

            $tempMap = $this->wordTree;
            for ($i = $length; $i < $this->contentLength; $i++) {
                $keyChar = mb_substr($processedContent, $i, 1, 'utf-8');

                // 获取指定节点树
                $nowMap = $tempMap->get($keyChar);

                // 不存在节点树，直接返回
                if (empty($nowMap)) {
                    break;
                }

                // 找到相应key，偏移量+1
                $tempMap = $nowMap;
                $matchFlag++;

                // 如果为最后一个匹配规则,结束循环，返回匹配标识数
                if (false === $nowMap->get('ending')) {
                    continue;
                }

                return false;
            }

            // 找到相应key
            if ($matchFlag <= 0) {
                continue;
            }

            // 需匹配内容标志位往后移
            $length = $length + $matchFlag - 1;
        }
        return true;
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
     *
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
     * 添加针对特定前缀词库的检测方法
     *
     * @param string $content
     * @param string $prefix
     * @param int    $matchType
     * @param int    $wordNum
     *
     * @return array
     */
    protected function checkWordsWithPrefix($content, $prefix, $matchType = 1, $wordNum = 0)
    {
        if (!isset($this->prefixIndex[$prefix]) || empty($this->prefixIndex[$prefix])) {
            return [];
        }
        
        $badWordList = [];
        
        // 从前缀索引中获取待检查的敏感词列表
        $wordsToCheck = $this->prefixIndex[$prefix];
        
        // 对每个敏感词进行检查
        foreach ($wordsToCheck as $word) {
            $wordLength = mb_strlen($word, 'utf-8');
            
            // 如果敏感词长度大于内容长度，则跳过
            if ($wordLength > $this->contentLength) {
                continue;
            }
            
            // 在内容中查找敏感词
            $pos = 0;
            while (($pos = mb_strpos($content, $word, $pos, 'utf-8')) !== false) {
                $badWordList[] = $word;
                $pos += $wordLength;
                
                // 如果有数量限制且已达到，则提前返回
                if ($wordNum > 0 && count($badWordList) >= $wordNum) {
                    return $badWordList;
                }
                
                // 如果是最小匹配模式，找到一个就可以了
                if ($matchType === 1) {
                    break;
                }
            }
        }
        
        return $badWordList;
    }

    /**
     * 添加增量更新词库的方法
     *
     * @param array $words
     *
     * @return bool
     */
    public function addWords(array $words): bool
    {
        if (empty($words)) {
            return false;
        }
        
        // 确保词库已初始化
        if ($this->wordTree === null) {
            $this->wordTree = new HashMap();
        }
        
        $this->enablePrefixIndex = $this->config->get('sensitive_words.enable_prefix_index', true);
        
        foreach ($words as $word) {
            if (empty($word)) {
                continue;
            }
            
            $this->buildWordToTree($word);
            
            // 如果启用了前缀索引，则更新索引
            if ($this->enablePrefixIndex) {
                $prefix = mb_substr($word, 0, 1, 'utf-8');
                if (!isset($this->prefixIndex[$prefix])) {
                    $this->prefixIndex[$prefix] = [];
                }
                if (!in_array($word, $this->prefixIndex[$prefix])) {
                    $this->prefixIndex[$prefix][] = $word;
                }
            }
        }
        
        // 更新词库后更新缓存
        if ($this->enableCache) {
            $this->saveToCache();
        }
        
        return true;
    }

    /**
     * 内容预处理，处理表情符号和特殊字符
     * 
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
}