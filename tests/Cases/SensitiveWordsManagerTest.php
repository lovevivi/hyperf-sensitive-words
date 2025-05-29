<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use SensitiveWords\Helpers\SensitiveHelper;
use SensitiveWords\SensitiveWordsManager;
use Hyperf\Config\Config;

class SensitiveWordsManagerTest extends AbstractTestCase
{
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

    public function testCheck()
    {
        $manager = $this->createManager(['敏感词', '违禁内容', 'badword']);
        
        // 测试包含敏感词的内容
        $this->assertTrue($manager->check('这是敏感词测试'), '应该检测到敏感词');
        $this->assertTrue($manager->check('包含违禁内容的文本'), '应该检测到违禁内容');
        $this->assertTrue($manager->check('This is a badword test'), '应该检测到英文敏感词');
        
        // 测试不包含敏感词的内容
        $this->assertFalse($manager->check('这是正常的文本内容'), '正常内容不应被检测为敏感');
        $this->assertFalse($manager->check('This is a normal text'), '正常英文内容不应被检测为敏感');
        
        // 测试空内容
        $this->assertFalse($manager->check(''), '空内容不应被检测为敏感');
    }

    public function testReplace()
    {
        $manager = $this->createManager(['敏感词', '违禁', 'bad']);
        
        // 测试基本替换
        $this->assertEquals('这是***测试', $manager->replace('这是敏感词测试'));
        $this->assertEquals('包含**内容', $manager->replace('包含违禁内容'));
        $this->assertEquals('This is *** word', $manager->replace('This is bad word'));
        
        // 测试自定义替换字符
        $this->assertEquals('这是###测试', $manager->replace('这是敏感词测试', '#'));
        $this->assertEquals('包含XX内容', $manager->replace('包含违禁内容', 'X'));
        
        // 测试不重复替换
        $this->assertEquals('这是*测试', $manager->replace('这是敏感词测试', '*', false));
        $this->assertEquals('包含*内容', $manager->replace('包含违禁内容', '*', false));
        
        // 测试正常内容
        $normalText = '这是正常的文本内容';
        $this->assertEquals($normalText, $manager->replace($normalText), '正常内容不应被替换');
    }

    public function testGetBadWords()
    {
        $manager = $this->createManager(['敏感词', '违禁内容', '测试敏感', 'bad', 'word']);
        
        // 测试获取所有敏感词
        $badWords = $manager->getBadWords('这是敏感词和违禁内容的测试');
        $this->assertContains('敏感词', $badWords);
        $this->assertContains('违禁内容', $badWords);
        
        // 测试限制数量
        $badWords = $manager->getBadWords('这是敏感词和违禁内容的测试', 1);
        $this->assertCount(1, $badWords, '应该只返回1个敏感词');
        
        // 测试英文敏感词
        $badWords = $manager->getBadWords('This is a bad word test');
        $this->assertContains('bad', $badWords);
        $this->assertContains('word', $badWords);
        
        // 测试正常内容
        $badWords = $manager->getBadWords('这是正常的文本内容');
        $this->assertEmpty($badWords, '正常内容不应返回敏感词');
    }

    public function testMark()
    {
        $manager = $this->createManager(['敏感词', '违禁']);
        
        // 测试默认标记
        $result = $manager->mark('这是敏感词测试');
        $this->assertStringContainsString('<span style="color:red">敏感词</span>', $result);
        
        // 测试自定义标记
        $result = $manager->mark('包含违禁内容', '<mark>', '</mark>');
        $this->assertStringContainsString('<mark>违禁</mark>', $result);
        
        // 测试多个敏感词标记
        $result = $manager->mark('敏感词和违禁内容', '<b>', '</b>');
        $this->assertStringContainsString('<b>敏感词</b>', $result);
        $this->assertStringContainsString('<b>违禁</b>', $result);
        
        // 测试正常内容
        $normalText = '这是正常的文本内容';
        $this->assertEquals($normalText, $manager->mark($normalText), '正常内容不应被标记');
    }

    public function testSetWordLibrary()
    {
        $manager = $this->createManager();
        
        // 测试设置词库
        $this->assertTrue($manager->setWordLibrary(['新敏感词', '新违禁词']));
        
        // 验证新词库生效
        $this->assertTrue($manager->check('包含新敏感词的内容'));
        $this->assertTrue($manager->check('包含新违禁词的内容'));
        
        // 测试空词库
        $this->assertFalse($manager->setWordLibrary([]), '空词库应该返回false');
        
        // 测试覆盖词库
        $manager->setWordLibrary(['第一批词']);
        $this->assertTrue($manager->check('第一批词测试'));
        
        $manager->setWordLibrary(['第二批词']);
        $this->assertFalse($manager->check('第一批词测试'), '旧词库应该被覆盖');
        $this->assertTrue($manager->check('第二批词测试'), '新词库应该生效');
    }

    public function testAddWords()
    {
        $manager = $this->createManager(['初始敏感词']);
        
        // 测试添加新词
        $this->assertTrue($manager->addWords(['新增词1', '新增词2']));
        
        // 验证原有词库仍然有效
        $this->assertTrue($manager->check('包含初始敏感词的内容'));
        
        // 验证新增词生效
        $this->assertTrue($manager->check('包含新增词1的内容'));
        $this->assertTrue($manager->check('包含新增词2的内容'));
        
        // 测试空数组
        $this->assertFalse($manager->addWords([]), '空数组应该返回false');
        
        // 测试重复添加
        $this->assertTrue($manager->addWords(['新增词1', '另一个新词']), '重复词应该被忽略，但新词应该添加成功');
        $this->assertTrue($manager->check('包含另一个新词的内容'));
    }

    public function testWarmup()
    {
        $manager = $this->createManager();
        
        // 测试预热功能
        $this->assertTrue($manager->warmup(), '预热应该成功');
        
        // 预热后应该能正常工作
        $manager->setWordLibrary(['预热测试词']);
        $this->assertTrue($manager->check('包含预热测试词的内容'));
    }

    public function testClearCache()
    {
        $manager = $this->createManager(['缓存测试词']);
        
        // 测试清除缓存
        $this->assertTrue($manager->clearCache(), '清除缓存应该成功');
        
        // 清除缓存后功能应该仍然正常
        $this->assertTrue($manager->check('包含缓存测试词的内容'));
    }

    public function testSetEmojiStrategy()
    {
        $manager = $this->createManager();
        
        // 测试有效的表情策略
        $this->assertTrue($manager->setEmojiStrategy('ignore'));
        $this->assertTrue($manager->setEmojiStrategy('remove'));
        $this->assertTrue($manager->setEmojiStrategy('replace', '[emoji]'));
        $this->assertTrue($manager->setEmojiStrategy('include'));
        
        // 测试无效的表情策略
        $this->assertFalse($manager->setEmojiStrategy('invalid_strategy'));
        $this->assertFalse($manager->setEmojiStrategy(''));
    }

    public function testEnableVariantTextDetection()
    {
        $manager = $this->createManager();
        
        // 测试启用变形文本检测
        $this->assertTrue($manager->enableVariantTextDetection(true));
        $this->assertTrue($manager->enableVariantTextDetection(false));
        
        // 测试默认参数
        $this->assertTrue($manager->enableVariantTextDetection());
    }

    public function testSetVariantMapPath()
    {
        $manager = $this->createManager();
        
        // 测试不存在的文件路径
        $this->assertFalse($manager->setVariantMapPath('/path/to/nonexistent/file.php'));
        
        // 创建一个临时文件进行测试
        $tempFile = tempnam(sys_get_temp_dir(), 'variant_map_test');
        file_put_contents($tempFile, '<?php return ["ａ" => "a", "ｂ" => "b"];');
        
        // 测试存在的文件路径
        $this->assertTrue($manager->setVariantMapPath($tempFile));
        
        // 清理临时文件
        unlink($tempFile);
    }

    public function testComplexScenario()
    {
        // 测试复杂场景：多种功能组合使用
        $manager = $this->createManager(['敏感', '违禁']);
        
        // 1. 添加更多敏感词
        $manager->addWords(['新增敏感词', '另一个违禁词']);
        
        // 2. 设置表情处理策略
        $manager->setEmojiStrategy('remove');
        
        // 3. 启用变形文本检测
        $manager->enableVariantTextDetection(true);
        
        // 4. 测试复合文本
        $complexText = '这段文本包含敏感内容和新增敏感词，还有另一个违禁词';
        
        // 检测
        $this->assertTrue($manager->check($complexText));
        
        // 获取敏感词
        $badWords = $manager->getBadWords($complexText);
        $this->assertGreaterThan(0, count($badWords));
        
        // 替换
        $replacedText = $manager->replace($complexText, '*');
        $this->assertStringNotContainsString('敏感', $replacedText);
        $this->assertStringNotContainsString('违禁', $replacedText);
        
        // 标记
        $markedText = $manager->mark($complexText, '<mark>', '</mark>');
        $this->assertStringContainsString('<mark>', $markedText);
        $this->assertStringContainsString('</mark>', $markedText);
    }

    public function testEdgeCases()
    {
        $manager = $this->createManager(['测试']);
        
        // 测试边界情况
        
        // 1. 空字符串
        $this->assertFalse($manager->check(''));
        $this->assertEquals('', $manager->replace(''));
        $this->assertEmpty($manager->getBadWords(''));
        $this->assertEquals('', $manager->mark(''));
        
        // 2. 只有空格的字符串
        $this->assertFalse($manager->check('   '));
        
        // 3. 特殊字符
        $this->assertFalse($manager->check('!@#$%^&*()'));
        
        // 4. 数字
        $this->assertFalse($manager->check('1234567890'));
        
        // 5. 混合内容
        $mixedText = '123测试456';
        $this->assertTrue($manager->check($mixedText));
        $this->assertContains('测试', $manager->getBadWords($mixedText));
    }

    public function testPerformance()
    {
        // 创建大量敏感词进行性能测试
        $largeSensitiveWords = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeSensitiveWords[] = '敏感词' . $i;
        }
        
        $manager = $this->createManager($largeSensitiveWords);
        
        // 测试大文本处理
        $largeText = str_repeat('这是一段正常的文本内容，', 100) . '敏感词500';
        
        $startTime = microtime(true);
        $result = $manager->check($largeText);
        $endTime = microtime(true);
        
        $this->assertTrue($result);
        $this->assertLessThan(1.0, $endTime - $startTime, '性能测试：检测时间应该少于1秒');
        
        // 测试批量替换性能
        $startTime = microtime(true);
        $replacedText = $manager->replace($largeText);
        $endTime = microtime(true);
        
        $this->assertStringNotContainsString('敏感词500', $replacedText);
        $this->assertLessThan(1.0, $endTime - $startTime, '性能测试：替换时间应该少于1秒');
    }

    /**
     * 测试白名单管理功能
     */
    public function testWhitelistManagement()
    {
        $manager = $this->createManager();
        
        // 设置基础敏感词
        $manager->setWordLibrary(['敏感词', 'badword', 'test']);
        
        // 添加白名单
        $this->assertTrue($manager->addWhitelistWords(['test']));
        $this->assertTrue($manager->isWhitelisted('test'));
        
        // 检测应该被白名单过滤
        $badWords = $manager->getBadWords('this is test content');
        $this->assertNotContains('test', $badWords);
        
        // 获取白名单
        $whitelist = $manager->getWhitelistWords();
        $this->assertContains('test', $whitelist);
        
        // 删除白名单
        $this->assertTrue($manager->removeWhitelistWords(['test']));
        $this->assertFalse($manager->isWhitelisted('test'));
        
        // 现在应该能检测到
        $badWords2 = $manager->getBadWords('this is test content');
        $this->assertContains('test', $badWords2);
    }

    /**
     * 测试获取所有敏感词功能
     */
    public function testGetAllSensitiveWords()
    {
        $manager = $this->createManager();
        
        // 1. 空词库测试
        $allWords = $manager->getAllSensitiveWords();
        $this->assertIsArray($allWords);
        
        // 2. 设置词库并测试
        $testWords = ['敏感词1', '敏感词2', 'badword', 'test'];
        $manager->setWordLibrary($testWords);
        
        $allWords2 = $manager->getAllSensitiveWords();
        $this->assertIsArray($allWords2);
        $this->assertCount(count($testWords), $allWords2);
        
        foreach ($testWords as $word) {
            $this->assertContains($word, $allWords2, "敏感词 '{$word}' 未在返回列表中");
        }
        
        // 3. 动态添加词语后测试
        $manager->addWords(['新增词1', '新增词2']);
        $allWords3 = $manager->getAllSensitiveWords();
        
        $this->assertContains('新增词1', $allWords3);
        $this->assertContains('新增词2', $allWords3);
        $this->assertCount(count($testWords) + 2, $allWords3);
        
        // 4. 验证返回的词语确实是敏感词
        foreach ($allWords3 as $word) {
            $this->assertTrue($manager->check($word), "返回的词语 '{$word}' 应该被识别为敏感词");
        }
    }
} 