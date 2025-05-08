<?php

declare(strict_types=1);

return [
    // 用户自定义敏感词库路径，留空则使用默认词库
    'word_path' => '',
    
    // 词库合并模式：
    // - override: 覆盖模式，仅使用用户词库，忽略默认词库
    // - append: 追加模式，同时使用默认词库和用户词库，并合并去重
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
    
    // 缓存文件存放路径，留空则按以下优先级决定：
    // 1. 如果使用Hyperf框架，则使用 BASE_PATH . '/runtime/cache'
    // 2. 否则使用系统临时目录下的 sensitive-words 目录
    'cache_path' => '',
    
    // 是否在应用启动时预热词库（自动加载并缓存词库）
    'preload' => false,
    
    // 是否启用前缀索引加速（通过首字符索引敏感词，提高检测效率）
    'enable_prefix_index' => true,
    
    // 表情符号处理策略
    // - ignore: 忽略表情符号(默认)
    // - remove: 移除所有表情符号后再检测
    // - replace: 将表情符号替换为占位符再检测
    // - include: 将表情符号作为正常字符处理
    'emoji_strategy' => 'remove',
    
    // 表情符号替换为的占位符（当emoji_strategy为replace时有效）
    'emoji_placeholder' => '[表情]',
    
    // 是否启用变形文本检测（如拼音、特殊字符分隔等）
    'detect_variant_text' => true,
    
    // 变形文本映射表路径（自定义映射表，留空使用内置映射）
    'variant_map_path' => '',
]; 