<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Used in creating options for Yes|No|Specified config value selection
 *
 */
namespace Commer\PaymentOptimal\Model\Config\Source;

/**
 * @api
 */
class Environment implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'production', 'label' => __('Production')],
            ['value' => 'develop', 'label' => __('Develop')],
        ];
    }
}
