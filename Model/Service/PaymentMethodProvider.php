<?php
/**
 * MagoArab_EasYorder Payment Method Provider Service
 * Handles payment method retrieval and filtering
 */
declare(strict_types=1);

namespace MagoArab\EasYorder\Model\Service;

use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Model\Config as PaymentConfig;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class PaymentMethodProvider
{
    private $helperData;
    private $paymentMethodList;
    private $paymentConfig;
    private $storeManager;
    private $scopeConfig;
    private $logger;

    public function __construct(
        HelperData $helperData,
        PaymentMethodListInterface $paymentMethodList,
        PaymentConfig $paymentConfig,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->helperData = $helperData;
        $this->paymentMethodList = $paymentMethodList;
        $this->paymentConfig = $paymentConfig;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Get available payment methods
     */
    public function getAvailablePaymentMethods(): array
    {
        try {
            $store = $this->storeManager->getStore();
            
            $this->logger->info('=== Payment Method Provider: Getting methods ===', [
                'store_id' => $store->getId()
            ]);

            // Use official Payment Method List API
            $paymentMethods = $this->paymentMethodList->getActiveList($store->getId());
            $methods = [];

            foreach ($paymentMethods as $method) {
                $methodCode = $method->getCode();
                $title = $method->getTitle() ?: $this->getPaymentMethodDefaultTitle($methodCode);
                
                $methods[] = [
                    'code' => $methodCode,
                    'title' => $title
                ];
            }

            // Apply admin filtering
            $filteredMethods = $this->helperData->filterPaymentMethods($methods);

            $this->logger->info('=== Payment Method Provider: Methods retrieved ===', [
                'total_methods' => count($methods),
                'filtered_methods' => count($filteredMethods),
                'methods' => array_column($filteredMethods, 'code')
            ]);

            return $filteredMethods;

        } catch (\Exception $e) {
            $this->logger->error('=== Payment Method Provider: Error ===', [
                'error' => $e->getMessage()
            ]);
            return $this->getFallbackPaymentMethods();
        }
    }

    /**
     * Get fallback payment methods
     */
    private function getFallbackPaymentMethods(): array
    {
        try {
            $store = $this->storeManager->getStore();
            $activePayments = $this->paymentConfig->getActiveMethods();
            $methods = [];

            foreach ($activePayments as $code => $config) {
                $isActive = $this->scopeConfig->getValue(
                    'payment/' . $code . '/active',
                    ScopeInterface::SCOPE_STORE,
                    $store->getId()
                );

                if ($isActive) {
                    $title = $this->scopeConfig->getValue(
                        'payment/' . $code . '/title',
                        ScopeInterface::SCOPE_STORE,
                        $store->getId()
                    ) ?: $this->getPaymentMethodDefaultTitle($code);

                    $methods[] = [
                        'code' => $code,
                        'title' => $title
                    ];
                }
            }

            $this->logger->info('Fallback payment methods retrieved', [
                'methods_count' => count($methods)
            ]);

            return $methods;

        } catch (\Exception $e) {
            $this->logger->error('Error getting fallback payment methods: ' . $e->getMessage());
            return $this->getUltimateFallbackMethods();
        }
    }

    /**
     * Get ultimate fallback methods
     */
    private function getUltimateFallbackMethods(): array
    {
        return [
            [
                'code' => 'cashondelivery',
                'title' => __('Cash on Delivery')
            ],
            [
                'code' => 'checkmo',
                'title' => __('Check / Money Order')
            ]
        ];
    }

    /**
     * Get default title for payment method
     */
    private function getPaymentMethodDefaultTitle(string $methodCode): string
    {
        $titles = [
            'checkmo' => __('Check / Money Order'),
            'banktransfer' => __('Bank Transfer Payment'),
            'cashondelivery' => __('Cash on Delivery'),
            'free' => __('No Payment Information Required'),
            'purchaseorder' => __('Purchase Order'),
            'paypal_express' => __('PayPal Express Checkout'),
            'authorizenet_directpost' => __('Credit Card Direct Post'),
            'braintree' => __('Credit Card (Braintree)'),
            'stripe_payments' => __('Credit Card (Stripe)')
        ];

        return (string)($titles[$methodCode] ?? ucfirst(str_replace('_', ' ', $methodCode)));
    }
}