<?php

declare(strict_types=1);

namespace SensitiveWords\Aspect;

use Hyperf\Di\Annotation\Aspect;
use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveWords\Annotation\SensitiveCheck;
use SensitiveWords\SensitiveWordsManager;

/**
 * @Aspect
 */
class SensitiveAspect extends AbstractAspect
{
    /**
     * @var array
     */
    public $annotations = [
        SensitiveCheck::class,
    ];

    /**
     * @var SensitiveWordsManager
     */
    protected $sensitiveWordsManager;

    public function __construct(SensitiveWordsManager $sensitiveWordsManager)
    {
        $this->sensitiveWordsManager = $sensitiveWordsManager;
    }

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        $annotation = $proceedingJoinPoint->getAnnotationMetadata()->method[SensitiveCheck::class];
        $arguments = $proceedingJoinPoint->getArguments();
        $needModify = false;

        // 获取方法参数名称
        $parameterNames = $this->getMethodParameterNames($proceedingJoinPoint);

        // 处理参数中的敏感词
        foreach ($arguments as $index => $argument) {
            if (is_string($argument) && isset($parameterNames[$index])) {
                $paramName = $parameterNames[$index];
                if ($paramName === $annotation->param && $annotation->replace) {
                    try {
                        $modifiedArgument = $this->sensitiveWordsManager->replace(
                            $argument,
                            $annotation->replaceChar
                        );

                        // 如果处理后的参数与原参数不同，则更新
                        if ($modifiedArgument !== $argument) {
                            $arguments[$index] = $modifiedArgument;
                            $needModify = true;
                        }
                    } catch (\Throwable $e) {
                        // 异常处理，继续使用原参数
                    }
                }
            }
        }

        // 如果参数被修改，直接返回修改后的参数
        if ($needModify) {
            return $arguments[0];
        }

        // 如果没有修改，直接调用proceed
        return $proceedingJoinPoint->process();
    }

    /**
     * 获取方法的参数名称列表
     *
     * @param ProceedingJoinPoint $proceedingJoinPoint
     * @return array 参数名称列表，索引对应参数位置
     */
    protected function getMethodParameterNames(ProceedingJoinPoint $proceedingJoinPoint): array
    {
        $parameterNames = [];

        try {
            // 获取类和方法信息
            $className = $proceedingJoinPoint->className;
            $methodName = $proceedingJoinPoint->methodName;

            // 使用反射获取参数信息
            $reflectionMethod = new ReflectionMethod($className, $methodName);
            $parameters = $reflectionMethod->getParameters();

            // 提取参数名称
            foreach ($parameters as $key => $parameter) {
                /** @var ReflectionParameter $parameter */
                $parameterNames[$key] = $parameter->getName();
            }
        } catch (\ReflectionException $e) {
            // 反射异常处理
        }

        return $parameterNames;
    }
} 