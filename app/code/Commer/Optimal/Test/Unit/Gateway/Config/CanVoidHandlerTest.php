<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\Optimal\Test\Unit\Gateway\Config;

use Commer\Optimal\Gateway\Config\CanVoidHandler;
use Commer\Optimal\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Payment;

class CanVoidHandlerTest extends \PHPUnit\Framework\TestCase
{
    public function testHandleNotOrderPayment()
    {
        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $subject = [
            'payment' => $paymentDO
        ];

        $subjectReader = $this->getMockBuilder(SubjectReader::class)
            ->disableOriginalConstructor()
            ->getMock();

        $subjectReader->expects(static::once())
            ->method('readPayment')
            ->willReturn($paymentDO);

        $paymentMock = $this->createMock(InfoInterface::class);

        $paymentDO->expects(static::once())
            ->method('getPayment')
            ->willReturn($paymentMock);

        $voidHandler = new CanVoidHandler($subjectReader);

        static::assertFalse($voidHandler->handle($subject));
    }

    public function testHandleSomeAmoutWasPaid()
    {
        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $subject = [
            'payment' => $paymentDO
        ];

        $subjectReader = $this->getMockBuilder(SubjectReader::class)
            ->disableOriginalConstructor()
            ->getMock();

        $subjectReader->expects(static::once())
            ->method('readPayment')
            ->willReturn($paymentDO);

        $paymentMock = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();

        $paymentDO->expects(static::once())
            ->method('getPayment')
            ->willReturn($paymentMock);

        $paymentMock->expects(static::once())
            ->method('getAmountPaid')
            ->willReturn(1.00);

        $voidHandler = new CanVoidHandler($subjectReader);

        static::assertFalse($voidHandler->handle($subject));
    }
}
