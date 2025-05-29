# Hyperf 敏感词过滤组件

基于确定有穷自动机算法(DFA)的敏感词过滤 Hyperf 组件，高效、灵活，支持协程环境。支持表情符号处理、变形文本检测和动态白名单管理。

## 特性亮点

- 🚀 **高性能DFA算法**：基于确定有穷自动机，检测效率高
- 🎯 **智能白名单系统**：支持动态白名单管理，避免误判
- 🔧 **灵活配置**：支持多种表情符号处理策略和变形文本检测
- 💾 **智能缓存**：三级缓存机制，大幅提升加载速度
- 🔍 **前缀索引加速**：针对中等长度文本的匹配优化
- 🌐 **协程友好**：完全支持 Hyperf 协程环境
- 📊 **详细位置信息**：支持返回敏感词的精确位置和长度
- 🛡️ **异常安全**：敏感词处理异常不会影响业务流程

## 安装

```bash
composer require lovevivi/hyperf-sensitive-words
```

## 发布配置

```bash
php bin/hyperf.php vendor:publish lovevivi/hyperf-sensitive-words
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
    
    // 白名单词库 - 新增功能
    // 这里的词语即使命中敏感词规则，也不会被处理
    'whitelist' => [
        'assessment',       // 示例: 防止 "ass" 被错误匹配
        'helloween',        // 示例: 防止 "hell" 被错误匹配
        '中华人民共和国',   // 示例: 整个词是合法的
        // 在这里添加您需要的白名单词
    ],
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
     * @RequestMapping(path="/sensitive/aspect-test", methods={"GET", "POST"})
     */
    public function test(RequestInterface $request)
    {
        // 从请求中获取内容
        $content = $request->input('string', '');
        
        // 调用助手方法，它将被切片处理
        $filteredContent = $this->filterContent($content);
        
        // 使用过滤后的内容构建响应
        return [
            'code' => 0,
            'message' => '成功',
            'data' => [
                'original_content' => $content,
                'processed_content' => $filteredContent
            ]
        ];
    }
    
    /**
     * 助手方法：过滤敏感词
     * 
     * @SensitiveCheck(param="content", replace=true, replaceChar="#")
     */
    protected function filterContent(string $content): string
    {
        // 切片会在方法执行前处理$content参数
        // 这里直接返回(可能已经被过滤的)内容
        return $content;
    }
}
```

### 3. 使用中间件方式

方式一：使用全局中间件，在 `config/autoload/middlewares.php` 中添加：

```php
return [
    'http' => [
        SensitiveWords\Middleware\SensitiveWordsMiddleware::class,
    ],
];
```
方式二：按需使用中间件：注解方式注入中间件

```php
use Hyperf\HttpServer\Annotation\Middleware;
use SensitiveWords\Middleware\SensitiveWordsMiddleware;

/**
 * @Middleware(SensitiveWordsMiddleware::class)
 */
class SensitiveMiddlewareTestController
```

同时在配置文件中设置 `middleware_enable` 为 `true` 可以启用中间件自动过滤功能。

### 4. 管理敏感词库

```php
// 预热词库（在应用启动时调用，提高首次访问性能）
$container->get(SensitiveWordsManager::class)->warmup();

// 清除词库缓存
$container->get(SensitiveWordsManager::class)->clearCache();

// 设置词库（覆盖现有词库）- 方法已重命名
$container->get(SensitiveWordsManager::class)->setWordLibrary(['新词1', '新词2']);

// 增量添加敏感词
$container->get(SensitiveWordsManager::class)->addWords(['新词1', '新词2']);
```

### 5. 动态白名单管理 - 新增功能

```php
$manager = $container->get(SensitiveWordsManager::class);

// 添加白名单词语
$manager->addWhitelistWords(['正常词汇', '合法内容']);

// 删除白名单词语
$manager->removeWhitelistWords(['不再需要的白名单']);

// 设置白名单（覆盖现有白名单）
$manager->setWhitelistWords(['新白名单1', '新白名单2']);

// 获取当前所有白名单词语
$whitelistWords = $manager->getWhitelistWords();

// 检查词语是否在白名单中
$isWhitelisted = $manager->isWhitelisted('某个词语');

// 清空所有白名单
$manager->clearWhitelist();
```

### 6. 获取所有敏感词列表

```php
$manager = $container->get(SensitiveWordsManager::class);

// 获取当前词库中的所有敏感词
$allSensitiveWords = $manager->getAllSensitiveWords();

// 示例输出：['敏感词1', '敏感词2', '违禁词', ...]
var_dump($allSensitiveWords);

// 可以用于词库管理、统计分析等场景
$wordCount = count($allSensitiveWords);
echo "当前词库包含 {$wordCount} 个敏感词";
```

### 7. 表情符号与变形文本处理

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

### 8. 获取详细位置信息 - 增强功能

```php
// 获取敏感词的详细位置信息
$badWordsDetails = $manager->getBadWords($content, 0, true); // 第三个参数为true时返回详细信息

// 返回格式：
// [
//     ['word' => '敏感词', 'offset' => 5, 'len' => 3],
//     ['word' => '违禁词', 'offset' => 12, 'len' => 3]
// ]
```

## API 参考

### SensitiveWordsManager 类

#### 基础功能
* `check(string $content): bool` - 检查内容是否包含敏感词
* `replace(string $content, string $replaceChar = '*', bool $repeat = true): string` - 替换内容中的敏感词
* `getBadWords(string $content, int $wordNum = 0, bool $returnDetails = false): array` - 获取内容中的敏感词列表，支持返回详细位置信息
* `mark(string $content, string $sTag = '<span style="color:red">', string $eTag = '</span>'): string` - 标记内容中的敏感词

#### 词库管理
* `setWordLibrary(array $words = []): bool` - 设置词库（覆盖现有词库）**[方法重命名]**
* `addWords(array $words): bool` - 增量添加敏感词
* `getAllSensitiveWords(): array` - 获取当前词库中的所有敏感词 
* `warmup(): bool` - 预热词库，提高首次访问性能
* `clearCache(): bool` - 清除词库缓存，在词库更新后调用

#### 白名单管理 **[新增功能]**
* `addWhitelistWords(array $words): bool` - 添加白名单词语
* `removeWhitelistWords(array $words): bool` - 删除白名单词语
* `setWhitelistWords(array $words): bool` - 设置白名单词语（覆盖现有白名单）
* `getWhitelistWords(): array` - 获取当前所有白名单词语
* `isWhitelisted(string $word): bool` - 检查词语是否在白名单中
* `clearWhitelist(): bool` - 清空所有白名单词语

#### 高级设置
* `setEmojiStrategy(string $strategy, string $placeholder = '[表情]'): bool` - 设置表情符号处理策略
* `enableVariantTextDetection(bool $enable = true): bool` - 启用或禁用变形文本检测
* `setVariantMapPath(string $path): bool` - 设置变形文本映射表路径

## 高级功能

### 智能白名单系统 

白名单系统支持两种过滤模式：

#### 1. 直接白名单
直接将词语加入白名单，该词语将不会被检测为敏感词：
```php
$manager->addWhitelistWords(['assessment', 'helloween']);
// "assessment" 和 "helloween" 不会被检测为敏感词
```

#### 2. 上下文白名单
当敏感词是更长白名单词语的一部分时，也会被过滤：
```php
// 敏感词库包含：['ass', 'hell']
// 白名单包含：['assessment', 'helloween']

$text = 'This is an assessment of the helloween party.';
// "ass" 和 "hell" 不会被检测，因为它们是白名单词语的一部分
```

#### 3. 动态管理
支持运行时动态添加、删除、查询白名单：
```php
// 运行时添加
$manager->addWhitelistWords(['新的合法词汇']);

// 检查是否在白名单中
if ($manager->isWhitelisted('某个词')) {
    // 处理逻辑
}

// 获取所有白名单
$allWhitelist = $manager->getWhitelistWords();
```

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
* **白名单也会被缓存**，确保性能一致性

### 前缀索引加速

针对中等长度文本的匹配算法优化：
* 按首字符索引敏感词，预先过滤不可能匹配的词
* 减少大型词库的检查次数，提高检测效率

### 详细位置信息 

支持返回敏感词的精确位置信息：
```php
$details = $manager->getBadWords($content, 0, true);
// 返回：[['word' => '敏感词', 'offset' => 5, 'len' => 3], ...]
```

## 性能优化

* 基于DFA算法的高效词库树结构
* 前缀索引加速，减少检测过程中的比较次数
* 词库文件懒加载与缓存机制，避免频繁IO操作
* 支持协程环境下的高并发处理
* 灵活的降级策略，保证组件在各种环境下可用
* 异常安全，敏感词处理异常不会影响业务流程
* **智能白名单缓存**，避免重复计算

## 版本更新说明

### 最新版本改进

#### 1. 白名单功能全面升级 
- 新增动态白名单管理API
- 支持上下文白名单过滤
- 白名单与缓存系统集成
- 配置文件支持白名单预设

#### 2. API方法重命名 
- `updateWordLibrary()` → `setWordLibrary()` - 更准确地反映方法功能

#### 3. 功能增强
- `getBadWords()` 方法支持返回详细位置信息
- `replace()` 和 `mark()` 方法基于精确位置进行操作
- 匹配算法一致性优化，确保前缀索引与DFA主循环行为统一
- 新增 `getAllSensitiveWords()` 方法，支持获取当前词库中的所有敏感词

#### 4. 测试覆盖完善
- 新增 `SensitiveWordsManagerTest.php` - 管理器功能全面测试
- 新增 `WhitelistManagementTest.php` - 白名单功能专项测试
- 52个测试用例，307个断言，确保功能稳定性

#### 5. 代码质量提升
- 重复代码优化，提取公共逻辑
- 中文注释完善，提高代码可读性
- 异常处理增强，提高系统健壮性

## 许可证

MIT

## 测试

本项目包含全面的单元测试，确保所有功能的正确性和稳定性。

### 测试结构

```
tests/
├── Cases/
│   ├── SensitiveHelperTest.php          # SensitiveHelper 核心功能测试
│   ├── SensitiveWordsManagerTest.php    # SensitiveWordsManager 管理器测试
│   └── WhitelistManagementTest.php      # 白名单功能专项测试
└── AbstractTestCase.php                 # 测试基类
```

### 测试覆盖范围

#### SensitiveHelperTest.php
- 白名单功能测试（上下文过滤、直接白名单等）
- addWords 增量添加功能测试
- getBadWord 详细模式测试（返回位置信息）
- replace 和 mark 方法测试（各种匹配模式）
- 变形文本处理测试
- 边界情况和异常处理测试
- **getAllSensitiveWords 功能测试** - 新增

#### SensitiveWordsManagerTest.php 
- 基础功能测试：check、replace、getBadWords、mark
- 词库管理测试：setWordLibrary、addWords、getAllSensitiveWords
- 系统功能测试：warmup、clearCache
- 配置管理测试：setEmojiStrategy、enableVariantTextDetection、setVariantMapPath
- 复杂场景测试：多功能组合使用
- 性能测试：大词库和大文本处理
- 边界情况测试：空内容、特殊字符等

#### WhitelistManagementTest.php 
- 动态白名单管理：addWhitelistWords、removeWhitelistWords
- 白名单设置：setWhitelistWords、clearWhitelist
- 白名单查询：getWhitelistWords、isWhitelisted
- 白名单与敏感词检测的交互测试
- 预处理对白名单的影响测试

### 运行测试

```bash
# 运行所有测试
composer test

# 或者使用 PHPUnit 直接运行
./vendor/bin/phpunit

# 运行特定测试文件
./vendor/bin/phpunit tests/Cases/SensitiveWordsManagerTest.php

# 运行特定测试方法
./vendor/bin/phpunit tests/Cases/SensitiveWordsManagerTest.php::testGetAllSensitiveWords

# 显示详细输出
./vendor/bin/phpunit --verbose

# 生成代码覆盖率报告（需要安装 xdebug）
./vendor/bin/phpunit --coverage-html coverage/
```

### 测试统计

- **总测试数**: 52个测试
- **总断言数**: 307个断言
- **测试覆盖**: 核心功能100%覆盖
- **测试类型**: 单元测试、集成测试、性能测试

### 添加新测试

如果你需要添加新的测试用例，请遵循以下规范：

1. **测试文件命名**: 使用 `*Test.php` 后缀
2. **测试方法命名**: 使用 `test*` 前缀，方法名要清晰描述测试内容
3. **测试分组**: 按功能模块分组，相关测试放在同一个测试类中
4. **断言使用**: 使用具体的断言方法，如 `assertStringContainsString` 而不是通用的 `assertTrue`
5. **测试数据**: 使用有意义的测试数据，包含中英文、特殊字符等各种情况

### 持续集成

项目配置了完整的测试流程，每次代码提交都会自动运行全部测试，确保代码质量。
