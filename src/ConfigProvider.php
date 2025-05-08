<?php

declare(strict_types=1);

namespace SensitiveWords;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                // 注册依赖关系
                SensitiveWordsManager::class => SensitiveWordsManager::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                    'collectors' => [
                        // 注解收集器
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for sensitive words component.',
                    'source' => __DIR__ . '/../publish/sensitive_words.php',
                    'destination' => BASE_PATH . '/config/autoload/sensitive_words.php',
                ],
                [
                    'id' => 'default-word-library',
                    'description' => 'The default sensitive word library.',
                    'source' => __DIR__ . '/../data/sensitive_words.txt',
                    'destination' => BASE_PATH . '/data/sensitive_words.txt',
                ],
                [
                    'id' => 'variant-map',
                    'description' => 'The variant text mapping table.',
                    'source' => __DIR__ . '/../data/variant_map.php',
                    'destination' => BASE_PATH . '/data/variant_map.php',
                ],
            ],
        ];
    }
} 