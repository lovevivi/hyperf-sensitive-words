<?php

namespace SensitiveWords\Filters;

class ContextFilter implements FilterStrategyInterface
{
    public function shouldFilter(string $word, string $content): bool
    {
        return $this->isProfessionalTerm($word, $content) ||
               $this->isBusinessNewsContext($word, $content) ||
               $this->isBrandOrProperNoun($word, $content);
    }
    
    public function getName(): string
    {
        return 'context_filter';
    }
    
    /**
     * 检查是否为专业术语
     * @param string $word 待检查的词汇
     * @param string $content 原始文本内容
     * @return bool 是否为专业术语
     */
    private function isProfessionalTerm(string $word, string $content): bool
    {
        // 定义专业术语模式和对应的词汇
        $professionalPatterns = [
            // 音频/视频技术术语
            '/(?:音频|视频|数字|信号|编码|解码|压缩|传输|保真|音质|画质)/' => ['保真', '真'],
            
            // 计算机技术术语  
            '/(?:系统|软件|程序|代码|算法|数据|网络|服务器|数据库)/' => ['ur', 'url', '保真'],
            
            // 科研学术术语
            '/(?:研究|实验|测试|分析|检测|科学|技术|方法|理论|假设)/' => ['测试', '真', '保真'],
            
            // 农业科技术语
            '/(?:农业|种植|播种|收获|产量|品种|土壤|肥料|灌溉|示范)/' => ['保真', '测试'],
            
            // 地理和地质术语
            '/(?:地貌|地质|地形|地理|地震|震级|北纬|东经|喀斯特|平原|山地|丘陵|盆地)/' => ['喀特', '地震', '北', '东'],
            
            // 时间日期表达
            '/(?:\d+年|\d+月|\d+日|上午|下午|凌晨|时\d+分|年月日)/' => ['日', '月', '年', '时', '分'],
            
            // 质量控制术语
            '/(?:质量|精度|准确|可靠|标准|规范|检验|验证|测量)/' => ['保真', '真', '测试'],
        ];
        
        // 检查词汇是否在专业语境中出现
        foreach ($professionalPatterns as $pattern => $applicableWords) {
            if (in_array($word, $applicableWords) && preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查是否为商业新闻文本的正常词汇
     * @param string $word 待检查的词汇
     * @param string $content 原始文本内容
     * @return bool 是否为正常的商业新闻词汇
     */
    private function isBusinessNewsContext(string $word, string $content): bool
    {
        // 定义商业新闻的特征词汇和对应的正常词汇
        $businessPatterns = [
            // 电商相关
            '/(?:店铺|淘宝|京东|拼多多|电商|购买|售卖|价格|折扣|商品|销量)/' => ['淘宝', '价格', '售', '卖'],
            
            // 企业运营相关
            '/(?:公司|企业|品牌|运营|管理|成立|创立|经营|业务)/' => ['运营', '运营商', '营'],
            
            // 新闻媒体相关
            '/(?:记者|报道|新闻|媒体|采访|爆料|消息|据悉|报告|网民|社交平台|发布|核实|情况说明)/' => ['爆料', '料', '日', '报'],
            
            // 应急和灾害相关
            '/(?:地震|灾害|应急|管理厅|受损|伤亡|突发|危房|隐患|救援|监测)/' => ['地震', '日', '灾', '害', '伤', '亡'],
            
            // 娱乐明星相关
            '/(?:演员|明星|艺人|女星|男星|娱乐|影视|作品)/' => ['星', '演'],
            
            // 服装时尚相关
            '/(?:服装|衣服|T恤|外套|袜子|时尚|潮牌|面料|材质)/' => ['卖', '服'],
            
            // 其他商业词汇
            '/(?:市场|标准|质量|产品|服务|消费者|客户)/' => ['其他', '他'],
        ];
        
        // 检查是否在商业新闻语境中
        foreach ($businessPatterns as $pattern => $applicableWords) {
            if (in_array($word, $applicableWords) && preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查是否为品牌名称或专有名词的一部分
     * @param string $word 待检查的词汇
     * @param string $content 原始文本内容
     * @return bool 是否为品牌名称或专有名词
     */
    private function isBrandOrProperNoun(string $word, string $content): bool
    {
        $wordLen = mb_strlen($word, 'utf-8');
        
        // 对于极短的词汇（特别是英文字母组合），检查是否在品牌名称中
        if ($wordLen <= 3 && preg_match('/^[a-zA-Z]+$/', $word)) {
            // 查找词汇在文本中的位置
            $position = mb_strpos($content, $word, 0, 'utf-8');
            if ($position !== false) {
                $contextBefore = '';
                $contextAfter = '';
                
                // 获取前后10个字符的上下文
                $startPos = max(0, $position - 10);
                $endPos = min(mb_strlen($content, 'utf-8'), $position + $wordLen + 10);
                
                if ($position > 0) {
                    $contextBefore = mb_substr($content, $startPos, $position - $startPos, 'utf-8');
                }
                if ($position + $wordLen < mb_strlen($content, 'utf-8')) {
                    $contextAfter = mb_substr($content, $position + $wordLen, $endPos - ($position + $wordLen), 'utf-8');
                }
                
                $fullContext = $contextBefore . $word . $contextAfter;
                
                // 检查是否在品牌名称、公司名称或专有名词的语境中
                $brandPatterns = [
                    '/[A-Z]{2,}\s+[A-Z]+/', // 大写字母组成的品牌名（如 NJ RAINER）
                    '/\b[a-zA-Z]+\s*[a-zA-Z]+\b.*(?:品牌|公司|集团|有限公司)/', // 品牌或公司名称
                    '/(?:潮牌|品牌|商标|logo|LOGO).*[a-zA-Z]+/', // 潮牌相关
                    '/[a-zA-Z]+.*(?:店铺|旗舰店|专卖店)/', // 店铺相关
                ];
                
                foreach ($brandPatterns as $pattern) {
                    if (preg_match($pattern, $fullContext)) {
                        return true;
                    }
                }
                
                // 检查是否被引号、括号等符号包围（通常表示专有名词）
                if (preg_match('/[""「」『』\(\)\[\]【】《》].*' . preg_quote($word, '/') . '.*[""「」『』\(\)\[\]【】《》]/', $fullContext)) {
                    return true;
                }
            }
        }
        
        return false;
    }
} 