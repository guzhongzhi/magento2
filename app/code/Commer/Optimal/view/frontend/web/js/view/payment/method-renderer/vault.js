/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define([
    'jquery',
    'Magento_Vault/js/view/payment/method-renderer/vault',
    'Commer_Optimal/js/view/payment/adapter',
    'Magento_Ui/js/model/messageList',
    'Magento_Checkout/js/model/full-screen-loader'
], function ($, VaultComponent, Optimal, globalMessageList, fullScreenLoader) {
    'use strict';

    return VaultComponent.extend({
        defaults: {
            template: 'Commer_Optimal/payment/vault',
            modules: {
                hostedFields: '${ $.parentName }.optimal'
            }
        },

        /**
         * Get last 4 digits of card
         * @returns {String}
         */
        getMaskedCard: function () {
            return this.details.maskedCC;
        },
        
        /**
         * Get expiration date
         * @returns {String}
         */
        getExpirationDate: function () {
            return this.details.expirationDate;
        },

        /**
         * Get card type
         * @returns {String}
         */
        getCardType: function () {
            return this.details.type;
        },
    
        /**
         * Action to place order
         * @param {String} key
         */
        placeOrder: function (key) {
            var self = this;

            if (key) {
                return self._super();
            }
            // place order on success validation
            self.validatorManager.validate(self, function () {
                return self.placeOrder('parent');
            });

            return false;
        },
        
        getData: function() {
            
            var data = {
                method: this.getCode()
            };

            data['additional_data'] = {};
            
            var vaultId = jQuery(".optimal-vault-item:checked").val();
            data['additional_data']["cc_vault"] = vaultId;
            data["additional_data"]['public_hash'] = this.publicHash;
            //data['additional_data']['public_hash'] = vaultId;//this.getToken();
            return data;
        },
        
        /**
         * Send request to get payment method nonce
         */
        getPaymentMethodNonce: function () {
            var self = this;

            fullScreenLoader.startLoader();
            $.get(self.nonceUrl, {
                'public_hash': self.publicHash
            })
                .done(function (response) {
                    fullScreenLoader.stopLoader();
                    self.hostedFields(function (formComponent) {
                        formComponent.setPaymentMethodNonce(response.paymentMethodNonce);
                        formComponent.additionalData['public_hash'] = self.publicHash;
                        formComponent.code = self.code;
                        formComponent.messageContainer = self.messageContainer;
                        formComponent.placeOrder();
                    });
                })
                .fail(function (response) {
                    var error = JSON.parse(response.responseText);

                    fullScreenLoader.stopLoader();
                    globalMessageList.addErrorMessage({
                        message: error.message
                    });
                });
        }
    });
});
