/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list',
        'jquery'
    ],
    function (
        Component,
        rendererList,
        $
    ) {
        'use strict';
        rendererList.push(
           
            {
                type: 'optimal',
                component: 'Commer_PaymentOptimal/js/view/payment/method-renderer/optimal-method'
            }
            
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);