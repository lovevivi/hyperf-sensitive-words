<?php

declare(strict_types=1);

namespace SensitiveWords\Helpers\Exceptions;

/**
 * 敏感词异常类
 */
class SensitiveWordException extends \Exception
{
    /**
     * 无法找到文件
     */
    public const CANNOT_FIND_FILE = 100;

    /**
     * 敏感词库内容为空
     */
    public const EMPTY_WORD_POOL = 101;

    /**
     * 待检测内容为空
     */
    public const EMPTY_CONTENT = 102;
    
    /**
     * 系统错误
     */
    public const SYSTEM_ERROR = 500;
} 