# Hyperf 敏感词过滤组件

基于确定有穷自动机算法(DFA)的敏感词过滤 Hyperf 组件，高效、灵活，支持协程环境。支持表情符号处理和变形文本检测。

## 安装

```bash
composer require kkguan/sensitive-words
```

## 发布配置

```bash
php bin/hyperf.php vendor:publish kkguan/sensitive-words
```

## 配置

配置文件位于 `config/autoload/sensitive_words.php`：

```php
return [
    // 用户自定义敏感词库路径，留空则使用默认词库
    'word_path' => '',
    
    // 词库合并模式：override-覆盖模式，append-追加模式
    'merge_mode' => 'append',
    
    // 是否开启中间件自动过滤
    'middleware_enable' => false,
    
    // 替换字符
    'replace_char' => '*',
    
    // 是否重复替换字符
    'repeat_char' => true,
    
    // 处理哪些HTTP请求参数
    'http_params' => ['content', 'text', 'message'],
    
    // 是否启用词库缓存
    'enable_cache' => true,
    
    // 缓存过期时间（秒），默认86400秒（1天）
    'cache_expire' => 86400,
    
    // 缓存文件存放路径，留空则按优先级决定
    'cache_path' => '',
    
    // 是否在应用启动时预热词库
    'preload' => false,
    
    // 是否启用前缀索引加速
    'enable_prefix_index' => true,
    
    // 表情符号处理策略 (ignore, remove, replace, include)
    'emoji_strategy' => 'remove',
    
    // 表情符号替换占位符（当emoji_strategy为replace时有效）
    'emoji_placeholder' => '[表情]',
    
    // 是否启用变形文本检测（如拼音、特殊字符分隔等）
    'detect_variant_text' => true,
    
    // 变形文本映射表路径（自定义映射表，留空使用内置映射）
    'variant_map_path' => '',
];
```

## 使用方式

### 1. 使用服务方式

```php
<?php

namespace App\Service;
    
use SensitiveWords\SensitiveWordsManager;

class ContentService
{
    /**
     * @var SensitiveWordsManager
     */
    protected $sensitiveWordsManager;
    
    public function __construct(SensitiveWordsManager $sensitiveWordsManager)
    {
        $this->sensitiveWordsManager = $sensitiveWordsManager;
    }
    
    public function filterContent(string $content): string
    {
        // 检查内容是否包含敏感词
        if (!$this->sensitiveWordsManager->check($content)) {
            // 替换敏感词
            $content = $this->sensitiveWordsManager->replace($content);

            // 或者获取所有敏感词
            $badWords = $this->sensitiveWordsManager->getBadWords($content);
        }
        
        return $content;
    }
}
```

### 2. 使用注解方式

```php
<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use SensitiveWords\Annotation\SensitiveCheck;

/**
 * @Controller
 */
class ContentController
{
    /**
     * @RequestMapping(path="post", methods="post")
     * @SensitiveCheck(param="content", replace=true, replaceChar="*")
     */
    public function post(string $content)
    {
        // $content已自动过滤敏感词
        return ['content' => $content];
    }
}
```

### 3. 使用中间件方式

在 `config/autoload/middlewares.php` 中添加：

```php
return [
    'http' => [
        SensitiveWords\Middleware\SensitiveWordsMiddleware::class,
    ],
];
```

同时在配置文件中设置 `middleware_enable` 为 `true` 可以启用中间件自动过滤功能。

### 4. 管理敏感词库

```php
// 预热词库（在应用启动时调用，提高首次访问性能）
$container->get(SensitiveWordsManager::class)->warmup();

// 清除词库缓存
$container->get(SensitiveWordsManager::class)->clearCache();

// 增量添加敏感词
$container->get(SensitiveWordsManager::class)->addWords(['新词1', '新词2']);
```

### 5. 表情符号与变形文本处理

```php
// 设置表情符号处理策略
$manager->setEmojiStrategy('remove'); // 可选：ignore, remove, replace, include

// 如果选择replace策略，可以自定义替换占位符
$manager->setEmojiStrategy('replace', '[表情符号]');

// 启用或禁用变形文本检测
$manager->enableVariantTextDetection(true);

// 设置自定义变形文本映射表
$manager->setVariantMapPath('/path/to/custom/variant_map.php');
```

## API 参考

### SensitiveWordsManager 类

#### 基础功能
* `check(string $content): bool` - 检查内容是否包含敏感词
* `replace(string $content, string $replaceChar = '*', bool $repeat = true): string` - 替换内容中的敏感词
* `getBadWords(string $content, int $wordNum = 0): array` - 获取内容中的敏感词列表
* `mark(string $content, string $sTag = '<span style="color:red">', string $eTag = '</span>'): string` - 标记内容中的敏感词

#### 词库管理
* `updateWordLibrary(array $words = []): bool` - 更新词库
* `addWords(array $words): bool` - 增量添加敏感词
* `warmup(): bool` - 预热词库，提高首次访问性能
* `clearCache(): bool` - 清除词库缓存，在词库更新后调用

#### 高级设置
* `setEmojiStrategy(string $strategy, string $placeholder = '[表情]'): bool` - 设置表情符号处理策略
* `enableVariantTextDetection(bool $enable = true): bool` - 启用或禁用变形文本检测
* `setVariantMapPath(string $path): bool` - 设置变形文本映射表路径

## 高级功能

### 表情符号处理

组件支持多种表情符号处理策略：
* `ignore`: 忽略表情符号(默认)
* `remove`: 移除所有表情符号后再检测
* `replace`: 将表情符号替换为占位符再检测
* `include`: 将表情符号作为正常字符处理

### 变形文本检测

能够识别常见的变形规避手段：
* 全角/半角转换（如"Ａｐｐｌｅ"→"Apple"）
* 特殊符号分隔（如"敏.感.词"→"敏感词"）
* 数字与汉字混用（如"全①国"→"全1国"/"全一国"）
* 中英文混合（如"wo爱中国"→"我爱中国"）

### 缓存与预热机制

* 支持词库缓存，大幅提升加载速度
* 三级缓存路径：用户配置、框架缓存目录、系统临时目录
* 支持自动预热，避免首次请求的性能损耗

### 前缀索引加速

针对中等长度文本的匹配算法优化：
* 按首字符索引敏感词，预先过滤不可能匹配的词
* 减少大型词库的检查次数，提高检测效率

## 性能优化

* 基于DFA算法的高效词库树结构
* 前缀索引加速，减少检测过程中的比较次数
* 词库文件懒加载与缓存机制，避免频繁IO操作
* 支持协程环境下的高并发处理
* 灵活的降级策略，保证组件在各种环境下可用
* 异常安全，敏感词处理异常不会影响业务流程

## 许可证

MIT
