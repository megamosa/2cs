<?php
/**
 * MagoArab_EasYorder Enhanced Quick Order Service - FIXED VERSION
 * Main service class - lightweight and clean
 */
declare(strict_types=1);

namespace MagoArab\EasYorder\Model;

use MagoArab\EasYorder\Api\QuickOrderServiceInterface;
use MagoArab\EasYorder\Api\Data\QuickOrderDataInterface;
use MagoArab\EasYorder\Helper\Data as HelperData;
use MagoArab\EasYorder\Model\Service\ShippingCalculator;
use MagoArab\EasYorder\Model\Service\OrderCreator;
use MagoArab\EasYorder\Model\Service\PriceCalculator;
use MagoArab\EasYorder\Model\Service\PaymentMethodProvider;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class QuickOrderService implements QuickOrderServiceInterface
{
    private $helperData;
    private $logger;
    private $shippingCalculator;
    private $orderCreator;
    private $priceCalculator;
    private $paymentMethodProvider;
    private $currentOrderAttributes = null;

    public function __construct(
        HelperData $helperData,
        LoggerInterface $logger,
        ShippingCalculator $shippingCalculator,
        OrderCreator $orderCreator,
        PriceCalculator $priceCalculator,
        PaymentMethodProvider $paymentMethodProvider
    ) {
        $this->helperData = $helperData;
        $this->logger = $logger;
        $this->shippingCalculator = $shippingCalculator;
        $this->orderCreator = $orderCreator;
        $this->priceCalculator = $priceCalculator;
        $this->paymentMethodProvider = $paymentMethodProvider;
    }

    /**
     * @inheritDoc
     */
    public function getAvailableShippingMethods(
        int $productId,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null
    ): array {
        try {
            if (!$this->helperData->isEnabled()) {
                return [];
            }

            return $this->shippingCalculator->getAvailableShippingMethods(
                $productId,
                $countryId,
                $region,
                $postcode
            );
        } catch (\Exception $e) {
            $this->logger->error('Error getting shipping methods: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getAvailablePaymentMethods(): array
    {
        try {
            if (!$this->helperData->isEnabled()) {
                return [];
            }

            return $this->paymentMethodProvider->getAvailablePaymentMethods();
        } catch (\Exception $e) {
            $this->logger->error('Error getting payment methods: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function createQuickOrder(QuickOrderDataInterface $orderData): array
    {
        try {
            if (!$this->helperData->isEnabled()) {
                throw new LocalizedException(__('Quick order is not enabled.'));
            }

            // Set selected product attributes if available
            $superAttribute = $orderData->getSuperAttribute();
            if ($superAttribute && is_array($superAttribute)) {
                $this->setSelectedProductAttributes($superAttribute);
            }

            $this->logger->info('EasYorder: Order creation started', [
                'product_id' => $orderData->getProductId(),
                'qty' => $orderData->getQty(),
                'shipping_method' => $orderData->getShippingMethod(),
                'payment_method' => $orderData->getPaymentMethod()
            ]);

            return $this->orderCreator->createOrder($orderData, $this->currentOrderAttributes);

        } catch (LocalizedException $e) {
            $this->logger->error('Quick order creation failed: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error in quick order creation: ' . $e->getMessage());
            throw new LocalizedException(__('An unexpected error occurred. Please try again.'));
        }
    }

    /**
     * @inheritDoc
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
            return $this->shippingCalculator->calculateShippingCost(
                $productId,
                $shippingMethod,
                $countryId,
                $region,
                $postcode,
                $qty
            );
        } catch (\Exception $e) {
            $this->logger->error('Error calculating shipping cost: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * @inheritDoc
     */
    public function calculateOrderTotalWithDynamicRules(
        int $productId,
        int $qty,
        string $shippingMethod,
        string $countryId,
        ?string $region = null,
        ?string $postcode = null
    ): array {
        try {
            return $this->priceCalculator->calculateOrderTotalWithDynamicRules(
                $productId,
                $qty,
                $shippingMethod,
                $countryId,
                $region,
                $postcode,
                $this->currentOrderAttributes
            );
        } catch (\Exception $e) {
            $this->logger->error('Error in dynamic calculation: ' . $e->getMessage());
            return $this->getFallbackCalculation($productId, $qty, $shippingMethod);
        }
    }

    /**
     * Set selected product attributes for configurable products
     */
    public function setSelectedProductAttributes(array $attributes): void
    {
        $this->currentOrderAttributes = $attributes;
    }

    /**
     * Get fallback calculation
     */
    private function getFallbackCalculation(int $productId, int $qty, string $shippingMethod): array
    {
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