<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Commer\Optimal\Gateway\Command;

use Exception;
use Commer\Optimal\Gateway\Helper\SubjectReader;
use Commer\Optimal\Gateway\Validator\PaymentNonceResponseValidator;
use Commer\Optimal\Model\Adapter\OptimalAdapter;
use Magento\Payment\Gateway\Command;
use Magento\Payment\Gateway\Command\Result\ArrayResultFactory;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;

/**
 * Class GetPaymentNonceCommand
 */
class GetPaymentNonceCommand implements CommandInterface
{

    /**
     * @var PaymentTokenManagementInterface
     */
    private $tokenManagement;

    /**
     * @var OptimalAdapter
     */
    private $adapter;

    /**
     * @var ArrayResultFactory
     */
    private $resultFactory;

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @var PaymentNonceResponseValidator
     */
    private $responseValidator;

    /**
     * @param PaymentTokenManagementInterface $tokenManagement
     * @param OptimalAdapter $adapter
     * @param ArrayResultFactory $resultFactory
     * @param SubjectReader $subjectReader
     * @param PaymentNonceResponseValidator $responseValidator
     */
    public function __construct(
        PaymentTokenManagementInterface $tokenManagement,
        OptimalAdapter $adapter,
        ArrayResultFactory $resultFactory,
        SubjectReader $subjectReader,
        PaymentNonceResponseValidator $responseValidator
    ) {
        $this->tokenManagement = $tokenManagement;
        $this->adapter = $adapter;
        $this->resultFactory = $resultFactory;
        $this->subjectReader = $subjectReader;
        $this->responseValidator = $responseValidator;
    }

    /**
     * @inheritdoc
     * @throws \Exception
     */
    public function execute(array $commandSubject)
    {
        $publicHash = $this->subjectReader->readPublicHash($commandSubject);
        $customerId = $this->subjectReader->readCustomerId($commandSubject);
        $paymentToken = $this->tokenManagement->getByPublicHash($publicHash, $customerId);
        if (!$paymentToken) {
            throw new Exception('No available payment tokens');
        }

        $data = $this->adapter->createNonce($paymentToken->getGatewayToken());
        $result = $this->responseValidator->validate(['response' => ['object' => $data]]);

        if (!$result->isValid()) {
            throw new Exception(__(implode("\n", $result->getFailsDescription())));
        }

        return $this->resultFactory->create(['array' => ['paymentMethodNonce' => $data->paymentMethodNonce->nonce]]);
    }
}
