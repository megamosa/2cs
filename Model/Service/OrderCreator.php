<?php
/**
 * MagoArab_EasYorder Order Creator Service
 * Handles order creation with all business logic
 */
declare(strict_types=1);

namespace MagoArab\EasYorder\Model\Service;

use MagoArab\EasYorder\Api\Data\QuickOrderDataInterface;
use MagoArab\EasYorder\Helper\Data as HelperData;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

class OrderCreator
{
    private $helperData;
    private $quoteFactory;
    private $cartRepository;
    private $cartManagement;
    private $productRepository;
    private $storeManager;
    private $orderRepository;
    private $orderSender;
    private $regionFactory;
    private $logger;

    public function __construct(
        HelperData $helperData,
        QuoteFactory $quoteFactory,
        CartRepositoryInterface $cartRepository,
        CartManagementInterface $cartManagement,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        RegionFactory $regionFactory,
        LoggerInterface $logger
    ) {
        $this->helperData = $helperData;
        $this->quoteFactory = $quoteFactory;
        $this->cartRepository = $cartRepository;
        $this->cartManagement = $cartManagement;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->regionFactory = $regionFactory;
        $this->logger = $logger;
    }

    /**
     * Create order from quick order data
     */
    public function createOrder(QuickOrderDataInterface $orderData, ?array $selectedAttributes = null): array
    {
        try {
            $this->logger->info('=== Order Creator: Starting order creation ===', [
                'product_id' => $orderData->getProductId(),
                'qty' => $orderData->getQty(),
                'customer_name' => $orderData->getCustomerName()
            ]);

            // Create quote with product
            $quote = $this->createOrderQuote($orderData, $selectedAttributes);

            // Set customer information
            $this->setCustomerInformation($quote, $orderData);

            // Set addresses
            $this->setBillingAddress($quote, $orderData);
            $this->setShippingAddress($quote, $orderData);

            // Set shipping method
            $this->setShippingMethod($quote, $orderData);

            // Set payment method
            $this->setPaymentMethod($quote, $orderData);

            // Final totals collection
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();
            $this->cartRepository->save($quote);

            // Validate quote
            $this->validateQuote($quote);

            // Create order
            $orderId = $this->cartManagement->placeOrder($quote->getId());
            $order = $this->orderRepository->get($orderId);

            // Apply custom order status if configured
            $this->applyCustomOrderStatus($order);

            // Ensure order visibility in admin
            $this->ensureOrderVisibility($order);

            // Send email notification
            $this->sendOrderNotification($order);

            // Get order details for response
            $orderDetails = $this->getOrderDetails($order);

            $this->logger->info('=== Order Creator: Order created successfully ===', [
                'order_id' => $orderId,
                'increment_id' => $order->getIncrementId()
            ]);

            return [
                'success' => true,
                'order_id' => $orderId,
                'increment_id' => $order->getIncrementId(),
                'message' => $this->helperData->getSuccessMessage(),
                'product_details' => $orderDetails['product_details'],
                'order_total' => $orderDetails['formatted_total'],
                'redirect_url' => $this->getOrderSuccessUrl($order)
            ];

        } catch (\Exception $e) {
            $this->logger->error('=== Order Creator: Error ===', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new LocalizedException(__('Unable to create order: %1', $e->getMessage()));
        }
    }

    /**
     * Create quote with product
     */
    private function createOrderQuote(QuickOrderDataInterface $orderData, ?array $selectedAttributes = null)
    {
        $product = $this->productRepository->getById($orderData->getProductId());
        $store = $this->storeManager->getStore();

        // Create quote
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setStoreId($store->getId());
        $quote->setCurrency();

        // Set customer context
        $quote->setCustomerGroupId($this->helperData->getDefaultCustomerGroup());
        $quote->setCustomerIsGuest(true);

        // Handle configurable products
        if ($product->getTypeId() === 'configurable' && $selectedAttributes) {
            $simpleProduct = $product->getTypeInstance()->getProductByAttributes($selectedAttributes, $product);
            if ($simpleProduct) {
                $product = $this->productRepository->getById($simpleProduct->getId());
            }
        }

        // Add product to quote
        $request = new DataObject([
            'qty' => $orderData->getQty(),
            'product' => $product->getId()
        ]);

        if ($selectedAttributes) {
            $request->setData('super_attribute', $selectedAttributes);
        }

        $quote->addProduct($product, $request);

        // Initial totals collection
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        return $quote;
    }

    /**
     * Set customer information
     */
    private function setCustomerInformation($quote, QuickOrderDataInterface $orderData): void
    {
        $customerEmail = $orderData->getCustomerEmail();
        
        // Auto-generate email if not provided
        if (!$customerEmail && $this->helperData->isAutoGenerateEmailEnabled()) {
            $customerEmail = $this->helperData->generateGuestEmail($orderData->getCustomerPhone());
        }

        $quote->setCustomerEmail($customerEmail);
        $quote->setCustomerFirstname($orderData->getCustomerName());
        $quote->setCustomerLastname('');
    }

    /**
     * Set billing address
     */
    private function setBillingAddress($quote, QuickOrderDataInterface $orderData): void
    {
        $billingAddress = $quote->getBillingAddress();
        $this->setAddressData($billingAddress, $orderData, $quote->getCustomerEmail());
    }

    /**
     * Set shipping address
     */
    private function setShippingAddress($quote, QuickOrderDataInterface $orderData): void
    {
        $shippingAddress = $quote->getShippingAddress();
        $this->setAddressData($shippingAddress, $orderData, $quote->getCustomerEmail());
    }

    /**
     * Set address data
     */
    private function setAddressData($address, QuickOrderDataInterface $orderData, string $customerEmail): void
    {
        // Split customer name
        $fullName = trim($orderData->getCustomerName());
        $nameParts = explode(' ', $fullName, 2);
        $firstName = $nameParts[0];
        $lastName = isset($nameParts[1]) ? $nameParts[1] : $firstName;

        $address->setFirstname($firstName);
        $address->setLastname($lastName);

        // Handle street address
        $streetAddress = $orderData->getAddress();
        if (strpos($streetAddress, ',') !== false) {
            $streetLines = array_map('trim', explode(',', $streetAddress));
        } else {
            $streetLines = [$streetAddress];
        }
        $address->setStreet($streetLines);

        $address->setCity($orderData->getCity());
        $address->setCountryId($orderData->getCountryId());
        $address->setTelephone($this->helperData->formatPhoneNumber($orderData->getCustomerPhone()));
        $address->setEmail($customerEmail);
        $address->setCompany('');

        // Set region
        if ($orderData->getRegion()) {
            $regionId = $this->getRegionIdByName($orderData->getRegion(), $orderData->getCountryId());
            if ($regionId) {
                $address->setRegionId($regionId);
            }
            $address->setRegion($orderData->getRegion());
        }

        // Set postcode
        if ($orderData->getPostcode()) {
            $address->setPostcode($orderData->getPostcode());
        }
    }

    /**
     * Set shipping method
     */
    private function setShippingMethod($quote, QuickOrderDataInterface $orderData): void
    {
        $shippingAddress = $quote->getShippingAddress();
        
        // Force shipping rates collection
        $shippingAddress->setCollectShippingRates(true);
        $shippingAddress->removeAllShippingRates();
        $shippingAddress->collectShippingRates();

        $requestedMethod = $orderData->getShippingMethod();
        $availableRates = $shippingAddress->getAllShippingRates();

        foreach ($availableRates as $rate) {
            $rateCode = $rate->getCarrier() . '_' . $rate->getMethod();
            if ($rateCode === $requestedMethod || $rate->getCarrier() === $requestedMethod) {
                $shippingAddress->setShippingMethod($rateCode);
                $shippingAddress->setShippingDescription($rate->getCarrierTitle() . ' - ' . $rate->getMethodTitle());
                return;
            }
        }

        // Use first available rate if requested method not found
        if (!empty($availableRates)) {
            $firstRate = reset($availableRates);
            $rateCode = $firstRate->getCarrier() . '_' . $firstRate->getMethod();
            $shippingAddress->setShippingMethod($rateCode);
            $shippingAddress->setShippingDescription($firstRate->getCarrierTitle() . ' - ' . $firstRate->getMethodTitle());
        }
    }

    /**
     * Set payment method
     */
    private function setPaymentMethod($quote, QuickOrderDataInterface $orderData): void
    {
        $payment = $quote->getPayment();
        $payment->importData(['method' => $orderData->getPaymentMethod()]);
    }

    /**
     * Validate quote before order creation
     */
    private function validateQuote($quote): void
    {
        $shippingAddress = $quote->getShippingAddress();
        $payment = $quote->getPayment();

        if (!$shippingAddress->getShippingMethod()) {
            throw new LocalizedException(__('Shipping method is missing.'));
        }

        if (!$payment->getMethod()) {
            throw new LocalizedException(__('Payment method is missing.'));
        }

        if (!$quote->getItemsCount()) {
            throw new LocalizedException(__('Quote has no items.'));
        }
    }

    /**
     * Apply custom order status
     */
    private function applyCustomOrderStatus($order): void
    {
        try {
            $customStatus = $this->helperData->getDefaultOrderStatus();
            $customState = $this->helperData->getDefaultOrderState();

            if ($customStatus) {
                $order->setStatus($customStatus);
            }

            if ($customState) {
                $order->setState($customState);
            }

            if ($customStatus || $customState) {
                $this->orderRepository->save($order);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not apply custom order status: ' . $e->getMessage());
        }
    }

    /**
     * Ensure order visibility in admin
     */
    private function ensureOrderVisibility($order): void
    {
        try {
            // Force order save multiple times to ensure persistence
            $this->orderRepository->save($order);

            // Manual grid insertion for immediate visibility
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $connection = $objectManager->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
            
            $gridTable = $connection->getTableName('sales_order_grid');
            
            // Check if record exists in grid
            $exists = $connection->fetchOne(
                "SELECT entity_id FROM {$gridTable} WHERE entity_id = ?",
                [$order->getId()]
            );

            if (!$exists) {
                $gridData = [
                    'entity_id' => $order->getId(),
                    'status' => $order->getStatus(),
                    'store_id' => $order->getStoreId(),
                    'customer_id' => $order->getCustomerId(),
                    'base_grand_total' => $order->getBaseGrandTotal(),
                    'grand_total' => $order->getGrandTotal(),
                    'increment_id' => $order->getIncrementId(),
                    'base_currency_code' => $order->getBaseCurrencyCode(),
                    'order_currency_code' => $order->getOrderCurrencyCode(),
                    'created_at' => $order->getCreatedAt(),
                    'updated_at' => $order->getUpdatedAt(),
                    'customer_email' => $order->getCustomerEmail(),
                    'customer_name' => $order->getCustomerName()
                ];
                $connection->insert($gridTable, $gridData);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error ensuring order visibility: ' . $e->getMessage());
        }
    }

    /**
     * Send order notification
     */
    private function sendOrderNotification($order): void
    {
        if ($this->helperData->isEmailNotificationEnabled()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to send order email: ' . $e->getMessage());
            }
        }
    }

    /**
     * Get order details for response
     */
    private function getOrderDetails($order): array
    {
        $productDetails = [];
        $formattedTotal = '';

        try {
            foreach ($order->getAllVisibleItems() as $item) {
                $unitPrice = (float)$item->getPrice();
                $totalPrice = (float)$item->getRowTotal();
                $qty = (int)$item->getQtyOrdered();

                $details = [
                    'name' => $item->getName(),
                    'sku' => $item->getSku(),
                    'qty' => $qty,
                    'price' => $this->formatPrice($unitPrice),
                    'row_total' => $this->formatPrice($totalPrice),
                    'product_type' => $item->getProductType()
                ];

                // Add configurable product attributes
                $productOptions = $item->getProductOptions();
                if (isset($productOptions['attributes_info']) && is_array($productOptions['attributes_info'])) {
                    $details['attributes'] = [];
                    foreach ($productOptions['attributes_info'] as $attribute) {
                        $details['attributes'][] = [
                            'label' => $attribute['label'],
                            'value' => $attribute['value']
                        ];
                    }
                }

                $productDetails[] = $details;
            }

            $formattedTotal = $this->formatPrice($order->getGrandTotal());

        } catch (\Exception $e) {
            $this->logger->error('Error getting order details: ' . $e->getMessage());
        }

        return [
            'product_details' => $productDetails,
            'formatted_total' => $formattedTotal
        ];
    }

    /**
     * Get order success URL
     */
    private function getOrderSuccessUrl($order): string
    {
        return $this->storeManager->getStore()->getUrl('checkout/onepage/success', [
            '_query' => ['order_id' => $order->getId()]
        ]);
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
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $priceHelper = $objectManager->get(\Magento\Framework\Pricing\Helper\Data::class);
        return $priceHelper->currency($price, true, false);
    }
}