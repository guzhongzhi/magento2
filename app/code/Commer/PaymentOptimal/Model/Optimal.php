<?php
namespace Commer\PaymentOptimal\Model;

use Magento\Framework\App\ObjectManager;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\TMRobokassa\Model\Config\Source\Order\Status\Paymentreview;
use Magento\Sales\Model\Order;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Model\PaymentToken;
use Magento\Vault\Model\PaymentTokenFactory;
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

class Optimal extends \Magento\Payment\Model\Method\Cc
{
    static $data = array();
    const METHOD_CODE = "optimal";
    const AUTH_RESPONSE_SUCCESS_CODE = "COMPLETED";
    protected $hashCode = "";
    /**
     * @var string
     */
    //protected $_infoBlockType = \Magento\Payment\Block\Info\Cc::class;

    protected $_isGateway = true;
    protected $_code = self::METHOD_CODE;
    protected $_canOrder = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_stripeApi = false;
    protected $_canVoid = true;
    protected $_countryFactory;
    protected $_minAmount = null;
    protected $_maxAmount = null;
    protected $_supportedCurrencyCodes = array('USD','CAD');
    protected $_debugReplacePrivateDataKeys = ['number', 'exp_month', 'exp_year', 'cvc'];
    protected $gateway = null;
    
    public function __construct(\Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        array $data = array()
    ) {

        parent::__construct(
            $context, $registry, $extensionFactory, $customAttributeFactory,
            $paymentData, $scopeConfig, $logger, $moduleList, $localeDate, null,
            null, $data
        );
        $this->_countryFactory = $countryFactory;
        $this->_minAmount = $this->getConfigData('min_order_total');
        $this->_maxAmount = $this->getConfigData('max_order_total');
        
        $this->hashCode = md5(microtime(true).mt_rand(100,999));
        $isLive = $this->getConfigData("is_live_environment");
        if($isLive) {
            $this->gateway = new PaysafeApiClient($this->getConfigData("app_key"), $this->getConfigData("app_sec"), Environment::LIVE, $this->getConfigData("account_id"));
        } else {
            $this->gateway = new PaysafeApiClient($this->getConfigData("app_key"), $this->getConfigData("app_sec"), Environment::TEST, $this->getConfigData("account_id"));
        }
    }

    /**
     * Check method for processing with base currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }
        return true;
    }

    /**
     * Retrieve block type for method form generation
     *
     * @return string
     */
    public function getFormBlockType()
    {
        return $this->_formBlockType;
    }

    public function getAvailableCardTypes() {
        $data = $this->getConfigData("cctypes");
        return $data;
    }
    
    /**
     * Check whether there are CC types set in configuration
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $v =  $this->getConfigData('cctypes', $quote ? $quote->getStoreId() : null) && parent::isAvailable($quote);
        return $v;
    }

    public function isActive($storeId = null) {
        $v = parent::isActive($storeId);
        return $v;
    }

    public function validate() {
        $vaultId = $this->getVaultId();
        if($vaultId) {
            return true;
        }
        return parent::validate();
    }
    
    /**
     * Assign data to info model instance
     *
     * @param \Magento\Framework\DataObject|mixed $data
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_object($additionalData)) {
            $additionalData = new DataObject($additionalData ?: []);
        }
        
        /** @var DataObject $info */
        $info = $this->getInfoInstance();
        $data = [
            'cc_type' => $additionalData->getCcType(),
            'cc_owner' => $additionalData->getCcOwner(),
            'cc_last_4' => substr($additionalData->getCcNumber(), -4),
            'cc_number' => $additionalData->getCcNumber(),
            'cc_cid' => $additionalData->getCcCid(),
            'cc_exp_month' => $additionalData->getCcExpMonth(),
            'cc_exp_year' => $additionalData->getCcExpYear(),
            'cc_ss_issue' => $additionalData->getCcSsIssue(),
            'cc_ss_start_month' => $additionalData->getCcSsStartMonth(),
            'cc_ss_start_year' => $additionalData->getCcSsStartYear(),
            'allow_save_card' => $additionalData->getData("allow_save_card"),
            'vault_id' => $additionalData->getData("vault_id"),
        ];
        $info->addData($data);
        self::$data["last_assign_data"] = $data;
        return $this;
    }

    protected function getVaultId() {
        $info = $this->getInfoInstance();
        $vaultId = $info->getData("vault_id");
        if(isset(self::$data["last_assign_data"]["vault_id"])) {
            $vaultId = self::$data["last_assign_data"]["vault_id"];
        }
        return $vaultId;
    }

    /**
     * Authorize payment abstract method
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        /**
         * @var \Magento\Sales\Model\Order\Payment\Transaction\Manager $transManager
         * @var \Magento\Sales\Model\Order\Payment\Transaction\Builder $transBuilder
         * @var \Magento\Sales\Model\Order\Payment\Transaction $trans
         * @var \Magento\Sales\Model\Order $order
         * @var \Magento\Sales\Model\Order\Payment\Transaction\Repository $transRepository
         */
        $trans = $objectManager->get("Magento\Sales\Model\Order\Payment\Transaction");
        $transBuilder = $objectManager->get("Magento\Sales\Model\Order\Payment\Transaction\Builder");

        /**
         * @var \Paysafe\CardPayments\Authorization $response
         * @var \Magento\Sales\Model\Order $order
         *
         */
        $order = $payment->getOrder();
        $infoIns = $this->getInfoInstance();
        $vaultId = $this->getVaultId();

        $isAllowSaveCreditCard = false;
        if(isset(self::$data["last_assign_data"]["allow_save_card"])) {
            $isAllowSaveCreditCard = self::$data["last_assign_data"]["allow_save_card"] || $isAllowSaveCreditCard;
        }
        $isAllowSaveCreditCard = $isAllowSaveCreditCard || $infoIns->getData("allow_save_card");

        $billingAddress = $order->getBillingAddress();
        $isCaptured = ($this->getConfigPaymentAction() == \Magento\Authorizenet\Model\Authorizenet::ACTION_AUTHORIZE_CAPTURE ? true : false);

        try {
            if($vaultId) {
                $response = $this->authorizeByVaultId($vaultId, $payment, $amount, $isCaptured);
            }else {
                $data = array(
                    'merchantRefNum' => "order-".$order->getIncrementId(),
                    'amount' => $amount,
                    //'currencyCode'=>$order->getOrderCurrencyCode(),
                    "settleWithAuth"=> $isCaptured,
                    'card' => array(
                        'cardNum' => $infoIns->getData("cc_number"),
                        'cvv' => $infoIns->getData("cc_cid") * 1,
                        'cardExpiry' => array(
                            'month' => $infoIns->getData("cc_exp_month") * 1,
                            'year' => $infoIns->getData("cc_exp_year") * 1,
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
                //print_r($data);die();
                $response = $this->gateway->cardPaymentService()->authorize(new Authorization($data));
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

        $trans->setOrderId($payment->getParentId());
        $trans->setPaymentId($payment->getId());
        $trans->setTxnId($response->authCode);
        $trans->setTxnType(Transaction::TYPE_AUTH);
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
            if($isAllowSaveCreditCard && !$vaultId) {
                $this->createCustomerCardProfile($payment);
            }
        }catch(\Exception $ex) {
            echo $ex->__toString();die();
        }
        return $this;
    }


    protected function authorizeByVaultId($vaultId,$payment,$amount,$isCaptured) {
        /**
         * @var \Magento\Vault\Model\PaymentToken $customerTokenManagement
         */
        $order = $payment->getOrder();
        $customerTokenManagement = ObjectManager::getInstance()->get("Magento\\Vault\\Model\\PaymentToken");
        $customerTokenManagement->load($vaultId);
        try {
            $data = array(
                'merchantRefNum' => $this->getMerchantRefNum($order),
                'amount' => $amount,
                'settleWithAuth' => $isCaptured,
                //'currencyCode'=>$order->getOrderCurrencyCode(),
                'card' => array(
                    'paymentToken' => $customerTokenManagement->getGatewayToken()
                )
            );
            $response = $this->gateway->cardPaymentService()->authorize(new Authorization($data));
            return $response;
        }catch(\Exception $ex) {
            echo $ex->__toString();die();
            throw $ex;
        }
        return $response;
    }

    protected function createCustomerCardProfile(\Magento\Payment\Model\InfoInterface $payment) {
        $order = $payment->getOrder();
        if(!$order->getCustomerId()) {
            return false;
        }
        $billingAddress = $order->getBillingAddress();
        $customerProfile = $this->getGatewayCustomerProfile($payment);
        $address = $this->getGatewayBillingAddressId($payment,$customerProfile);
        $infoIns = $this->getInfoInstance();
        $card = new Card(array(
            "profileID" => $customerProfile->id,
            "nickName" => $billingAddress->getLastname(),
            "holderName" => $billingAddress->getLastname()." " . $billingAddress->getFirstname(),
            "cardNum" => $infoIns->getData("cc_number"),
            "cardExpiry" => array(
                'month' =>  $infoIns->getData("cc_exp_month") * 1,
                'year' => $infoIns->getData("cc_exp_year") * 1,
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
         */
        $p = $payment;
        $order = $payment->getOrder();
        $attributes = $p->getExtensionAttributes();
        $objectManager = ObjectManager::getInstance();
        $paymentTokenFactory = $objectManager->get("Magento\\Vault\\Model\\PaymentTokenFactory");
        /**
         * PaymentTokenFactoryInterface
         * @var PaymentTokenFactory $paymentTokenFactory
         */
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

    public function initialize($paymentAction, $stateObject) {
        if($this->getConfigData("payment_action") == Order::STATE_PROCESSING) {
            $stateObject->setStatus(Order::STATE_PROCESSING);
            $stateObject->setState(Order::STATE_PROCESSING);
        }
    }

    /**
     *@return false if the value of return is true, no authorization will be called
     */
    public function isInitializeNeeded() {
        return false;
    }
    
    /**
     * Get config payment action url
     * Used to universalize payment actions when processing payment place
     *
     * @return string
     * @api
     */
    public function getConfigPaymentAction()
    {
        return \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE;
        $action = $this->getConfigData('payment_action');
        if(!$action) {
            $action = "authorize";
        }
        return $action;
    }
    
    /**
     * Capture payment abstract method
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        /**
         * @var \Magento\Sales\Model\Order\Invoice $invoice
         * @var \Magento\Sales\Model\Order\Payment $payment
         */
        $this->log("capture: " . $payment->getId(). " : ". $amount);
        if (!$this->canCapture()) {
            return $this;
        }
        try {
            $authorizationId = $payment->getAdditionalInformation("authID");
            /*
            $response = $this->gateway->cardPaymentService()->getAuth(new Authorization(array(
                'id' => $authorizationId
            )));
            */

            $response = $this->gateway->cardPaymentService()->settlement(new Settlement(array(
                'merchantRefNum' => $this->getMerchantRefNum($payment->getOrder()),
                'authorizationID' => $authorizationId,
                "amount"=>$amount,
            )));
            $payment->setParentTransactionId($payment->getAdditionalInformation("authID"));
            $payment->setTransactionId($response->id);
        }catch(\Exception $ex) {
            throw $ex;
        }
        return $this;
    }

    protected function getMerchantRefNum($order) {
        return "order-" . $order->getIncrementId();
    }

    protected function log($msg) {
        /**
         * @var \Magento\Framework\Logger\Monolog $logger
         */
        $logger = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Psr\Log\LoggerInterface::class);
        $logger->log(\Monolog\Logger::ERROR, $msg);
    }

    public function void(\Magento\Payment\Model\InfoInterface $payment) {
        $authorizationId = $payment->getAdditionalInformation("authID");
        $authReversal = new AuthorizationReversal(array(
            'merchantRefNum' => $this->getMerchantRefNum($payment->getOrder()),
            'amount' => $payment->getOrder()->getTotalDue(),
            'authorizationID' => $authorizationId
        ));
        $response = $this->gateway->cardPaymentService()->reverseAuth($authReversal);
        return $this;
    }

    public function fetchTransactionInfo(\Magento\Payment\Model\InfoInterface $payment, $transactionId) {

    }
 
    /**
     * Refund specified amount for payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        /**
         * @var \Magento\Sales\Model\Order\Invoice $invoice
         * @var \Magento\Sales\Model\Order\Payment $payment
         * @var \Magento\Sales\Model\Order\Creditmemo $creditmemo
         */
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
                    'merchantRefNum' => $this->getMerchantRefNum($payment->getOrder()),
                    'settlementID' => $invoice->getTransactionId(),
                    'amount'=>$amount,
                )));
            }
            $payment->setTransactionId($response->id);
            $payment->setParentTransactionId($invoice->getTransactionId());
        }catch(\Exception $ex) {
            throw $ex;
        }
        return $this;
    }

    protected function getSettlementInfo($settlementId) {
        $response = $this->gateway->cardPaymentService()->getSettlement(new Settlement(array(
            'id' => $settlementId
        )));
        return $response;
    }
}