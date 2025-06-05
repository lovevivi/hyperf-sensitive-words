<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use SensitiveWords\Helpers\SensitiveHelper;
use Hyperf\Config\Config;

class SensitiveHelperTest extends AbstractTestCase
{
    private function createHelper(array $sensitiveWords, array $whitelist = []): SensitiveHelper
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

    public function testWhitelistFunctionality()
    {
        $sensitiveWords = ['ass', 'hell', '敏感词'];
        $whitelist = ['assessment', 'helloween', '敏感'];

        $helper = $this->createHelper($sensitiveWords, $whitelist);

        // 1. 直接白名单测试
        $helperForDirectWhitelist = $this->createHelper(['apple', 'banana'], ['banana']);
        $this->assertTrue($helperForDirectWhitelist->islegal('I like banana a lot.'), '测试用例 1.1 失败: 直接白名单中的词语 "banana" 应该被认为是合法的。');
        $this->assertEmpty($helperForDirectWhitelist->getBadWord('I like banana a lot.'), '测试用例 1.2 失败: 不应返回直接白名单中的词语 "banana"。');

        // 2. 白名单词优先 (上下文测试)
        $textWithContext = 'This is an assessment of the situation.';
        $actualBadWords = $helper->getBadWord($textWithContext);
        $this->assertEmpty($actualBadWords, '测试用例 2.1 失败: "assessment" 中的 "ass" 因在白名单中，应被忽略。实际检测到: ' . implode(', ', $actualBadWords));
        $this->assertTrue($helper->islegal($textWithContext), '测试用例 2.2 失败: 包含白名单超串 "assessment" 的文本应被视为合法。');

        // 3. 正常敏感词测试
        $textWithRealSensitive = '这是一段包含敏感词的内容。';
        $actualBadWordsReal = $helper->getBadWord($textWithRealSensitive);
        $this->assertContains('敏感词', $actualBadWordsReal, '测试用例 3.1 失败: 应检测到 "敏感词"。');
        $this->assertFalse($helper->islegal($textWithRealSensitive), '测试用例 3.2 失败: 包含真实敏感词的文本应被视为非法。');

        // 4. 混合情况测试
        $mixedText = 'A spooky helloween night, but not hell itself.';
        $actualBadWordsMixed = $helper->getBadWord($mixedText);
        $this->assertNotContains('helloween', $actualBadWordsMixed, '测试用例 4.1 失败: "helloween" 应被白名单豁免。');
        $this->assertContains('hell', $actualBadWordsMixed, '测试用例 4.2 失败: 独立的 "hell" 应被检测到。实际检测到: ' . implode(', ', $actualBadWordsMixed));
        
        // 6. 另一个上下文例子 (中文)
        $helperComplex = $this->createHelper(['评价'], ['正面评价']);
        $textComplex = '这是一个正面评价。';
        $this->assertTrue($helperComplex->islegal($textComplex), '测试用例 6.1 失败: "正面评价" 中的 "评价" 上下文白名单判断失败。');
        $this->assertEmpty($helperComplex->getBadWord($textComplex), '测试用例 6.2 失败: "正面评价" 中的 "评价" 不应被检测为敏感词。');
        $textComplexSensitive = '这个评价很差。';
        $this->assertFalse($helperComplex->islegal($textComplexSensitive), '测试用例 6.3 失败: 单独的 "评价" 应为敏感词，文本应非法。');
        $actualBadWordsComplexSensitive = $helperComplex->getBadWord($textComplexSensitive);
        $this->assertContains('评价', $actualBadWordsComplexSensitive, '测试用例 6.4 失败: 应检测到单独的 "评价"。');
    }

    public function testSimplerContextIssue()
    {
        $helper = $this->createHelper(['hell'], ['helloween']);
        $text = 'hell helloween';
        $actualBadWords = $helper->getBadWord($text);
        $this->assertContains('hell', $actualBadWords, '简单测试失败：第一个 "hell" 应该被检测到。实际: ' . implode(',', $actualBadWords));
        $this->assertCount(1, $actualBadWords, '简单测试失败：应该只检测到一个 "hell"。实际数量: ' . count($actualBadWords));

        $text2 = 'helloween hell';
        $actualBadWords2 = $helper->getBadWord($text2);
        $this->assertContains('hell', $actualBadWords2, '简单测试失败2：第二个 "hell" 应该被检测到。实际: ' . implode(',', $actualBadWords2));
        $this->assertCount(1, $actualBadWords2, '简单测试失败2：应该只检测到一个 "hell"。实际数量: ' . count($actualBadWords2));
    }

    public function testAddWordsFunctionality()
    {
        // 1. 基本添加和检测
        $helper = $this->createHelper([], []); // 初始为空词库
        $this->assertTrue($helper->addWords(['testword1', 'añadido 傻z']), '添加词语失败');
        
        $actualBadWords1 = $helper->getBadWord('这是一个 testword1');
        $this->assertContains('testword1', $actualBadWords1, '基础添加后未能检测到 testword1');
        
        $actualBadWords2 = $helper->getBadWord('另一个 añadido 傻z 例子');

        $this->assertContains('añadido 傻z', $actualBadWords2, '基础添加后未能检测到 "añadido"');
        $this->assertFalse($helper->islegal('包含 testword1 的文本'), 'islegal 判断错误 testword1');

        // 2. 重复添加 (不应产生错误，词库应保持一致)
        $this->assertTrue($helper->addWords(['testword1', 'newword']), '重复/新增添词语失败');
        $actualBadWords3 = $helper->getBadWord('testword1 和 newword');
        $this->assertContains('testword1', $actualBadWords3, '重复添加 testword1 后检测失败');
        $this->assertContains('newword', $actualBadWords3, '添加 newword 后检测失败');
        $this->assertCount(2, array_unique($actualBadWords3), '重复添加后，检测到的敏感词数量不唯一或不正确');

        // 3. 与现有词库交互 (先 setTree，后 addWords)
        $helper2 = $this->createHelper(['initial'], []);
        $this->assertTrue($helper2->addWords(['added']), '在现有词库基础上添加词语失败');
        $actualBadWords4 = $helper2->getBadWord('initial text with added word');
        $this->assertContains('initial', $actualBadWords4, 'setTree后的词 initial 未检测到');
        $this->assertContains('added', $actualBadWords4, 'addWords添加的词 added 未检测到');

        // 4. 与白名单交互
        $helper3 = $this->createHelper([], ['helloween']);
        $this->assertTrue($helper3->addWords(['hellfire']), '添加 "hellfire" 失败');
        
        $actualBadWords5 = $helper3->getBadWord('A hellfire on helloween.');
        $this->assertContains('hellfire', $actualBadWords5, '"hellfire" 应被检测到');
        $this->assertNotContains('helloween', $actualBadWords5, '"helloween" 作为白名单不应被检测到');

        $helper4 = $this->createHelper([], ['exactmatch']);
        $this->assertTrue($helper4->addWords(['exactmatch', 'another']), '添加与白名单相同的词失败');
        $actualBadWords6 = $helper4->getBadWord('This is exactmatch and another.');
        $this->assertNotContains('exactmatch', $actualBadWords6, '通过addWords添加的、但同时也是白名单的词不应被检测');
        $this->assertContains('another', $actualBadWords6, '通过addWords添加的非白名单词 "another" 应被检测');

        // 5. 空数组或无效输入
        $helper5 = $this->createHelper(['existing'], []);
        $this->assertFalse($helper5->addWords([]), '向词库添加空数组应返回 false');
        $actualBadWordsInitial = $helper5->getBadWord('existing word test');
        $this->assertCount(1, $actualBadWordsInitial, '添加空数组后，原有词库不应改变');
        $this->assertContains('existing', $actualBadWordsInitial, '添加空数组后，原有词 "existing" 仍在');
        $this->assertTrue($helper5->addWords(['', 'newemptytest', '']), '添加包含空字符串的数组应成功处理非空部分');
        $actualBadWordsNewEmpty = $helper5->getBadWord('test newemptytest');
        $this->assertContains('newemptytest', $actualBadWordsNewEmpty, '空字符串不影响有效词的添加');

        // 6. 确保添加后，原有的词库和前缀索引得到正确更新
        $helper6 = $this->createHelper(['apple'], []);
        $this->assertTrue($helper6->addWords(['banana']));
        $actualBadWordsApple = $helper6->getBadWord('apple pie');
        $this->assertContains('apple', $actualBadWordsApple);
        
        $actualBadWordsBanana = $helper6->getBadWord('banana split');
        $this->assertContains('banana', $actualBadWordsBanana);
    }

    public function testGetBadWordWithDetails()
    {
        $helper = $this->createHelper(['赌博', '法轮功', '另一个词']);

        // 1. 测试基本返回结构和中文内容 (预处理影响较小的情况)
        $text1 = '这是一个关于赌博和法轮功的例子。';
        
        $details1 = $helper->getBadWord($text1, 1, 0, true);

        $this->assertIsArray($details1, '测试用例 1.1 (详细模式): 应返回一个数组。');
        $this->assertNotEmpty($details1, '测试用例 1.2 (详细模式): 应检测到敏感词。');
        
        usort($details1, function($a, $b) {
            return $a['offset'] - $b['offset'];
        });
        $this->assertCount(2, $details1, '测试用例 1.3 (详细模式): 应检测到2个敏感词。');

        // 预期值 (假设 preprocessContent 不改变这些特定中文词的 offset)
        $expectedWord1 = '赌博';
        $expectedOffset1 = mb_strpos($text1, $expectedWord1, 0, 'utf-8');
        $expectedLen1 = mb_strlen($expectedWord1, 'utf-8');

        $expectedWord2 = '法轮功';
        $expectedOffset2 = mb_strpos($text1, $expectedWord2, 0, 'utf-8');
        $expectedLen2 = mb_strlen($expectedWord2, 'utf-8');

        $foundItemsCount = 0;
        foreach ($details1 as $item) {
            $this->assertIsArray($item, '测试用例 1.4 (详细模式): 每个条目都应是数组。');
            $this->assertArrayHasKey('word', $item, '测试用例 1.5 (详细模式): 条目应包含 "word" 键。');
            $this->assertArrayHasKey('offset', $item, '测试用例 1.6 (详细模式): 条目应包含 "offset" 键。');
            $this->assertArrayHasKey('len', $item, '测试用例 1.7 (详细模式): 条目应包含 "len" 键。');

            if ($item['word'] === $expectedWord1) {
                $this->assertEquals($expectedOffset1, $item['offset'], "测试用例 1.8 (详细模式): '{$expectedWord1}' 的 offset 不正确。");
                $this->assertEquals($expectedLen1, $item['len'], "测试用例 1.9 (详细模式): '{$expectedWord1}' 的长度不正确。");
                $foundItemsCount++;
            } elseif ($item['word'] === $expectedWord2) {
                $this->assertEquals($expectedOffset2, $item['offset'], "测试用例 1.10 (详细模式): '{$expectedWord2}' 的 offset 不正确。");
                $this->assertEquals($expectedLen2, $item['len'], "测试用例 1.11 (详细模式): '{$expectedWord2}' 的长度不正确。");
                $foundItemsCount++;
            }
        }
        $this->assertEquals(2, $foundItemsCount, '测试用例 1.12 (详细模式): 未能找到所有预期的敏感词及其正确信息。');

        // 2. 测试 $wordNum 参数 (中文)
        $text2 = '赌博 法轮功 另一个词'; 
        $details2 = $helper->getBadWord($text2, 1, 2, true); // 获取前2个
        
        $this->assertCount(2, $details2, '测试用例 2.1 (详细模式): $wordNum=2 应返回2个条目。');
        // 预期 '赌博' 和 '法轮功' (已通过 usort 排序)
        $this->assertEquals('赌博', $details2[0]['word'], '测试用例 2.2 (详细模式): $wordNum=2 时第一个条目应为 "赌博".');
        $this->assertEquals(mb_strpos($text2, '赌博'), $details2[0]['offset'], '测试用例 2.2.1 (详细模式): "赌博" offset 不正确。');
        
        $this->assertEquals('法轮功', $details2[1]['word'], '测试用例 2.3 (详细模式): $wordNum=2 时第二个条目应为 "法轮功".');
        $this->assertEquals(mb_strpos($text2, '法轮功'), $details2[1]['offset'], '测试用例 2.3.1 (详细模式): "法轮功" offset 不正确。');

        // 3. 测试白名单和详细信息 (中文)
        $helperWithWhitelist = $this->createHelper(['毛片'], ['卖毛片']);
        $text3 = '毛片 卖毛片'; // 第一个'毛片'是敏感词, 第二个是白名单上下文的一部分
        $details3 = $helperWithWhitelist->getBadWord($text3, 1, 0, true);
        $this->assertCount(1, $details3, '测试用例 3.1 (详细模式): 因白名单存在，应只找到一个 "毛片"。');
        if (count($details3) == 1) {
            $this->assertEquals('毛片', $details3[0]['word'], '测试用例 3.2 (详细模式): 找到的词应为 "毛片".');
            $this->assertEquals(0, $details3[0]['offset'], '测试用例 3.3 (详细模式): 第一个 "毛片" 的 offset 应为 0。');
        }

        // 4. 测试无敏感词情况 (中文)
        $text4 = '一段纯净的中文文本';
        $details4 = $helper->getBadWord($text4, 1, 0, true);
        $this->assertIsArray($details4, '测试用例 4.1 (详细模式): 即使为空也应返回数组。');
        $this->assertEmpty($details4, '测试用例 4.2 (详细模式): 对于纯净文本应返回空数组。');
        
        // 5. 测试中文变形词 (启用 detectVariantText)
        $configVariant = new Config([
            'sensitive_words' => [
                'word_path' => null, 
                'whitelist' => [],
                'enable_cache' => false, 
                'preload' => false,
                'emoji_strategy' => 'ignore',
                'detect_variant_text' => true, 
            ]
        ]);
        $helperVariant = new SensitiveHelper($configVariant);
        // 假设词库中的敏感词是规范化后的形式
        $helperVariant->setTree(['卖淫嫖娼']); 

        $textVariant = '卖 淫 嫖 娼'; // 包含空格，应被预处理
        $processedTextVariant = '卖淫嫖娼'; // 预期的处理后形式
        
        $detailsVariant = $helperVariant->getBadWord($textVariant, 1, 0, true);
        $this->assertCount(1, $detailsVariant, '测试用例 5.1 (详细模式): 应找到变形的中文敏感词。');
        if (count($detailsVariant) == 1) {
            $item = $detailsVariant[0];
            $this->assertEquals($processedTextVariant, $item['word'], '测试用例 5.2 (详细模式): 词语应是处理后的规范形式。');
            $this->assertEquals(0, $item['offset'], '测试用例 5.3 (详细模式): Offset 应为0 (相对于处理后的文本)。');
            $this->assertEquals(mb_strlen($processedTextVariant), $item['len'], '测试用例 5.4 (详细模式): 长度应是处理后词语的长度。');
        }
    }

    public function testReplaceBasic()
    {
        $helper = $this->createHelper(["敏感", "敏感词", "坏蛋", "法轮功", "测试敏感", "敏感内容", "AB", "ABC", "BCD"]);
        
        $this->assertEquals("这是*", $helper->replace("这是坏蛋", "*"));
        $this->assertEquals("****内容", $helper->replace("测试敏感内容", "*", true)); // repeat = true
        $this->assertEquals("文本内容", $helper->replace("文本敏感内容", "", false)); // replace with empty string
        $this->assertEquals("文本内容", $helper->replace("文本敏感内容", "", true));   // replace with empty string and repeat
    }

    public function testReplaceWithDifferentChar()
    {
        $helper = $this->createHelper(["坏蛋", "敏感内容"]);
        $this->assertEquals("这是+", $helper->replace("这是坏蛋", "+"));
        $this->assertEquals("测试++++", $helper->replace("测试敏感内容", "+", true));
    }

    public function testReplaceRepeat()
    {
        $helper = $this->createHelper(["坏蛋", "法轮功", "敏感内容"]);
        $this->assertEquals("这是**", $helper->replace("这是坏蛋", "*", true));
        $this->assertEquals("XXX被替换", $helper->replace("法轮功被替换", "XXX", false)); // "法轮功" (3 chars) -> "XXX"
        $this->assertEquals("X被替换", $helper->replace("法轮功被替换", "X", false));   // "法轮功" (3 chars) -> "X"
        $this->assertEquals("XXX被替换", $helper->replace("法轮功被替换", "X", true));  // "法轮功" (3 chars) -> "XXX" (repeat 'X')
        $this->assertEquals("测试****", $helper->replace("测试敏感内容", "*", true)); // "敏感内容" (4 chars) -> "****"
    }
    
    public function testReplaceMultipleSensitiveWords()
    {
        $helper = $this->createHelper(["敏感词", "坏蛋"]);
        $this->assertEquals("这个*语和另一个*都要处理", $helper->replace("这个敏感词语和另一个坏蛋都要处理", "*"));
        $this->assertEquals("这个***语和另一个**都要处理", $helper->replace("这个敏感词语和另一个坏蛋都要处理", "*", true));
    }

    public function testReplaceNoSensitiveWords()
    {
        $helper = $this->createHelper(["敏感词"]);
        $original = "这是一段正常的文本内容";
        // preprocessContent 在默认配置下不改变此字符串
        $this->assertEquals($original, $helper->replace($original, "*")); 
    }

    public function testReplaceEmptyString()
    {
        $helper = $this->createHelper(["敏感词"]);
        $this->assertEquals("", $helper->replace("", "*"));
    }

    public function testReplaceWithWhitelistedWord()
    {
        $helper = $this->createHelper(
            ["敏感", "敏感词", "坏蛋", "测试敏感"],
            ["好坏蛋", "测试敏感词"] // 白名单
        );
        // "好坏蛋" 是白名单, "坏蛋" 是敏感词
        $this->assertEquals("这个好坏蛋没问题", $helper->replace("这个好坏蛋没问题", "*"));
        // "测试敏感词" 是白名单, "测试敏感" 和 "敏感词" 是敏感词
        $this->assertEquals("这句话测试敏感词通过", $helper->replace("这句话测试敏感词通过", "*"));
        $this->assertEquals("单独的*", $helper->replace("单独的敏感", "*", false));
        $this->assertEquals("单独的*词", $helper->replace("单独的敏感词", "*", false));
    }

    public function testReplaceOverlappingMinimalMatch() // matchType = 1 (default)
    {
        $helper = $this->createHelper(["敏感", "敏感内容", "AB", "ABC", "BCD"]);
        $this->assertEquals("文本**内容和**CD", $helper->replace("文本敏感内容和ABCD", "*", true, 1));
        $this->assertEquals("文本*内容和*CD", $helper->replace("文本敏感内容和ABCD", "*", false, 1));
    }
    
    public function testReplaceOverlappingMaximalMatch() // matchType = 0 (for maximal)
    {
        $helper = $this->createHelper(["敏感", "敏感内容", "AB", "ABC", "BCD"]);
        $this->assertEquals("文本****和***D", $helper->replace("文本敏感内容和ABCD", "*", true, 0));
        $this->assertEquals("文本*和*D", $helper->replace("文本敏感内容和ABCD", "*", false, 0));
    }

    public function testReplaceWithMixedCharsAndNumbers()
    {
        $helper = $this->createHelper(["法轮功", "敏感词"]);
        $text = "法轮功123 and 敏感词word";
        $this->assertEquals("***123 and ***word", $helper->replace($text, "*", true));
        $this->assertEquals("*123 and *word", $helper->replace($text, "*", false));
    }

    // --- 以下是为 mark 方法新增的测试用例 ---

    public function testMarkBasic()
    {
        $helper = $this->createHelper(["敏感", "敏感词", "坏蛋"]);
        $this->assertEquals("这是<b>坏蛋</b>", $helper->mark("这是坏蛋", "<b>", "</b>"));
        $this->assertEquals("测试<em>敏感</em>词内容", $helper->mark("测试敏感词内容", "<em>", "</em>"));
    }

    public function testMarkWithDifferentTags()
    {
        $helper = $this->createHelper(["坏蛋"]);
        $this->assertEquals("这是<tag1>坏蛋<tag2>", $helper->mark("这是坏蛋", "<tag1>", "<tag2>"));
    }

    public function testMarkMultipleSensitiveWords()
    {
        $helper = $this->createHelper(["敏感词", "坏蛋"]);
        $text = "这个敏感词语和另一个坏蛋都要处理";
        $expected = "这个<mark>敏感词</mark>语和另一个<mark>坏蛋</mark>都要处理";
        $this->assertEquals($expected, $helper->mark($text, "<mark>", "</mark>"));
    }

    public function testMarkNoSensitiveWords()
    {
        $helper = $this->createHelper(["敏感词"]);
        $original = "这是一段正常的文本内容";
        $this->assertEquals($original, $helper->mark($original, "<b>", "</b>"));
    }

    public function testMarkEmptyString()
    {
        $helper = $this->createHelper(["敏感词"]);
        $this->assertEquals("", $helper->mark("", "<b>", "</b>"));
    }

    public function testMarkWithWhitelistedWord()
    {
        $helper = $this->createHelper(
            ["敏感", "敏感词", "坏蛋", "测试敏感"],
            ["好坏蛋", "测试敏感词"] 
        );
        $sTag = "<span>";
        $eTag = "</span>";

        $this->assertEquals("这个好坏蛋没问题", $helper->mark("这个好坏蛋没问题", $sTag, $eTag));
        $this->assertEquals("这句话测试敏感词通过", $helper->mark("这句话测试敏感词通过", $sTag, $eTag));
        $this->assertEquals("单独的{$sTag}敏感{$eTag}", $helper->mark("单独的敏感", $sTag, $eTag));
        $this->assertEquals("单独的{$sTag}敏感{$eTag}词", $helper->mark("单独的敏感词", $sTag, $eTag));
    }

    public function testMarkOverlappingMinimalMatch() // matchType = 1 (default)
    {
        $helper = $this->createHelper(["敏感", "敏感内容", "AB", "ABC", "BCD"]);
        $sTag = "<x>";
        $eTag = "</x>";
        $this->assertEquals("文本{$sTag}敏感{$eTag}内容和{$sTag}AB{$eTag}CD", $helper->mark("文本敏感内容和ABCD", $sTag, $eTag, 1));
    }

    public function testMarkOverlappingMaximalMatch() // matchType = 0
    {
        $helper = $this->createHelper(["敏感", "敏感内容", "AB", "ABC", "BCD"]);
        $sTag = "<strong>";
        $eTag = "</strong>";
        $this->assertEquals("文本{$sTag}敏感内容{$eTag}和{$sTag}ABC{$eTag}D", $helper->mark("文本敏感内容和ABCD", $sTag, $eTag, 0));
    }

    public function testMarkWithMixedCharsAndNumbers()
    {
        $helper = $this->createHelper(["法轮功", "敏感词"]);
        $text = "法轮功123 and 敏感词word";
        $sTag = "[S]"; $eTag = "[E]";
        $this->assertEquals("[S]法轮功[E]123 and [S]敏感词[E]word", $helper->mark($text, $sTag, $eTag));
    }

    /**
     * 测试动态白名单管理功能
     */
    public function testDynamicWhitelistManagement()
    {
        // 1. 基本添加和检测
        $helper = $this->createHelper(['testword1', 'añadido 傻z'], []); // 初始为空词库
        $this->assertTrue($helper->addWords(['testword1', 'añadido 傻z']), '添加词语失败');
        
        $actualBadWords1 = $helper->getBadWord('这是一个 testword1');
        $this->assertContains('testword1', $actualBadWords1, '基础添加后未能检测到 testword1');
        
        $actualBadWords2 = $helper->getBadWord('另一个 añadido 傻z 例子');
        $this->assertContains('añadido 傻z', $actualBadWords2, '基础添加后未能检测到 "añadido"');
        $this->assertFalse($helper->islegal('包含 testword1 的文本'), 'islegal 判断错误 testword1');

        // 2. 重复添加 (不应产生错误，词库应保持一致)
        $this->assertTrue($helper->addWords(['testword1', 'newword']), '重复/新增添词语失败');
        $actualBadWords3 = $helper->getBadWord('testword1 和 newword');
        $this->assertContains('testword1', $actualBadWords3, '重复添加 testword1 后检测失败');
        $this->assertContains('newword', $actualBadWords3, '添加 newword 后检测失败');
        $this->assertCount(2, array_unique($actualBadWords3), '重复添加后，检测到的敏感词数量不唯一或不正确');

        // 3. 与现有词库交互 (先 setTree，后 addWords)
        $helper2 = $this->createHelper(['initial'], []);
        $this->assertTrue($helper2->addWords(['added']), '在现有词库基础上添加词语失败');
        $actualBadWords4 = $helper2->getBadWord('initial text with added word');
        $this->assertContains('initial', $actualBadWords4, 'setTree后的词 initial 未检测到');
        $this->assertContains('added', $actualBadWords4, 'addWords添加的词 added 未检测到');

        // 4. 与白名单交互
        $helper3 = $this->createHelper([], ['helloween']);
        $this->assertTrue($helper3->addWords(['hellfire']), '添加 "hellfire" 失败');
        
        // 添加白名单词语
        $this->assertTrue($helper3->addWhitelistWords(['hellfire']), '添加白名单词语失败');
        $this->assertTrue($helper3->isWhitelisted('hellfire'), '白名单检查失败');
        
        // 检测应该被白名单过滤
        $badWords = $helper3->getBadWord('this is hellfire test');
        $this->assertNotContains('hellfire', $badWords, '白名单过滤失败');
        
        // 删除白名单词语
        $this->assertTrue($helper3->removeWhitelistWords(['hellfire']), '删除白名单词语失败');
        $this->assertFalse($helper3->isWhitelisted('hellfire'), '删除白名单后检查失败');
        
        // 现在应该能检测到
        $badWords2 = $helper3->getBadWord('this is hellfire test');
        $this->assertContains('hellfire', $badWords2, '删除白名单后检测失败');
    }

    /**
     * 测试获取所有敏感词功能
     */
    public function testGetAllSensitiveWords()
    {
        // 1. 空词库测试
        $helper = $this->createHelper([], []);
        $allWords = $helper->getAllSensitiveWords();
        $this->assertIsArray($allWords, '返回值应该是数组');

        // 2. 基本词库测试
        $testWords = ['敏感词1', '敏感词2', 'badword', 'test'];
        $helper2 = $this->createHelper($testWords, []);
        $allWords2 = $helper2->getAllSensitiveWords();
        
        $this->assertIsArray($allWords2, '返回值应该是数组');
        $this->assertCount(count($testWords), $allWords2, '返回的敏感词数量不正确');
        
        foreach ($testWords as $word) {
            $this->assertContains($word, $allWords2, "敏感词 '{$word}' 未在返回列表中");
        }

        // 3. 动态添加词语后测试
        $helper2->addWords(['新增词1', '新增词2']);
        $allWords3 = $helper2->getAllSensitiveWords();
        
        $this->assertContains('新增词1', $allWords3, '动态添加的敏感词未在返回列表中');
        $this->assertContains('新增词2', $allWords3, '动态添加的敏感词未在返回列表中');
        $this->assertCount(count($testWords) + 2, $allWords3, '动态添加后敏感词数量不正确');

        // 4. 重复词语去重测试
        $helper3 = $this->createHelper(['重复词', '重复词', '唯一词'], []);
        $allWords4 = $helper3->getAllSensitiveWords();
        
        $this->assertCount(2, $allWords4, '重复词语应该被去重');
        $this->assertContains('重复词', $allWords4, '重复词应该存在');
        $this->assertContains('唯一词', $allWords4, '唯一词应该存在');

        // 5. 大词库性能测试（可选）
        $largeWordList = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeWordList[] = "敏感词{$i}";
        }
        
        $helper4 = $this->createHelper($largeWordList, []);
        $start = microtime(true);
        $allWords5 = $helper4->getAllSensitiveWords();
        $end = microtime(true);
        
        $this->assertCount(1000, $allWords5, '大词库敏感词数量不正确');
        $this->assertLessThan(1.0, $end - $start, '获取大词库敏感词耗时过长（超过1秒）');

        // 6. 包含特殊字符的敏感词测试
        $specialWords = ['测试@词', '特殊#字符', '数字123词', 'English_word'];
        $helper5 = $this->createHelper($specialWords, []);
        $allWords6 = $helper5->getAllSensitiveWords();
        
        foreach ($specialWords as $word) {
            $this->assertContains($word, $allWords6, "包含特殊字符的敏感词 '{$word}' 未在返回列表中");
        }
    }
} 