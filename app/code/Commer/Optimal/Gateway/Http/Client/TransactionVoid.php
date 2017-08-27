<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\Optimal\Gateway\Http\Client;

/**
 * Class \Commer\Optimal\Gateway\Http\Client\TransactionVoid
 *
 */
class TransactionVoid extends AbstractTransaction
{
    /**
     * Process http request
     * @param array $data
     * @return \Braintree\Result\Error|\Braintree\Result\Successful
     */
    protected function process(array $data, $payment = null)
    {
        $this->adapter->void($data['transaction_id'],$payment);
    }
}
