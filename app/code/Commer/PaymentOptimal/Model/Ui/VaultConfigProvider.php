<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\PaymentOptimal\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentMethodListInterface;
/**
 * Class ConfigProvider
 */
class VaultConfigProvider extends \Magento\Vault\Model\Ui\VaultConfigProvider
{
    const CODE = 'optimal';

    const CC_VAULT_CODE = 'optimal_vault';
    private static $vaultCode = 'optimal_vault';
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var SessionManagerInterface
     */
    private $session;

    /**
     * @var PaymentMethodListInterface
     */
    private $vaultPaymentList;

    /**
     * VaultConfigProvider constructor.
     * @param StoreManagerInterface $storeManager
     * @param SessionManagerInterface $session
     * @since 100.1.0
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        SessionManagerInterface $session
    ) {
        $this->storeManager = $storeManager;
        $this->session = $session;
    }
    
    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     * @since 100.1.0
     */
    public function getConfig()
    {
        $availableMethods = [];
        $storeId = $this->storeManager->getStore()->getId();
        /**
         * @var \Magento\Vault\Model\PaymentMethodList $methodList
         */
        $methodList = $this->getVaultPaymentList();

        $vaultPayments = $methodList->getActiveList($storeId);

        $customerId = $this->session->getCustomerId();

        foreach ($vaultPayments as $method) {
            $availableMethods[$method->getCode()] = [
                'is_enabled' => $customerId !== null && $method->isActive($storeId)
            ];
        }
        return [
            self::$vaultCode => $availableMethods
        ];
    }
    
    
    /**
     * Get vault payment list instance
     * @return PaymentMethodListInterface
     * @deprecated 100.2.0
     */
    private function getVaultPaymentList()
    {
        if ($this->vaultPaymentList === null) {
            $this->vaultPaymentList = ObjectManager::getInstance()->get(PaymentMethodListInterface::class);
        }
        
        return $this->vaultPaymentList;
    }
}
