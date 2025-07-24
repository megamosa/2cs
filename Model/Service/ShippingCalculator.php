<?php
/**
 * MagoArab_EasYorder Shipping Calculator Service
 * Handles all shipping-related calculations
 */
declare(strict_types=1);

namespace MagoArab\EasYorder\Model\Service;

use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Shipping\Model\Config as ShippingConfig;
use Psr\Log\LoggerInterface;

class ShippingCalculator
{
    private $helperData;
    private $quoteFactory;
    private $cartRepository;
    private $productRepository;
    private $storeManager;
    private $regionFactory;
    private $priceHelper;
    private $shippingConfig;
    private $logger;

    public function __construct(
        HelperData $helperData,
        QuoteFactory $quoteFactory,
        CartRepositoryInterface $cartRepository,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        RegionFactory $regionFactory,
        PriceHelper $priceHelper,
        ShippingConfig $shippingConfig,
        LoggerInterface $logger
    ) {
        $this->helperData = $helperData;
        $this->quoteFactory = $quoteFactory;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->regionFactory = $regionFactory;
        $this->priceHelper = $priceHelper;
        $this->shippingConfig = $shippingConfig;
        $this->logger = $logger;
    }

    /**
     * Get available shipping methods for product and location
     */
    public function getAvailableShippingMethods(
        int $productId,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null
    ): array {
        $requestId = uniqid('shipping_', true);
        
        try {
            $this->logger->info('=== Shipping Calculator: Starting ===', [
                'request_id' => $requestId,
                'product_id' => $productId,
                'country_id' => $countryId
            ]);

            // Create realistic quote for shipping calculation
            $quote = $this->createShippingQuote($productId, $countryId, $region, $postcode);
            
            // Get shipping methods using Magento's official API
            $shippingMethods = $this->collectShippingMethods($quote, $requestId);
            
            // Apply admin filtering
            $filteredMethods = $this->helperData->filterShippingMethods($shippingMethods);

            $this->logger->info('=== Shipping Calculator: Completed ===', [
                'request_id' => $requestId,
                'methods_count' => count($filteredMethods)
            ]);

            return $filteredMethods;

        } catch (\Exception $e) {
            $this->logger->error('=== Shipping Calculator: Error ===', [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            
            return $this->getFallbackShippingMethods();
        }
    }

    /**
     * Calculate shipping cost for specific method
     */
    public function calculateShippingCost(
        int $productId,
        string $shippingMethod,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null,
        int $qty = 1
    ): float {
        try {
            // Get available methods and find the requested one
            $methods = $this->getAvailableShippingMethods($productId, $countryId, $region, $postcode);
            
            foreach ($methods as $method) {
                if ($method['code'] === $shippingMethod) {
                    return (float)$method['price'];
                }
            }

            // Check for free shipping
            $product = $this->productRepository->getById($productId);
            $subtotal = (float)$product->getFinalPrice() * $qty;
            
            if ($this->helperData->shouldApplyFreeShipping($subtotal)) {
                return 0.0;
            }

            // Return fallback price
            return $this->helperData->getFallbackShippingPrice();

        } catch (\Exception $e) {
            $this->logger->error('Error calculating shipping cost: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Create quote for shipping calculation
     */
    private function createShippingQuote(
        int $productId,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null
    ) {
        $product = $this->productRepository->getById($productId);
        $store = $this->storeManager->getStore();

        // Create quote
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setCurrency();

        // Set customer context
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail('guest@shipping-calc.local');

        // Add product to quote
        $request = new \Magento\Framework\DataObject(['qty' => 1]);
        $quote->addProduct($product, $request);

        // Set shipping address
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCountryId($countryId);
        $shippingAddress->setCity($region ?: 'Default City');
        $shippingAddress->setStreet(['123 Main Street']);
        $shippingAddress->setFirstname('Guest');
        $shippingAddress->setLastname('Customer');
        $shippingAddress->setTelephone('1234567890');
        $shippingAddress->setEmail('guest@shipping-calc.local');

        if ($region) {
            $regionId = $this->getRegionIdByName($region, $countryId);
            if ($regionId) {
                $shippingAddress->setRegionId($regionId);
            }
            $shippingAddress->setRegion($region);
        }

        if ($postcode) {
            $shippingAddress->setPostcode($postcode);
        } else {
            $shippingAddress->setPostcode('12345');
        }

        // Set weight for calculation
        $weight = $product->getWeight() ?: 1;
        $shippingAddress->setWeight($weight);

        // Collect totals
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        return $quote;
    }

    /**
     * Collect shipping methods from quote
     */
    private function collectShippingMethods($quote, string $requestId): array
    {
        $shippingAddress = $quote->getShippingAddress();
        
        // Force shipping rates collection
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->removeAllShippingRates();
        $shippingAddress->collectShippingRates();

        // Final totals collection
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        // Get collected rates
        $shippingRates = $shippingAddress->getAllShippingRates();
        $methods = [];

        foreach ($shippingRates as $rate) {
            if ($rate->getMethod() !== null && !$rate->getErrorMessage()) {
                $methodCode = $rate->getCarrier() . '_' . $rate->getMethod();
                $methods[] = [
                    'code' => $methodCode,
                    'carrier_code' => $rate->getCarrier(),
                    'method_code' => $rate->getMethod(),
                    'carrier_title' => $rate->getCarrierTitle(),
                    'title' => $rate->getMethodTitle(),
                    'price' => (float)$rate->getPrice(),
                    'price_formatted' => $this->formatPrice((float)$rate->getPrice())
                ];
            }
        }

        $this->logger->info('Shipping methods collected', [
            'request_id' => $requestId,
            'methods_count' => count($methods)
        ]);

        return $methods;
    }

    /**
     * Get fallback shipping methods
     */
    private function getFallbackShippingMethods(): array
    {
        $fallbackPrice = $this->helperData->getFallbackShippingPrice();
        
        return [[
            'code' => 'fallback_standard',
            'carrier_code' => 'fallback',
            'method_code' => 'standard',
            'carrier_title' => __('Standard Shipping'),
            'title' => __('Standard Delivery'),
            'price' => $fallbackPrice,
            'price_formatted' => $this->formatPrice($fallbackPrice)
        ]];
    }

    /**
     * Get region ID by name
     */
    private function getRegionIdByName(string $regionName, string $countryId): ?int
    {
        try {
            $region = $this->regionFactory->create();
            $region->loadByName($regionName, $countryId);
            return $region->getId() ? (int)$region->getId() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Format price
     */
    private function formatPrice(float $price): string
    {
        return $this->priceHelper->currency($price, true, false);
    }
}