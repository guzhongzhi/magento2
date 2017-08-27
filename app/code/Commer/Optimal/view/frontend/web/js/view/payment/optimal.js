/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';

        var config = window.checkoutConfig.payment,
            braintreeType = 'optimal',
            payPalType = 'optimal_paypal';

        if (config[braintreeType].isActive) {
            rendererList.push(
                {
                    type: braintreeType,
                    component: 'Commer_Optimal/js/view/payment/method-renderer/hosted-fields'
                }
            );
        }

        /** Add view logic here if needed */
        return Component.extend({});
    }
);
