<?php
/**
 * MagoArab_EasYorder Debug Logger Service
 * Centralized logging and debugging for troubleshooting
 */
declare(strict_types=1);

namespace MagoArab\EasYorder\Model\Service;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class DebugLogger
{
    private $logger;
    private $scopeConfig;
    private $debugEnabled;

    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->debugEnabled = $this->isDebugEnabled();
    }

    /**
     * Check if debug mode is enabled
     */
    private function isDebugEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'magoarab_easyorder/advanced/debug_mode',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Log info message with context
     */
    public function info(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->logger->info('[EasyOrder] ' . $message, $this->sanitizeContext($context));
        }
    }

    /**
     * Log error message with context
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error('[EasyOrder ERROR] ' . $message, $this->sanitizeContext($context));
    }

    /**
     * Log warning message with context
     */
    public function warning(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->logger->warning('[EasyOrder WARNING] ' . $message, $this->sanitizeContext($context));
        }
    }

    /**
     * Log debug message with context
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->logger->debug('[EasyOrder DEBUG] ' . $message, $this->sanitizeContext($context));
        }
    }

    /**
     * Log service performance metrics
     */
    public function logPerformance(string $service, float $startTime, array $additionalData = []): void
    {
        if ($this->debugEnabled) {
            $executionTime = microtime(true) - $startTime;
            $memoryUsage = memory_get_usage(true) / 1024 / 1024; // MB
            
            $this->info("Performance metrics for {$service}", array_merge([
                'execution_time_ms' => round($executionTime * 1000, 2),
                'memory_usage_mb' => round($memoryUsage, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ], $additionalData));
        }
    }

    /**
     * Log API request/response
     */
    public function logApiCall(string $endpoint, array $request, array $response, float $startTime): void
    {
        if ($this->debugEnabled) {
            $this->info("API Call: {$endpoint}", [
                'request_data' => $this->sanitizeApiData($request),
                'response_data' => $this->sanitizeApiData($response),
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
        }
    }

    /**
     * Log shipping calculation details
     */
    public function logShippingCalculation(array $data): void
    {
        if ($this->debugEnabled) {
            $this->info("Shipping Calculation", $data);
        }
    }

    /**
     * Log order creation steps
     */
    public function logOrderCreationStep(string $step, array $data = []): void
    {
        if ($this->debugEnabled) {
            $this->info("Order Creation Step: {$step}", $data);
        }
    }

    /**
     * Sanitize context data to avoid logging sensitive information
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = [
            'password', 'credit_card', 'cvv', 'ssn', 'api_key', 'secret',
            'token', 'authorization', 'payment_token', 'customer_password'
        ];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys)) {
                $context[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
            }
        }

        return $context;
    }

    /**
     * Sanitize API data
     */
    private function sanitizeApiData(array $data): array
    {
        // Limit array size to prevent huge logs
        if (count($data) > 100) {
            return array_slice($data, 0, 100, true) + ['...truncated' => 'data_too_large'];
        }

        return $this->sanitizeContext($data);
    }

    /**
     * Create structured log entry for troubleshooting
     */
    public function logTroubleshootingInfo(string $issue, array $diagnosticData): void
    {
        $this->error("Troubleshooting Issue: {$issue}", [
            'timestamp' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'diagnostic_data' => $diagnosticData
        ]);
    }

    /**
     * Force log (even if debug is disabled) for critical errors
     */
    public function forceLog(string $level, string $message, array $context = []): void
    {
        $method = strtolower($level);
        if (method_exists($this->logger, $method)) {
            $this->logger->{$method}('[EasyOrder FORCE] ' . $message, $this->sanitizeContext($context));
        }
    }
}