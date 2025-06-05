<?php

namespace SensitiveWords\Filters;

class FilterManager
{
    /**
     * @var FilterStrategyInterface[]
     */
    private $filters = [];
    
    /**
     * 添加过滤器
     * @param FilterStrategyInterface $filter
     */
    public function addFilter(FilterStrategyInterface $filter): void
    {
        $this->filters[$filter->getName()] = $filter;
    }
    
    /**
     * 移除过滤器
     * @param string $filterName
     */
    public function removeFilter(string $filterName): void
    {
        unset($this->filters[$filterName]);
    }
    
    /**
     * 检查词汇是否应该被过滤
     * @param string $word
     * @param string $content
     * @return bool
     */
    public function shouldFilter(string $word, string $content): bool
    {
        foreach ($this->filters as $filter) {
            if ($filter->shouldFilter($word, $content)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 获取所有过滤器名称
     * @return array
     */
    public function getFilterNames(): array
    {
        return array_keys($this->filters);
    }
    
    /**
     * 获取过滤器数量
     * @return int
     */
    public function getFilterCount(): int
    {
        return count($this->filters);
    }
    
    /**
     * 清空所有过滤器
     */
    public function clearFilters(): void
    {
        $this->filters = [];
    }
    
    /**
     * 设置默认过滤器
     */
    public function setDefaultFilters(): void
    {
        $this->addFilter(new BoundaryFilter());
        $this->addFilter(new LengthFilter());
        $this->addFilter(new ContextFilter());
    }
} 