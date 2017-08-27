<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\Optimal\Gateway\Http\Client;

use Commer\Optimal\Gateway\Request\PaymentDataBuilder;

/**
 * Class \Commer\Optimal\Gateway\Http\Client\TransactionRefund
 *
 */
class TransactionRefund extends AbstractTransaction
{
    /**
     * Process http request
     * @param array $data
     * @return \Braintree\Result\Error|\Braintree\Result\Successful
     */
    protected function process(array $data, $payment = null)
    {
        $this->adapter->refund(
            $data['transaction_id'],
            $data[PaymentDataBuilder::AMOUNT],
            $payment
        );
    }
}
