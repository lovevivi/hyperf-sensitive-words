<?php

declare(strict_types=1);

namespace SensitiveWords\Middleware;

use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SensitiveWords\Exceptions\SensitiveWordException;
use SensitiveWords\SensitiveWordsManager;

class SensitiveWordsMiddleware implements MiddlewareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var HttpResponse
     */
    protected $response;
    
    /**
     * @var SensitiveWordsManager
     */
    protected $sensitiveWordsManager;
    
    /**
     * @var ConfigInterface
     */
    protected $config;
    
    /**
     * 最大递归处理深度
     * 
     * @var int
     */
    protected $maxRecursion = 5;

    public function __construct(
        ContainerInterface $container, 
        RequestInterface $request, 
        HttpResponse $response,
        SensitiveWordsManager $sensitiveWordsManager,
        ConfigInterface $config
    ) {
        $this->container = $container;
        $this->request = $request;
        $this->response = $response;
        $this->sensitiveWordsManager = $sensitiveWordsManager;
        $this->config = $config;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 如果中间件未开启，直接跳过
        if (!$this->config->get('sensitive_words.middleware_enable', false)) {
            return $handler->handle($request);
        }
        
        try {
            // 获取需要处理的参数
            $httpParams = $this->config->get('sensitive_words.http_params', []);
            $parsedBody = $request->getParsedBody() ?? [];
            $queryParams = $request->getQueryParams() ?? [];
            
            // 将目标参数转换为关联数组，提高检查效率
            $targetParamsMap = array_flip($httpParams);
            
            $replacedBody = $this->replaceParams($parsedBody, $targetParamsMap);
            $replacedQuery = $this->replaceParams($queryParams, $targetParamsMap);
            
            // 构造新的请求对象
            $newRequest = $request
                ->withParsedBody($replacedBody)
                ->withQueryParams($replacedQuery);
            
            return $handler->handle($newRequest);
        } catch (\Throwable $e) {
            // 出现异常时记录日志（如果有日志系统）并继续处理请求
            if (method_exists($this->container, 'get') && $this->container->has('logger')) {
                $logger = $this->container->get('logger');
                $logger->error(sprintf('敏感词过滤异常: %s', $e->getMessage()));
            }
            
            // 异常时使用原始请求继续
            return $handler->handle($request);
        }
    }
    
    /**
     * 递归处理参数中的敏感词
     * 
     * @param array $params 需要处理的参数
     * @param array $targetParamsMap 目标参数的映射
     * @param int $depth 当前递归深度
     * @return array 处理后的参数
     */
    protected function replaceParams(array $params, array $targetParamsMap, int $depth = 0): array
    {
        // 防止无限递归
        if ($depth >= $this->maxRecursion) {
            return $params;
        }
        
        foreach ($params as $key => $value) {
            // 使用isset代替in_array提高性能
            if (is_string($value) && isset($targetParamsMap[$key])) {
                try {
                    $params[$key] = $this->sensitiveWordsManager->replace(
                        $value,
                        $this->config->get('sensitive_words.replace_char', '*'),
                        $this->config->get('sensitive_words.repeat_char', true)
                    );
                } catch (SensitiveWordException $e) {
                    // 敏感词处理异常，保留原始值
                }
            } elseif (is_array($value)) {
                // 递归处理并增加深度计数
                $params[$key] = $this->replaceParams($value, $targetParamsMap, $depth + 1);
            }
        }
        
        return $params;
    }
} 