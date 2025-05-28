<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use SensitiveWords\Helpers\SensitiveHelper;
use SensitiveWords\SensitiveWordsManager;
use Hyperf\Config\Config;

class WhitelistManagementTest extends AbstractTestCase
{
    private function createHelper(array $sensitiveWords = [], array $whitelist = []): SensitiveHelper
    {
        $config = new Config([
            'sensitive_words' => [
                'word_path' => null, 
                'whitelist' => $whitelist,
                'enable_cache' => false, 
                'preload' => false,
                'emoji_strategy' => 'ignore',
                'detect_variant_text' => false,
            ]
        ]);
        $helper = new SensitiveHelper($config);
        if (!empty($sensitiveWords)) {
            $helper->setTree($sensitiveWords);
        }
        return $helper;
    }

    private function createManager(array $sensitiveWords = [], array $whitelist = []): SensitiveWordsManager
    {
        $config = new Config([
            'sensitive_words' => [
                'word_path' => null, 
                'whitelist' => $whitelist,
                'enable_cache' => false, 
                'preload' => false,
                'emoji_strategy' => 'ignore',
                'detect_variant_text' => false,
            ]
        ]);
        $helper = new SensitiveHelper($config);
        if (!empty($sensitiveWords)) {
            $helper->setTree($sensitiveWords);
        }
        return new SensitiveWordsManager($config, $helper);
    }

    public function testAddWhitelistWords()
    {
        $helper = $this->createHelper(['敏感词', '测试'], ['初始白名单']);
        
        // 测试添加新的白名单词语
        $this->assertTrue($helper->addWhitelistWords(['新白名单', '另一个白名单']));
        
        // 验证白名单是否生效
        $this->assertTrue($helper->isWhitelisted('新白名单'));
        $this->assertTrue($helper->isWhitelisted('另一个白名单'));
        $this->assertTrue($helper->isWhitelisted('初始白名单'));
        
        // 测试敏感词检测
        $this->assertEmpty($helper->getBadWord('这是新白名单内容'));
        $this->assertEmpty($helper->getBadWord('这是另一个白名单内容'));
        $this->assertNotEmpty($helper->getBadWord('这是敏感词内容'));
        
        // 测试添加空数组
        $this->assertFalse($helper->addWhitelistWords([]));
        
        // 测试添加重复词语
        $this->assertFalse($helper->addWhitelistWords(['新白名单'])); // 已存在，应返回false
    }

    public function testRemoveWhitelistWords()
    {
        $helper = $this->createHelper(['敏感词'], ['白名单1', '白名单2', '白名单3']);
        
        // 验证初始状态
        $this->assertTrue($helper->isWhitelisted('白名单1'));
        $this->assertTrue($helper->isWhitelisted('白名单2'));
        
        // 删除部分白名单词语
        $this->assertTrue($helper->removeWhitelistWords(['白名单1', '白名单2']));
        
        // 验证删除结果
        $this->assertFalse($helper->isWhitelisted('白名单1'));
        $this->assertFalse($helper->isWhitelisted('白名单2'));
        $this->assertTrue($helper->isWhitelisted('白名单3')); // 未删除的应该还在
        
        // 测试删除不存在的词语
        $this->assertFalse($helper->removeWhitelistWords(['不存在的词']));
        
        // 测试删除空数组
        $this->assertFalse($helper->removeWhitelistWords([]));
    }

    public function testClearWhitelist()
    {
        $helper = $this->createHelper(['敏感词'], ['白名单1', '白名单2']);
        
        // 验证初始状态
        $this->assertCount(2, $helper->getWhitelistWords());
        
        // 清空白名单
        $this->assertTrue($helper->clearWhitelist());
        
        // 验证清空结果
        $this->assertEmpty($helper->getWhitelistWords());
        $this->assertFalse($helper->isWhitelisted('白名单1'));
        
        // 再次清空应该返回false
        $this->assertFalse($helper->clearWhitelist());
    }

    public function testGetWhitelistWords()
    {
        $initialWhitelist = ['白名单1', '白名单2'];
        $helper = $this->createHelper(['敏感词'], $initialWhitelist);
        
        // 获取初始白名单
        $whitelist = $helper->getWhitelistWords();
        $this->assertCount(2, $whitelist);
        $this->assertContains('白名单1', $whitelist);
        $this->assertContains('白名单2', $whitelist);
        
        // 添加新词语后再次获取
        $helper->addWhitelistWords(['新白名单']);
        $whitelist = $helper->getWhitelistWords();
        $this->assertCount(3, $whitelist);
        $this->assertContains('新白名单', $whitelist);
    }

    public function testSetWhitelistWords()
    {
        $helper = $this->createHelper(['敏感词'], ['旧白名单1', '旧白名单2']);
        
        // 设置新的白名单（覆盖旧的）
        $newWhitelist = ['新白名单1', '新白名单2', '新白名单3'];
        $this->assertTrue($helper->setWhitelistWords($newWhitelist));
        
        // 验证旧白名单被清除
        $this->assertFalse($helper->isWhitelisted('旧白名单1'));
        $this->assertFalse($helper->isWhitelisted('旧白名单2'));
        
        // 验证新白名单生效
        $this->assertTrue($helper->isWhitelisted('新白名单1'));
        $this->assertTrue($helper->isWhitelisted('新白名单2'));
        $this->assertTrue($helper->isWhitelisted('新白名单3'));
        
        // 设置空白名单
        $this->assertTrue($helper->setWhitelistWords([]));
        $this->assertEmpty($helper->getWhitelistWords());
    }

    public function testIsWhitelisted()
    {
        $helper = $this->createHelper(['敏感词'], ['白名单词']);
        
        // 测试存在的白名单词
        $this->assertTrue($helper->isWhitelisted('白名单词'));
        
        // 测试不存在的词
        $this->assertFalse($helper->isWhitelisted('不存在的词'));
        $this->assertFalse($helper->isWhitelisted('敏感词')); // 敏感词不在白名单中
        
        // 测试空字符串
        $this->assertFalse($helper->isWhitelisted(''));
    }

    public function testManagerWhitelistMethods()
    {
        $manager = $this->createManager(['敏感词'], ['初始白名单']);
        
        // 测试Manager的白名单方法
        $this->assertTrue($manager->addWhitelistWords(['管理器白名单']));
        $this->assertTrue($manager->isWhitelisted('管理器白名单'));
        $this->assertTrue($manager->isWhitelisted('初始白名单'));
        
        $whitelist = $manager->getWhitelistWords();
        $this->assertContains('管理器白名单', $whitelist);
        $this->assertContains('初始白名单', $whitelist);
        
        $this->assertTrue($manager->removeWhitelistWords(['初始白名单']));
        $this->assertFalse($manager->isWhitelisted('初始白名单'));
        $this->assertTrue($manager->isWhitelisted('管理器白名单'));
        
        $this->assertTrue($manager->setWhitelistWords(['新设置的白名单']));
        $this->assertFalse($manager->isWhitelisted('管理器白名单'));
        $this->assertTrue($manager->isWhitelisted('新设置的白名单'));
        
        $this->assertTrue($manager->clearWhitelist());
        $this->assertEmpty($manager->getWhitelistWords());
    }

    public function testWhitelistWithSensitiveWordDetection()
    {
        $helper = $this->createHelper(['敏感', '敏感词', '测试敏感'], []);
        
        // 添加白名单，测试上下文过滤
        $helper->addWhitelistWords(['测试敏感词汇']);
        
        // 测试白名单上下文过滤
        $text1 = '这是测试敏感词汇的内容';
        $badWords1 = $helper->getBadWord($text1);
        $this->assertEmpty($badWords1, '白名单词汇应该被过滤掉');
        
        // 测试非白名单上下文 - 使用最大匹配模式来确保检测到完整的"敏感词"
        $text2 = '这是敏感词的内容';
        $badWords2 = $helper->getBadWord($text2, 0); // 使用最大匹配模式
        $this->assertNotEmpty($badWords2, '非白名单敏感词应该被检测到');
        $this->assertContains('敏感词', $badWords2);
        
        // 测试部分匹配 - 使用最小匹配模式
        $text3 = '单独的敏感内容';
        $badWords3 = $helper->getBadWord($text3, 1); // 使用最小匹配模式
        $this->assertContains('敏感', $badWords3, '部分敏感词应该被检测到');
    }

    public function testWhitelistPreprocessing()
    {
        // 测试白名单词语的预处理
        $config = new Config([
            'sensitive_words' => [
                'word_path' => null, 
                'whitelist' => [],
                'enable_cache' => false, 
                'preload' => false,
                'emoji_strategy' => 'remove',
                'detect_variant_text' => true,
            ]
        ]);
        $helper = new SensitiveHelper($config);
        $helper->setTree(['测试']);
        
        // 添加包含特殊字符的白名单
        $helper->addWhitelistWords(['测 试 词 汇']);
        
        // 验证预处理后的匹配
        $this->assertTrue($helper->isWhitelisted('测试词汇'), '预处理后的白名单应该匹配');
        
        // 测试敏感词检测
        $text = '这是测 试 词 汇的内容';
        $badWords = $helper->getBadWord($text);
        $this->assertEmpty($badWords, '预处理后的白名单应该生效');
    }
} 