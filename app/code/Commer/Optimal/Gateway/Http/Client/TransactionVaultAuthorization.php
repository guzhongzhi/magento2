<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\Optimal\Gateway\Http\Client;
use Commer\Optimal\Observer\DataAssignObserver;

/**
 * Class TransactionSale
 */
class TransactionVaultAuthorization extends AbstractTransaction
{
    /**
     * @inheritdoc
     */
    protected function process(array $data, $paymentDataObject = null)
    {
        $payment = $paymentDataObject->getPayment();
        $order = $paymentDataObject->getOrder();
        
        $method = $payment->getMethodInstance();

        $lastPaymentInfo = DataAssignObserver::getLastPaymentInfo();
        $data["payment"] = $lastPaymentInfo;

        return $this->adapter->authorize($data, $method, $payment, $order,$isCapture = false);
    }
}
