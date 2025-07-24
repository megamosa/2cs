<?php
/**
 * MagoArab_EasYorder Helper Data - FIXED VERSION
 * Fixed all undefined property issues and optimized performance
 */
declare(strict_types=1);

namespace MagoArab\EasYorder\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Data extends AbstractHelper
{
    // General Settings
    private const XML_PATH_ENABLED = 'magoarab_easyorder/general/enabled';
    private const XML_PATH_FORM_TITLE = 'magoarab_easyorder/general/form_title';
    private const XML_PATH_SUCCESS_MESSAGE = 'magoarab_easyorder/general/success_message';
    private const XML_PATH_EMAIL_NOTIFICATION = 'magoarab_easyorder/general/send_email_notification';
    private const XML_PATH_CUSTOMER_GROUP = 'magoarab_easyorder/general/default_customer_group';
    private const XML_PATH_FORM_POSITION = 'magoarab_easyorder/general/form_position';
    private const XML_PATH_AUTO_GENERATE_EMAIL = 'magoarab_easyorder/general/auto_generate_email';
    private const XML_PATH_PHONE_VALIDATION = 'magoarab_easyorder/general/phone_validation';
    private const XML_PATH_EMAIL_DOMAIN = 'magoarab_easyorder/general/email_domain';
    
    // Form Fields Settings
    private const XML_PATH_REQUIRE_EMAIL = 'magoarab_easyorder/form_fields/require_email';
    private const XML_PATH_REQUIRE_POSTCODE = 'magoarab_easyorder/form_fields/require_postcode';
    private const XML_PATH_REQUIRE_REGION = 'magoarab_easyorder/form_fields/require_region';
    private const XML_PATH_SHOW_STREET_2 = 'magoarab_easyorder/form_fields/show_street_2';
    private const XML_PATH_REQUIRE_CITY = 'magoarab_easyorder/form_fields/require_city';
    private const XML_PATH_CUSTOM_CSS = 'magoarab_easyorder/form_fields/custom_css';
    private const XML_PATH_REGION_FIELD_TYPE = 'magoarab_easyorder/form_fields/region_field_type';
    private const XML_PATH_POSTCODE_FIELD_TYPE = 'magoarab_easyorder/form_fields/postcode_field_type';
    
    // Postcode Generation Settings
    private const XML_PATH_AUTO_GENERATE_POSTCODE = 'magoarab_easyorder/postcode_generation/auto_generate_postcode';
    private const XML_PATH_POSTCODE_GENERATION_METHOD = 'magoarab_easyorder/postcode_generation/postcode_generation_method';
    
    // Shipping & Payment Settings
    private const XML_PATH_ENABLED_SHIPPING_METHODS = 'magoarab_easyorder/shipping_payment/enabled_shipping_methods';
    private const XML_PATH_SHIPPING_METHOD_PRIORITY = 'magoarab_easyorder/shipping_payment/shipping_method_priority';
    private const XML_PATH_FALLBACK_SHIPPING_PRICE = 'magoarab_easyorder/shipping_payment/fallback_shipping_price';
    private const XML_PATH_ENABLED_PAYMENT_METHODS = 'magoarab_easyorder/shipping_payment/enabled_payment_methods';
    private const XML_PATH_DEFAULT_PAYMENT_METHOD = 'magoarab_easyorder/shipping_payment/default_payment_method';
    private const XML_PATH_PAYMENT_METHOD_PRIORITY = 'magoarab_easyorder/shipping_payment/payment_method_priority';
    private const XML_PATH_DEFAULT_ORDER_STATUS = 'magoarab_easyorder/shipping_payment/default_order_status';
    private const XML_PATH_DEFAULT_ORDER_STATE = 'magoarab_easyorder/shipping_payment/default_order_state';
    
    // Legacy Settings
    private const XML_PATH_DEFAULT_SHIPPING_PRICE = 'magoarab_easyorder/shipping/default_shipping_price';
    private const XML_PATH_FREE_SHIPPING_THRESHOLD = 'magoarab_easyorder/shipping/free_shipping_threshold';

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * Constructor
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->storeManager = $storeManager;
    }

    /**
     * Check if module is enabled
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get form title
     */
    public function getFormTitle(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_FORM_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get success message
     */
    public function getSuccessMessage(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_SUCCESS_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if email notification is enabled
     */
    public function isEmailNotificationEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EMAIL_NOTIFICATION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get default customer group
     */
    public function getDefaultCustomerGroup(?int $storeId = null): int
    {
        return (int)$this->scopeConfig->getValue(
            self::XML_PATH_CUSTOMER_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if auto generate email is enabled
     */
    public function isAutoGenerateEmailEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_GENERATE_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get email domain for auto-generated emails
     */
    public function getEmailDomain(?int $storeId = null): string
    {
        $domain = $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_DOMAIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $domain ?: 'easypay.com';
    }

    /**
     * Check if phone validation is enabled
     */
    public function isPhoneValidationEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PHONE_VALIDATION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if email field is required
     */
    public function isEmailRequired(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if postcode field is required
     */
    public function isPostcodeRequired(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_POSTCODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if region field is required
     */
    public function isRegionRequired(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_REGION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if second street line should be shown
     */
    public function showStreet2(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_STREET_2,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if city field is required
     */
    public function isCityRequired(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_CITY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get postcode field type
     */
    public function getPostcodeFieldType(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_POSTCODE_FIELD_TYPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'optional';
    }

    /**
     * Check if region field should be hidden
     */
    public function isRegionFieldHidden(?int $storeId = null): bool
    {
        return $this->getRegionFieldType($storeId) === 'hidden';
    }

    /**
     * Get region field type
     */
    public function getRegionFieldType(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_REGION_FIELD_TYPE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'visible';
    }

    /**
     * Get custom CSS for quick order form
     */
    public function getCustomCss(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_CUSTOM_CSS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get fallback shipping price with enhanced logic
     */
    public function getFallbackShippingPrice(?int $storeId = null): float
    {
        // Try fallback shipping price first
        $fallbackPrice = $this->scopeConfig->getValue(
            self::XML_PATH_FALLBACK_SHIPPING_PRICE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if ($fallbackPrice && $fallbackPrice > 0) {
            return (float)$fallbackPrice;
        }
        
        // Try legacy default shipping price
        $defaultPrice = $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_SHIPPING_PRICE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if ($defaultPrice && $defaultPrice > 0) {
            return (float)$defaultPrice;
        }
        
        // Try flatrate price
        $flatratePrice = $this->scopeConfig->getValue(
            'carriers/flatrate/price',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if ($flatratePrice && $flatratePrice > 0) {
            return (float)$flatratePrice;
        }
        
        // Ultimate fallback
        return 0.0; // Changed from 25.0 to 0.0 for free shipping
    }

    /**
     * Get free shipping threshold
     */
    public function getFreeShippingThreshold(?int $storeId = null): float
    {
        return (float)$this->scopeConfig->getValue(
            self::XML_PATH_FREE_SHIPPING_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if free shipping should be applied
     */
    public function shouldApplyFreeShipping(float $subtotal, ?int $storeId = null): bool
    {
        $threshold = $this->getFreeShippingThreshold($storeId);
        return $threshold > 0 && $subtotal >= $threshold;
    }

    /**
     * Get enabled shipping methods
     */
    public function getEnabledShippingMethods(?int $storeId = null): array
    {
        $methods = $this->scopeConfig->getValue(
            self::XML_PATH_ENABLED_SHIPPING_METHODS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if (empty($methods)) {
            return [];
        }
        
        return explode(',', $methods);
    }

    /**
     * Get enabled payment methods
     */
    public function getEnabledPaymentMethods(?int $storeId = null): array
    {
        $methods = $this->scopeConfig->getValue(
            self::XML_PATH_ENABLED_PAYMENT_METHODS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if (empty($methods)) {
            return [];
        }
        
        return explode(',', $methods);
    }

    /**
     * Get default payment method
     */
    public function getDefaultPaymentMethod(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_PAYMENT_METHOD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get default order status
     */
    public function getDefaultOrderStatus(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_ORDER_STATUS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get default order state
     */
    public function getDefaultOrderState(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_ORDER_STATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Generate guest email from phone number
     */
    public function generateGuestEmail(string $phone): string
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $domain = $this->getEmailDomain();
        return $cleanPhone . '@' . $domain;
    }

    /**
     * Format and validate phone number
     */
    public function formatPhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters except +
        $cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Add Egyptian country code if not present
        if (!str_starts_with($cleanPhone, '+') && !str_starts_with($cleanPhone, '20')) {
            if (str_starts_with($cleanPhone, '0')) {
                $cleanPhone = '+2' . $cleanPhone;
            } else {
                $cleanPhone = '+20' . $cleanPhone;
            }
        }
        
        return $cleanPhone;
    }

    /**
     * Enhanced shipping methods filter - supports ALL extensions
     */
    public function filterShippingMethods(array $methods): array
    {
        // Simple approach: Return all methods without filtering
        // This ensures compatibility with ALL shipping extensions
        return $methods;
    }

    /**
     * Enhanced payment methods filter - supports ALL extensions  
     */
    public function filterPaymentMethods(array $methods): array
    {
        $enabledMethods = $this->getEnabledPaymentMethods();
        
        // Filter enabled methods
        if (!empty($enabledMethods)) {
            $methods = array_filter($methods, function($method) use ($enabledMethods) {
                return in_array($method['code'], $enabledMethods);
            });
        }
        
        return $methods;
    }

    /**
     * Get default country from Magento configuration
     */
    public function getDefaultCountry(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(
            'general/country/default',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'EG';
    }
}