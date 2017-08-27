/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
define(
    [
        'underscore',
        'jquery',
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Checkout/js/model/quote',
        'Magento_Braintree/js/view/payment/adapter',
        'mage/translate',
        'Magento_Braintree/js/validator',
        'Magento_Braintree/js/view/payment/validator-handler',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Vault/js/view/payment/vault-enabler'

    ],
    function (
        _,
        $,
        Component,
        quote,
        braintree,
        $t,
        validator,
        validatorManager,
        fullScreenLoader,
        VaultEnabler
    
    ) {
        'use strict';
        var c = Component.extend({
            
            /**
             * @returns {exports.initialize}
             */
            initialize: function () {
                this._super();
                this.vaultEnabler = new VaultEnabler();
                this.vaultEnabler.setPaymentCode(this.getVaultCode());
                return this;
            },
            
            /**
             * @returns {Bool}
             */
            isVaultEnabled: function () {
                return window.checkoutConfig.payment[this.getCode()].vaultEnabled;
            },
            
            /**
             * @returns {String}
             */
            getVaultCode: function () {
                return window.checkoutConfig.payment[this.getCode()].vaultCode;
            },
            
            defaults: {
                template: 'Commer_PaymentOptimal/payment/optimal',
                allowSaveCard:""
            },
            
            /** @inheritdoc */
            initObservable: function () {
                this._super()
                    .observe([
                        'allowSaveCard'
                    ]);

                return this;
            },
            
            getCode: function () {
                return 'optimal';
            },
            /**
             * Get payment icons
             * @param {String} type
             * @returns {Boolean}
             */
            getIcons: function (type) {
                return window.checkoutConfig.payment.ccform.icons.hasOwnProperty(type) ?
                    window.checkoutConfig.payment.ccform.icons[type]
                    : false;
            },
            
            
            /**
             * Get list of available credit card types values
             * @returns {Object}
             */
            getCcAvailableTypesValues: function () {
                return _.map(this.getCcAvailableTypes(), function (value, key) {
                    return {
                        'value': key,
                        'type': value
                    };
                });
            },
        
            /**
             * Get list of available CC types
             *
             * @returns {Object}
             */
            getCcAvailableTypes: function () {
                return window.checkoutConfig.payment.optimal.availableCardTypes;
                var availableTypes = validator.getAvailableCardTypes(),
                    billingAddress = quote.billingAddress(),
                    billingCountryId;

                this.lastBillingAddress = quote.shippingAddress();

                if (!billingAddress) {
                    billingAddress = this.lastBillingAddress;
                }

                billingCountryId = billingAddress.countryId;

                if (billingCountryId && validator.getCountrySpecificCardTypes(billingCountryId)) {

                    return validator.collectTypes(
                        availableTypes, validator.getCountrySpecificCardTypes(billingCountryId)
                    );
                }

                return availableTypes;
            },

                
            /**
             * Get data
             * @returns {Object}
             */
            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'cc_cid': this.creditCardVerificationNumber(),
                        'cc_ss_start_month': this.creditCardSsStartMonth(),
                        'cc_ss_start_year': this.creditCardSsStartYear(),
                        'cc_ss_issue': this.creditCardSsIssue(),
                        'cc_type': this.creditCardType(),
                        'cc_exp_year': this.creditCardExpYear(),
                        'cc_exp_month': this.creditCardExpMonth(),
                        'cc_number': this.creditCardNumber(),
                        'allow_save_card': this.getAllowSaveCard(),
                        'vault_id': this.getSelectedVaultId()
                        
                    }
                };
            },
            getSelectedVaultId: function() {
                return $("#optimal_vault_select").val();
            },
            getAllowSaveCard: function(){
                return $("#optimal_enable_vault").prop("checked") == true ? 1 : 0;
            },
            changeVaultItem :function() {
                var selectedId = parseInt($("#optimal_vault_select").val());
                if(selectedId == 0) {
                    jQuery(".newcredit-card-container input").removeAttr("disabled");
                    jQuery(".newcredit-card-container select").removeAttr("disabled");
                    jQuery(".newcredit-card-container").show();
                }else {
                    jQuery(".newcredit-card-container").hide();
                    jQuery(".newcredit-card-container input").prop("disabled","disabled");
                    jQuery(".newcredit-card-container select").prop("disabled","disabled");
                }
                
            },
            getVaultItems: function() {
                var vaultItems= window.checkoutConfig.payment.optimal.vaultPaymentItems;
                console.log(vaultItems);
                var newData = new Array({
                    "value":"0",
                    "label":"Use New credit card",
                });
                _.map(vaultItems, function (value, label) {
                    newData.push( {
                        'value': label,
                        'label': value
                    });
                });
                return newData;
            },
            
            getCcMonths:function(){
                
                return window.checkoutConfig.payment.optimal.months;
                
            },
            /**
             * Get list of available month values
             * @returns {Object}
             */
            getCcMonthsValues: function () {
                return _.map(this.getCcMonths(), function (value, key) {
                    return {
                        'value': key,
                        'month': value
                    };
                });
            },

            /**
             * Get list of available year values
             * @returns {Object}
             */
            getCcYearsValues: function () {
                return _.map(this.getCcYears(), function (value, key) {
                    return {
                        'value': value,
                        'year': value
                    };
                });
            },
            getCcYears: function() {
                var years = new Array();
                var startYear = window.checkoutConfig.payment.optimal.startYear;
                var endYear = window.checkoutConfig.payment.optimal.endYear;
                while(startYear<= endYear) {
                    
                    years.push(startYear);
                    startYear++;
                }
                return years;
            },
            /**
             * @deprecated
             * @returns {Object}
             */
            getSsStartYearsValues: function () {
                var c = [2017];
                return _.map(c, function (value, key) {
                    return {
                        'value': key,
                        'year': value
                    };
                });
            },
            placeOrderHandler: null,
            validateHandler: null,
            hasVerification: function() {
                return true;
            },
            getCvvImageHtml:function(){
                
                return "AAA";
                
            },
            /**
             * @param {Object} handler
             */
            setPlaceOrderHandler: function (handler) {
                this.placeOrderHandler = handler;
            },

            /**
             * @param {Object} handler
             */
            setValidateHandler: function (handler) {
                this.validateHandler = handler;
            },

            /**
             * @returns {Object}
             */
            context: function () {
                return this;
            },

            /**
             * @returns {Boolean}
             */
            isShowLegend: function () {
                return true;
            },


            /**
             * @returns {Boolean}
             */
            isActive: function () {
                return true;
            },	
        
            /**
             * @returns {Boolean}
             */
            isShowLegend: function () {
                return true;
            },
            
            
            /**
             * Get available credit card type by code
             * @param {String} code
             * @returns {String}
             */
            getCcTypeTitleByCode: function (code) {
                var title = '',
                    keyValue = 'value',
                    keyType = 'type';

                _.each(this.getCcAvailableTypesValues(), function (value) {
                    if (value[keyValue] === code) {
                        title = value[keyType];
                    }
                });

                return title;
            },

            /**
             * Prepare credit card number to output
             * @param {String} number
             * @returns {String}
             */
            formatDisplayCcNumber: function (number) {
                return 'xxxx-' + number.substr(-4);
            },

            /**
             * Get credit card details
             * @returns {Array}
             */
            getInfo: function () {
                return [
                    {
                        'name': 'Credit Card Type', value: this.getCcTypeTitleByCode(this.creditCardType())
                    },
                    {
                        'name': 'Credit Card Number', value: this.formatDisplayCcNumber(this.creditCardNumber())
                    }
                ];
            },
        
            /**
             * Get value of instruction field.
             * @returns {String}
             */
            getInstructions: function () {
                return "";
                console.log("try to find instructions:"+this.item.method);
                console.log(this.item);
                //return window.checkoutConfig.payment.instructions[this.item.method];
            }
        });
        return c;
    }
);
