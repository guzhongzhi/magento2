<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\Optimal\Test\Unit\Helper;

use Commer\Optimal\Helper\CcType;
use Commer\Optimal\Model\Adminhtml\Source\CcType as CcTypeSource;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class CcTypeTest
 */
class CcTypeTest extends \PHPUnit\Framework\TestCase
{

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var \Commer\Optimal\Helper\CcType
     */
    private $helper;

    /** @var \Commer\Optimal\Model\Adminhtml\Source\CcType|\PHPUnit_Framework_MockObject_MockObject */
    private $ccTypeSource;

    protected function setUp()
    {
        $this->objectManager = new ObjectManager($this);

        $this->ccTypeSource = $this->getMockBuilder(CcTypeSource::class)
            ->disableOriginalConstructor()
            ->setMethods(['toOptionArray'])
            ->getMock();

        $this->helper = $this->objectManager->getObject(CcType::class, [
            'ccTypeSource' => $this->ccTypeSource
        ]);
    }

    /**
     * @covers \Commer\Optimal\Helper\CcType::getCcTypes
     */
    public function testGetCcTypes()
    {
        $this->ccTypeSource->expects(static::once())
            ->method('toOptionArray')
            ->willReturn([
                'label' => 'VISA', 'value' => 'VI'
            ]);

        $this->helper->getCcTypes();

        $this->ccTypeSource->expects(static::never())
            ->method('toOptionArray');

        $this->helper->getCcTypes();
    }
}
