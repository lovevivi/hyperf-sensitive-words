<?php

declare(strict_types=1);

namespace SensitiveWords\Annotation;

use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * @Annotation
 * @Target({"METHOD"})
 */
class SensitiveCheck extends AbstractAnnotation
{
    /**
     * 需要检查的参数名
     */
    public $param = 'content';
    
    /**
     * 是否替换
     */
    public $replace = true;
    
    /**
     * 替换字符
     */
    public $replaceChar = '*';
} 