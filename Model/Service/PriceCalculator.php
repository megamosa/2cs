<?php
/**
 * MagoArab_EasYorder Price Calculator Service
 * Handles dynamic pricing with catalog and cart rules
 */
declare(strict_types=1);

namespace MagoArab\EasYorder\Model\Service;

use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\DataObject;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

class PriceCalculator
{
    private $helperData;
    private $quoteFactory;
    private $cartRepository;
    private $productRepository;
    private $storeManager;
    private $priceHelper;
    private $catalogRuleResource;
    private $timezone;
    private $logger;

    public function __construct(
        HelperData $helperData,
        QuoteFactory $quoteFactory,
        CartRepositoryInterface $cartRepository,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        PriceHelper $priceHelper,
        CatalogRuleResource $catalogRuleResource,
        TimezoneInterface $timezone,
        LoggerInterface $logger
    ) {
        $this->helperData = $helperData;
        $this->quoteFactory = $quoteFactory;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->priceHelper = $priceHelper;
        $this->catalogRuleResource = $catalogRuleResource;
        $this->timezone = $timezone;
        $this->logger = $logger;
    }

    /**
     * Calculate order total with dynamic rules
     */
    public function calculateOrderTotalWithDynamicRules(
        int $productId,
        int $qty,
        string $shippingMethod,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null,
        ?array $selectedAttributes = null
    ): array {
        try {
            $this->logger->info('=== Price Calculator: Starting calculation ===', [
                'product_id' => $productId,
                'qty' => $qty,
                'shipping_method' => $shippingMethod
            ]);

            // Create clean quote for calculation
            $quote = $this->createCalculationQuote($productId, $countryId, $region, $postcode, $qty, $selectedAttributes);

            // Set shipping method
            $this->setShippingMethodOnQuote($quote, $shippingMethod);

            // Apply catalog rules
            $this->applyCatalogRules($quote);

            // Apply cart rules
            $this->applyCartRules($quote);

            // Final totals collection
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            // Extract results
            $result = $this->extractCalculationResults($quote);

            $this->logger->info('=== Price Calculator: Calculation completed ===', [
                'product_price' => $result['product_price'],
                'total' => $result['total']
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('=== Price Calculator: Error ===', [
                'error' => $e->getMessage()
            ]);
            return $this->getFallbackCalculation($productId, $qty, $shippingMethod);
        }
    }

    /**
     * Create quote for calculation
     */
    private function createCalculationQuote(
        int $productId,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null,
        int $qty = 1,
        ?array $selectedAttributes = null
    ) {
        $product = $this->productRepository->getById($productId);
        $store = $this->storeManager->getStore();

        // Create quote
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setCurrency();

        // Set customer context for rules
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail('calc@guest.local');

        // Handle configurable products
        if ($product->getTypeId() === 'configurable' && $selectedAttributes) {
            $simpleProduct = $product->getTypeInstance()->getProductByAttributes($selectedAttributes, $product);
            if ($simpleProduct) {
                $product = $this->productRepository->getById($simpleProduct->getId());
            }
        }

        // Add product with correct quantity
        $request = new DataObject(['qty' => $qty]);
        $quote->addProduct($product, $request);

        // Set addresses for location-based rules
        $this->setCalculationAddresses($quote, $countryId, $region, $postcode);

        // Initial totals collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        return $quote;
    }

    /**
     * Set shipping method on quote
     */
    private function setShippingMethodOnQuote($quote, string $shippingMethod): void
    {
        $shippingAddress = $quote->getShippingAddress();
        
        if (!$quote->isVirtual() && $shippingAddress) {
            // Force shipping rates collection
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->removeAllShippingRates();
            $shippingAddress->collectShippingRates();

            // Set shipping method
            $shippingAddress->setShippingMethod($shippingMethod);
        }
    }

    /**
     * Apply catalog rules
     */
    private function applyCatalogRules($quote): void
    {
        try {
            $websiteId = $this->storeManager->getStore()->getWebsiteId();
            $customerGroupId = $quote->getCustomerGroupId();
            $currentDate = $this->timezone->date();

            foreach ($quote->getAllItems() as $item) {
                $product = $item->getProduct();
                if (!$product || !$product->getId()) {
                    continue;
                }

                // Get catalog rule price
                $rulePrice = $this->catalogRuleResource->getRulePrice(
                    $currentDate,
                    $websiteId,
                    $customerGroupId,
                    $product->getId()
                );

                if ($rulePrice !== false && $rulePrice !== null && $rulePrice < $product->getPrice()) {
                    $this->logger->info('Catalog rule applied', [
                        'product_id' => $product->getId(),
                        'original_price' => $product->getPrice(),
                        'rule_price' => $rulePrice
                    ]);

                    $item->setCustomPrice($rulePrice);
                    $item->setOriginalCustomPrice($rulePrice);
                    $item->getProduct()->setIsSuperMode(true);
                    $item->calcRowTotal();
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error applying catalog rules: ' . $e->getMessage());
        }
    }

    /**
     * Apply cart rules
     */
    private function applyCartRules($quote): void
    {
        try {
            // Cart rules are applied automatically during collectTotals()
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            
            $this->logger->info('Cart rules applied', [
                'subtotal' => $quote->getSubtotal(),
                'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
                'applied_rule_ids' => $quote->getAppliedRuleIds()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error applying cart rules: ' . $e->getMessage());
        }
    }

    /**
     * Set addresses for calculation
     */
    private function setCalculationAddresses($quote, string $countryId, ?string $region = null, ?string $postcode = null): void
    {
        $addressData = [
            'country_id' => $countryId,
            'region' => $region ?: 'Default Region',
            'postcode' => $postcode ?: '12345',
            'city' => 'Default City',
            'street' => ['123 Main St'],
            'firstname' => 'Guest',
            'lastname' => 'Customer',
            'telephone' => '1234567890',
            'email' => 'calc@guest.local'
        ];

        // Set billing address
        $billingAddress = $quote->getBillingAddress();
        $billingAddress->addData($addressData);

        // Set shipping address
        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->addData($addressData);
        }
    }

    /**
     * Extract calculation results
     */
    private function extractCalculationResults($quote): array
    {
        $subtotal = (float)$quote->getSubtotal();
        $grandTotal = (float)$quote->getGrandTotal();
        $shippingAmount = 0.0;
        $discountAmount = 0.0;

        // Get shipping cost
        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $shippingAmount = (float)$shippingAddress->getShippingAmount();
            
            // Check for free shipping
            if ($this->helperData->shouldApplyFreeShipping($subtotal)) {
                $shippingAmount = 0.0;
            }
        }

        // Calculate discount
        $discountAmount = $subtotal - (float)$quote->getSubtotalWithDiscount();

        // Calculate unit price
        $productPrice = $subtotal;
        $items = $quote->getAllVisibleItems();
        if (!empty($items)) {
            $item = $items[0];
            $qty = (int)$item->getQty();
            if ($qty > 0) {
                $productPrice = (float)$item->getRowTotal() / $qty;
            }
        }

        return [
            'product_price' => $productPrice,
            'subtotal' => $subtotal,
            'shipping_cost' => $shippingAmount,
            'discount_amount' => $discountAmount,
            'total' => $grandTotal,
            'applied_rule_ids' => $quote->getAppliedRuleIds() ?: '',
            'has_discount' => $discountAmount > 0,
            'coupon_code' => $quote->getCouponCode() ?: ''
        ];
    }

    /**
     * Get fallback calculation
     */
    private function getFallbackCalculation(int $productId, int $qty, string $shippingMethod): array
    {
        try {
            $product = $this->productRepository->getById($productId);
            $productPrice = (float)$product->getFinalPrice();
            $subtotal = $productPrice * $qty;
            $shippingCost = $this->helperData->getFallbackShippingPrice();

            return [
                'product_price' => $productPrice,
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount_amount' => 0.0,
                'total' => $subtotal + $shippingCost,
                'applied_rule_ids' => '',
                'has_discount' => false
            ];
        } catch (\Exception $e) {
            return [
                'product_price' => 0.0,
                'subtotal' => 0.0,
                'shipping_cost' => 0.0,
                'discount_amount' => 0.0,
                'total' => 0.0,
                'applied_rule_ids' => '',
                'has_discount' => false
            ];
        }
    }
}