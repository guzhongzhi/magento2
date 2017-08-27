<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\Optimal\Gateway\Http\Client;

use Commer\Optimal\Gateway\Request\CaptureDataBuilder;
use Commer\Optimal\Gateway\Request\PaymentDataBuilder;

/**
 * Class TransactionSubmitForSettlement
 */
class TransactionSubmitForSettlement extends AbstractTransaction
{
    /**
     * @inheritdoc
     */
    protected function process(array $data, $payment = null)
    {
        $this->adapter->submitForSettlement(
            $data[CaptureDataBuilder::TRANSACTION_ID],
            $data[PaymentDataBuilder::AMOUNT],
            $payment
        );
    }
}
