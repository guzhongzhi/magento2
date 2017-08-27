<?php
namespace Commer\PaymentOptimal\Model;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\TMRobokassa\Model\Config\Source\Order\Status\Paymentreview;
use Magento\Sales\Model\Order;
use paysafe\Environment;
use paysafe\PaysafeApiClient;
use paysafe\CardPayments\AuthorizationReversal;
use paysafe\CardPayments\Refund;
use paysafe\CardPayments\Authorization;
use paysafe\CardPayments\Settlement;
use Magento\Sales\Model\Order\Payment\Transaction;
use \Magento\Payment\Model\Method\Substitution;

class OptimalVault extends \Magento\Vault\Model\Method\Vault
{
    protected $_code = self::METHOD_CODE;
    const METHOD_CODE = "optimal_vault";
    const CODE = "optimal_vault";
    const AUTH_RESPONSE_SUCCESS_CODE = "COMPLETED";
    
    public function isActive($storeId = null) {
        try {
            throw new \Exception("F");
        }catch(\Exception $ex) {
            //echo $ex->__toString();
            //die();
        }
        return true;
        
    }
    
    
    /**
     * Check whether there are CC types set in configuration
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return true;
        $v =  $this->getConfigData('cctypes', $quote ? $quote->getStoreId() : null) && parent::isAvailable($quote);
        return $v;
    }
}