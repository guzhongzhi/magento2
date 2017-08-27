<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Commer\Optimal\Model\Adapter;

use Commer\Optimal\Gateway\Config\Config;

use Magento\Vault\Model\PaymentTokenFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\TMRobokassa\Model\Config\Source\Order\Status\Paymentreview;
use Magento\Sales\Model\Order;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Model\PaymentToken;
use paysafe\Environment;
use paysafe\PaysafeApiClient;
use paysafe\CardPayments\AuthorizationReversal;
use paysafe\CardPayments\Refund;
use paysafe\CardPayments\Authorization;
use paysafe\CustomerVault\Profile;
use paysafe\CustomerVault\Card;
use paysafe\CustomerVault\CardExpiry;
use paysafe\CustomerVault\Address;
use paysafe\CardPayments\Settlement;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Framework\DataObject;
use Paysafe\RequestConflictException;
use PHP_CodeSniffer\Tokenizers\PHP;

/**
 * Class OptimalAdapter
 * @codeCoverageIgnore
 */
class OptimalAdapter
{
    const METHOD_CODE = "optimal";
    const AUTH_RESPONSE_SUCCESS_CODE = "COMPLETED";
    static $data = array();
    /**
     * @var Config
     */
    private $config;
    protected $gateway;
    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;

        if( $config->isLiveEnv() ) {
            $this->gateway = new PaysafeApiClient($config->getUsername(),$config->getPassword(), Environment::LIVE, $config->getAccountId());
        } else {
            $this->gateway = new PaysafeApiClient($config->getUsername(),$config->getPassword(), Environment::TEST, $config->getAccountId());
        }
    }

    public function authorize($data, $method, $payment, \Magento\Payment\Gateway\Data\Order\OrderAdapter $orderAdapter,$isCaptured=false) {
        /**
         * @var \Magento\Sales\Model\Order\Payment\Transaction\Manager $transManager
         * @var \Magento\Sales\Model\Order\Payment\Transaction\Builder $transBuilder
         * @var \Magento\Sales\Model\Order\Payment\Transaction $trans
         * @var \Magento\Sales\Model\Order $order
         * @var \Magento\Sales\Model\Order\Payment\Transaction\Repository $transRepository
         * @var \Paysafe\CardPayments\Authorization $response
         * @var \Magento\Sales\Model\Order\Payment\Interceptor $infoIns
         */
        $order = $payment->getOrder();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $amount = $data["amount"] * 1;
        $isAllowSaveCreditCard = isset($data["options"]["storeInVaultOnSuccess"]) ? $data["options"]["storeInVaultOnSuccess"] : false;

        $transBuilder = $objectManager->get("Magento\Sales\Model\Order\Payment\Transaction\Builder");

        if(!isset($data["payment"])) {
            throw new \Exception("No Payment Infos");
        }
        $paymentInfo = isset($data["payment"]) ? $data["payment"] : array();
        $vaultToken = false;
        
        if(isset($data["payment"]) && isset($data["payment"]["public_hash"])) {
            $publicHash = $data["payment"]["public_hash"];
            /**
             * @var \Magento\Vault\Model\PaymentTokenManagement $tokenManagement
             */
            $tokenManagement = $objectManager->get(\Magento\Vault\Model\PaymentTokenManagement::class);
            $tokenObject = $tokenManagement->getByPublicHash($publicHash, $order->getCustomerId());
            $vaultToken = $tokenObject->getGatewayToken();
        }

        $billingAddress = $order->getBillingAddress();
        try {
            if(!$vaultToken) {
                $authData = array(
                    'merchantRefNum' => $this->config->getOrderRefNumber($order->getIncrementId()),
                    'amount' => $amount,
                    "settleWithAuth"=> false,
                    'card' => array(
                        'cardNum' => $paymentInfo["cc_number"],
                        'cvv' => $paymentInfo["cc_cid"] * 1,
                        'cardExpiry' => array(
                            'month' => $paymentInfo["cc_exp_month"] * 1,
                            'year' => $paymentInfo["cc_exp_year"] * 1,
                        )
                    ),
                    'billingDetails' => array(
                        'zip' => $billingAddress->getPostcode(),
                        'street' => is_array($billingAddress->getStreet()) ? implode (" ", $billingAddress->getStreet()) : $billingAddress->getStreet(),
                        'city' => $billingAddress->getCity(),
                        'state' => $billingAddress->getRegionCode(),
                        'country' => $billingAddress->getCountryId(),
                    )
                );
                $response = $this->gateway->cardPaymentService()->authorize(new Authorization($authData));
            } else {
                $response = $this->authorizeByVaultId($vaultToken, $payment, $amount, $isCaptured);
            }
        } catch(\Exception $ex) {
            throw $ex;
        }
        
        if($response->status != self::AUTH_RESPONSE_SUCCESS_CODE) {
            throw new \Exception("Authorization failed");
        }
        /**
         * @var \Paysafe\CardPayments\Card $card
         */
        $authCode = $response->authCode;
        $card = $response->card;

        $payment->setCcType($card->type);
        $payment->setCcExpYear($card->cardExpiry->year);
        $payment->setCcExpMonth($card->cardExpiry->month);

        $payment->setCcStatus($response->status == self::AUTH_RESPONSE_SUCCESS_CODE ? "DONE": "FAIL"); //only 4 chars for db
        $payment->setCcAvsStatus($response->avsResponse);
        $payment->setCcSecureVerify($response->cvvVerification);
        $payment->setCcTransId($response->authCode);
        $payment->setCcLast4($card->lastDigits);
        $payment->setCcStatusDescription($response->status);
        $payment->setAdditionalInformation("authCode",$authCode);
        $payment->setAdditionalInformation("authID",$response->id);

        $payment->setSkipTransactionCreation(false);
        $payment->setIsTransactionClosed(false);
        $transBuilder->setTransactionId($response->id)
            ->setOrder($order)
            ->setPayment($payment)
            ->addAdditionalInformation("authCode",$authCode)
            ->build($isCaptured ? Transaction::TYPE_PAYMENT : Transaction::TYPE_AUTH);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->setState(Order::STATE_PROCESSING);
        try {
            if($isAllowSaveCreditCard && !$vaultToken) {
                $this->createCustomerCardProfile($data, $payment);
            }
        }catch(\Exception $ex) {

        }
        return $this;
    }


    protected function authorizeByVaultId($vaultToken,$payment,$amount,$isCaptured) {
        /**
         * @var \Magento\Vault\Model\PaymentToken $customerTokenManagement
         * @var Order $order
         */
        $order = $payment->getOrder();
        try {
            $data = array(
                'merchantRefNum' => $this->config->getOrderRefNumber($order->getIncrementId()),
                'amount' => $amount,
                'settleWithAuth' => $isCaptured,
                'card' => array(
                    'paymentToken' => $vaultToken
                )
            );
            $response = $this->gateway->cardPaymentService()->authorize(new Authorization($data));
            return $response;
        }catch(\Exception $ex) {
            throw $ex;
        }
        return $response;
    }

    protected function createCustomerCardProfile($data , \Magento\Payment\Model\InfoInterface $payment) {
        $order = $payment->getOrder();
        if(!$order->getCustomerId()) {
            return false;
        }
        $billingAddress = $order->getBillingAddress();
        $customerProfile = $this->getGatewayCustomerProfile($payment);
        $address = $this->getGatewayBillingAddressId($payment,$customerProfile);

        $paymentInfo = isset($data["payment"]) ? $data["payment"] : array();

        $card = new Card(array(
            "profileID" => $customerProfile->id,
            "nickName" => $billingAddress->getLastname(),
            "holderName" => $billingAddress->getLastname()." " . $billingAddress->getFirstname(),
            "cardNum" => $paymentInfo["cc_number"],
            "cardExpiry" => array(
                'month' =>  $paymentInfo["cc_exp_month"] * 1,
                'year' => $paymentInfo["cc_exp_year"] * 1,
            ),
            "billingAddressId" => $address->id
        ));

        try {
            try {
                $response = $this->gateway->customerVaultService()->createCard($card);
                if($response->status == "ACTIVE") {
                    $this->setPaymentToken($payment,$response);
                }
            } catch(RequestConflictException $ex) {
                $cardId = $this->getExistingCardIdFromRawResponse($ex->rawResponse);

                $response = $this->gateway->customerVaultService()->getCard(new Card(array(
                    'id' => $cardId,
                    'profileID' => $customerProfile->id
                )));
                if($response->status == "ACTIVE") {
                    $this->setPaymentToken($payment,$response);
                }

            }catch(\Exception $ex) {
                throw $ex;
            }
        }catch(\Exception $ex) {
            throw $ex;
        }
    }

    protected function getExistingCardIdFromRawResponse($response) {
        if(!isset($response["links"])) {
            return false;
        }
        $links = $response["links"];
        foreach($links as $link) {
            if($link["rel"] != "existing_entity") {
                continue;
            }
            $temp = explode("/cards/",$link["href"]);
            return array_pop($temp);
        }
        return false;
    }

    protected function setPaymentToken($payment,Card $response) {
        /**
         * @var \Magento\Sales\Model\Order\Payment $p
         * @var \Magento\Vault\Model\PaymentTokenFactory $paymentTokenFactory
         * @var PaymentTokenFactory $paymentTokenFactory
         * @var \Magento\Vault\Model\PaymentTokenManagement $tokenManagement
         */
        $p = $payment;
        $order = $payment->getOrder();
        $attributes = $p->getExtensionAttributes();
        $objectManager = ObjectManager::getInstance();
        try {
            $paymentTokenFactory = $objectManager->get(PaymentTokenFactory::class,[]);
            $tokenManagement = $objectManager->get(\Magento\Vault\Model\PaymentTokenManagement::class);
            $token = $tokenManagement->getByGatewayToken($response->paymentToken,self::METHOD_CODE,$order->getCustomerId());
            if($token->getEntityId()) {
                return $this;
            }
        }catch(\Exception $ex) {
            return $this;
        }

        $paymentToken = $paymentTokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
        $paymentToken->setCreatedAt(date("Y-m-d H:i:s"));
        $paymentToken->setExpiresAt(date("Y-m-d H:i:s",strtotime("+200 day")));
        $paymentToken->setCustomerId($order->getCustomerId());
        $paymentToken->setGatewayToken($response->paymentToken);
        $paymentToken->setPaymentMethodCode(self::METHOD_CODE);
        $paymentToken->setIsActive(true)->setIsVisible(true);
        $details = array(
            "card_id"=>$response->id,
            "billingAddressId"=>$response->billingAddressId,
            "profileId"=>$response->profileID,
            "cc_last_4"=>$response->lastDigits,
            "type"=>$response->cardType,
            "paymentToken"=>$response->paymentToken,
            'cc_exp_year'=>$response->cardExpiry->year,
            'maskedCC'=> "XXXXXXX-" . $response->lastDigits,
            'expirationDate'=>$payment->getCcExpYear()."/".$payment->getCcExpMonth(),
        );
        $paymentToken->setTokenDetails(json_encode($details));
        $attributes->setVaultPaymentToken($paymentToken);
    }

    protected function getGatewayCustomerProfile($payment) {
        $order = $payment->getOrder();
        $billingAddress = $order->getBillingAddress();
        $customerId = $order->getCustomerId();//."_".date("YmdHis");
        $profile = new Profile(array(
            "merchantCustomerId" => $customerId,
            "locale" => "en_US",
            "firstName" => $billingAddress->getFirstname(),
            "lastName" => $billingAddress->getLastname(),
            "email" => $order->getCustomerEmail(),
            "phone" => $billingAddress->getTelephone(),
        ));
        try {
            $response = $this->gateway->customerVaultService()->createProfile($profile);
            return $response;
        }catch(\Paysafe\RequestConflictException $ex) {
            $response = $ex->rawResponse;
            $links = isset($response["links"])? $response["links"] : array();
            $profileId = "";
            foreach($links as $link) {
                if($link["rel"] == "existing_entity") {
                    $profileDetailUrl = $link["href"];
                    $temp = explode("profiles/",$profileDetailUrl);
                    $profileId = array_pop($temp);
                    break;
                }
            }
            if($profileId) {
                $response = $this->gateway->customerVaultService()->getProfile(new Profile(array('id' => $profileId)));
                return $response;
            }
            throw $ex;
        }catch(\Exception $ex) {
            throw $ex;
        }
    }

    protected function getGatewayBillingAddressId($payment,$profile) {
        /**
         * @var Order $order
         */
        $order = $payment->getOrder();

        $billingAddress = $order->getBillingAddress();
        $street = $billingAddress->getStreet();
        $address = new Address(array(
            "profileID" => $profile->id,
            "nickName" => $billingAddress->getFirstname(),
            "street" => $street[0],
            "street2" => (isset($street[1]) ? $street[1] : ""),
            "city" => $billingAddress->getCity(),
            "country" => $billingAddress->getCountryId(),
            "state" => $billingAddress->getRegionCode(),
            "zip" => $billingAddress->getPostcode(),
            "recipientName" => $billingAddress->getLastname(),
            "phone" => $billingAddress->getTelephone()
        ));
        try {
            /*
            $response = $this->gateway->customerVaultService()->getAddress(new Address(array(
                'profileID' => $profile->id
            )));
            */
            $response = $this->gateway->customerVaultService()->createAddress($address);
            return $response;
        }catch(\Exception $ex) {
            throw $ex;
        }
    }

    /**
     * @param string|null $value
     * @return mixed
     */
    public function environment($value = null)
    {
        return Configuration::environment($value);
    }

    /**
     * @param string|null $value
     * @return mixed
     */
    public function merchantId($value = null)
    {
        return Configuration::merchantId($value);
    }

    /**
     * @param string|null $value
     * @return mixed
     */
    public function publicKey($value = null)
    {
        return Configuration::publicKey($value);
    }

    /**
     * @param string|null $value
     * @return mixed
     */
    public function privateKey($value = null)
    {
        return Configuration::privateKey($value);
    }

    /**
     * @param array $params
     * @return \Braintree\Result\Successful|\Braintree\Result\Error|null
     */
    public function generate(array $params = [])
    {
        return null;
    }

    /**
     * @param string $token
     * @return \Braintree\CreditCard|null
     */
    public function find($token)
    {
        return null;
    }

    /**
     * @param array $filters
     * @return \Braintree\ResourceCollection
     */
    public function search(array $filters)
    {
        return Transaction::search($filters);
    }

    /**
     * @param string $token
     * @return \Braintree\Result\Successful|\Braintree\Result\Error
     */
    public function createNonce($token)
    {
        return PaymentMethodNonce::create($token);
    }

    /**
     * @param array $attributes
     * @return \Braintree\Result\Successful|\Braintree\Result\Error
     */
    public function sale(array $attributes)
    {
        return Transaction::sale($attributes);
    }

    /**
     * @param string $transactionId
     * @param null|float $amount
     * @return \Braintree\Result\Successful|\Braintree\Result\Error
     */
    public function submitForSettlement($transactionId, $amount = null,$paymentDataObject)
    {
        try {
            /**
             * @var \Magento\Payment\Gateway\Data\PaymentDataObject $paymentDataObject
             */
            $payment = $paymentDataObject->getPayment();
            $order = $paymentDataObject->getOrder();
            $authorizationId = $payment->getAdditionalInformation("authID");

            $authorizationInfo = $this->getAuthorzationInfo($authorizationId);
            if($authorizationInfo->status == "COMPLETED") {

            }
            $response = $this->gateway->cardPaymentService()->settlement(new Settlement(array(
                'merchantRefNum' => $this->config->getOrderRefNumber($order->getOrderIncrementId()),
                'authorizationID' => $authorizationId,
                "status"=>"PROCESSING",
                "amount"=>$amount * 1,
            )));
            $payment->setParentTransactionId($payment->getAdditionalInformation("authID"));
            $payment->setTransactionId($response->id);
        }catch(\Exception $ex) {
            throw $ex;
        }

    }

    /**
     * @param string $transactionId
     * @return \Braintree\Result\Successful|\Braintree\Result\Error
     */
    public function void($transactionId, $paymentDataObject)
    {
        $payment = $paymentDataObject->getPayment();
        $order = $paymentDataObject->getOrder();
        $authorizationId = $payment->getAdditionalInformation("authID");
        $authReversal = new AuthorizationReversal(array(
            'merchantRefNum' => $this->config->getOrderRefNumber($order->getOrderIncrementId()),
            'amount' => $payment->getOrder()->getTotalDue(),
            'authorizationID' => $authorizationId
        ));
        try {
            //print_r($authReversal);die();
            $response = $this->gateway->cardPaymentService()->reverseAuth($authReversal);
        }catch(\Exception $ex) {
            throw $ex;
        }

        return $this;

    }

    /**
     * @param string $transactionId
     * @param null|float $amount
     * @return \Braintree\Result\Successful|\Braintree\Result\Error
     */
    public function refund($transactionId, $amount = null,$paymentObjectData)
    {
        /**
         * @var \Magento\Sales\Model\Order\Invoice $invoice
         * @var \Magento\Sales\Model\Order\Payment $payment
         * @var \Magento\Sales\Model\Order\Creditmemo $creditmemo
         */
        $payment = $paymentObjectData->getPayment();
        $order = $paymentObjectData->getOrder();
        $creditmemo = $payment->getCreditmemo();
        $invoice = $creditmemo->getInvoice();
        try {
            $settlementInfo = $this->getSettlementInfo($invoice->getTransactionId());
            if($settlementInfo->status == "PENDING") {
                if($amount != $invoice->getGrandTotal()) {
                    throw new \Exception("The capture still not batched, the partial refund doesn't supported!");
                }
                $response = $this->gateway->cardPaymentService()->cancelSettlement(new Settlement(array(
                    'id' => $invoice->getTransactionId(),
                )));
            } else {
                $response = $this->gateway->cardPaymentService()->refund(new Refund(array(
                    'merchantRefNum' => $this->config->getOrderRefNumber($order->getOrderIncrementId()),
                    'settlementID' => $invoice->getTransactionId(),
                    'amount'=>$amount * 1,
                )));
            }
            $payment->setTransactionId($response->id);
            $payment->setParentTransactionId($invoice->getTransactionId());
        }catch(\Exception $ex) {
            throw $ex;
        }
        return $this;
    }

    protected function getAuthorzationInfo($authId) {
        $response = $this->gateway->cardPaymentService()->getAuth(new Authorization(array(
            'id' => $authId
        )));
        return $response;
    }

    protected function getSettlementInfo($settlementId) {
        $response = $this->gateway->cardPaymentService()->getSettlement(new Settlement(array(
            'id' => $settlementId
        )));
        return $response;
    }

    /**
     * Clone original transaction
     * @param string $transactionId
     * @param array $attributes
     * @return mixed
     */
    public function cloneTransaction($transactionId, array $attributes)
    {
        return Transaction::cloneTransaction($transactionId, $attributes);
    }
}
