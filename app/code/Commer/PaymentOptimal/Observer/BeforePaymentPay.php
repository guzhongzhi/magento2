<?php
namespace Commer\PaymentOptimal\Observer;


use Magento\Framework\Event\ObserverInterface;
class BeforePaymentPay implements ObserverInterface {
    protected $context = null;
    protected $scopeConfig = null;
    public function __construct(\Magento\Framework\App\Action\Context $context,\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig) {
        $this->scopeConfig = $scopeConfig;
        $this->context = $context;
    }
    
    
    /**
     * Address after save event handler
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /**
         * @var \Magento\Sales\Model\Order\Payment $payment
         * @var \Magento\Sales\Model\Order\Invoice $invoice
         */
        $payment = $observer->getPayment();
        $invoice = $observer->getInvoice();
        $invoice->setTransactionId($payment->getLastTransId());
        $payment->setActiveInvoice($invoice);
    }

}