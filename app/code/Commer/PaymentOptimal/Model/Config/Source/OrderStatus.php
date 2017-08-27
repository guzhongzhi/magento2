<?php
namespace Commer\PaymentOptimal\Model\Config\Source;

/**
 * @api
 */
class OrderStatus implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => \Magento\Sales\Model\Order::STATE_PROCESSING, 'label' => __('Processing')],
            ['value' => \Magento\Sales\Model\Order::STATE_NEW, 'label' => __('Pending')],
        ];
    }
}
