<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\PaymentOptimal\Model\Ui;

use Magento\Braintree\Gateway\Request\PaymentDataBuilder;
use Magento\Checkout\Model\ConfigProviderInterface;
use Commer\PaymentOptimal\Model\Config;
use Magento\Braintree\Model\Adapter\BraintreeAdapter;
use Magento\Vault\Api\PaymentMethodListInterface;
use Magento\Vault\Model\CustomerTokenManagement;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class ConfigProvider
 */
class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'optimal';

    const CC_VAULT_CODE = 'optimal_vault';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var BraintreeAdapter
     */
    private $adapter;

    /**
     * @var string
     */
    private $clientToken = '';

    /**
     * Constructor
     *
     * @param Config $config
     * @param BraintreeAdapter $adapter
     */
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        CustomerTokenManagement $customerTokenManagement
    ) {
        $config->setMethodCode(self::CODE);
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->customerTokenManagement = $customerTokenManagement;
    }
    protected $vaultPaymentList;
    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'isActive' => $this->config->isActive(),
                    'clientToken' => "",
                    'ccTypesMapper' => $this->config->getCctypesMapper(),
                    'sdkUrl' => $this->config->getSdkUrl(),
                    'countrySpecificCardTypes' => $this->config->getCountrySpecificCardTypeConfig(),
                    'availableCardTypes' => $this->config->getAvailableCardTypes(),
                    'useCvv' => $this->config->isCvvEnabled(),
                    'environment' => $this->config->getEnvironment(),
                    'kountMerchantId' => $this->config->getKountMerchantId(),
                    'hasFraudProtection' => $this->config->hasFraudProtection(),
                    'merchantId' => $this->config->getMerchantId(),
                    'ccVaultCode' => self::CC_VAULT_CODE,
                    'vaultEnabled'=>true,
                    "months"=>$this->getMonths(),
                    "startYear" => date("Y"),
                    "endYear"=> (date("Y") + 10),
                    'specificCountries' => $this->config->get3DSecureSpecificCountries(),
                    "vaultPaymentItems"=>$this->getVaultList(),
                ],
                Config::CODE_3DSECURE => [
                    'enabled' => $this->config->isVerify3DSecure(),
                    'thresholdAmount' => $this->config->getThresholdAmount(),
                    'specificCountries' => $this->config->get3DSecureSpecificCountries()
                ],
            ]
        ];
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     * @since 100.1.0
     */
    public function getVaultList()
    {
        $vaultPayments = [];

        $tokens = $this->customerTokenManagement->getCustomerSessionTokens();

        foreach ($tokens as $i => $token) {
            $paymentCode = $token->getPaymentMethodCode();
            if($paymentCode  != self::CODE) {
                continue;
            }
            $str = $token->getTokenDetails();
            if(is_string($str)) {
                $str = json_decode($str, true);
            }
            $vaultPayments[$token->getEntityId()] = $str["maskedCC"];
        }
        return $vaultPayments;
    }

    protected function getMonths() {
        $data = array();
        for($i = 1;$i<=12;$i++) {
            $d = date("M",strtotime("2017-".$i."-01"));
            $data[$i] = $d;
        }
        return $data;
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
