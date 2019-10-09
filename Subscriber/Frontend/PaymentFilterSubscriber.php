<?php

namespace RpayRatePay\Subscriber\Frontend;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Enlight\Event\SubscriberInterface;
use Enlight_Components_Session_Namespace;
use Enlight_Event_EventArgs;
use RpayRatePay\Component\Model\ShopwareCustomerWrapper;
use RpayRatePay\Component\Service\ValidationLib as ValidationService;
use RpayRatePay\Enum\PaymentMethods;
use RpayRatePay\Helper\SessionHelper;
use RpayRatePay\Models\ConfigPayment;
use RpayRatePay\Models\ProfileConfig;
use RpayRatePay\Services\Config\ProfileConfigService;
use Shopware\Bundle\StoreFrontBundle\Service\Core\ContextService;
use Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface;
use Monolog\Logger;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Country\Country;
use Shopware\Models\Customer\Customer;
use Shopware\Models\Payment\Payment;
use Shopware_Components_Config;
use Shopware_Components_Modules;

class PaymentFilterSubscriber implements SubscriberInterface
{
    /**
     * @var ModelManager
     */
    protected $modelManager;
    /**
     * @var Enlight_Components_Session_Namespace
     */
    protected $session;
    /**
     * @var ProfileConfigService
     */
    protected $profileConfig;
    /**
     * @var Shopware_Components_Config
     */
    protected $config;
    /**
     * @var Shopware_Components_Modules
     */
    protected $modules;
    /**
     * @var Logger
     */
    protected $logger;
    /**
     * @var ShopContextInterface|null
     */
    protected $context;
    /**
     * @var SessionHelper
     */
    private $sessionHelper;

    public function __construct(
        ModelManager $modelManager,
        ContextService $contextService,
        Enlight_Components_Session_Namespace $session,
        Shopware_Components_Config $config,
        Shopware_Components_Modules $modules,
        ProfileConfigService $profileConfig,
        SessionHelper $sessionHelper,
        Logger $logger
    )
    {
        $this->modelManager = $modelManager;
        $this->session = $session;
        $this->context = $contextService->getContext();
        $this->config = $config;
        $this->modules = $modules;
        $this->profileConfig = $profileConfig;
        $this->logger = $logger;
        $this->sessionHelper = $sessionHelper;
    }

    public static function getSubscribedEvents()
    {
        return [
            'Shopware_Modules_Admin_GetPaymentMeans_DataFilter' => 'filterPayments',
        ];
    }

    /**
     * Filters the shown Payments
     * RatePAY-payments will be hidden, if one of the following requirement is not given
     *  - Delivery Address is not allowed to be not the same as billing address
     *  - The Customer must be over 18 years old
     *  - The Country must be germany or austria
     *  - The Currency must be EUR
     *
     * @param Enlight_Event_EventArgs $arguments
     * @return array|void
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws TransactionRequiredException
     */
    public function filterPayments(Enlight_Event_EventArgs $arguments)
    {
        $return = $arguments->getReturn();
        $currency = $this->config->get('currency');
        $userId = $this->session->get('sUserId');
        if (empty($userId) || empty($currency)) {
            return;
        }

        /** @var Customer $customer */
        $customer = $this->modelManager->find(Customer::class, $userId);
        $billingAddress = $this->sessionHelper->getBillingAddress($customer);
        $shippingAddress = $this->sessionHelper->getShippingAddress($customer);

        $configs = $this->getRatePayPluginConfigByCountry($this->context->getShop()->getId(), $billingAddress->getCountry());

        $availableRatePayMethods = [];
        foreach ($configs as $payment => $config) {
            $availableRatePayMethods[$payment] = false;

            /** @var ProfileConfig $profileConfig */
            $profileConfig = $config['profileConfig'];
            /** @var ConfigPayment $paymentConfig */
            $paymentConfig = $config['paymentConfig'];

            if((int)$paymentConfig->getStatus() !== 2) { //TODO change type of `status` to int
                continue;
            }

            if (Shopware()->Session()->RatePAY['hidePayment'] || // TODO whats that ?
                !ValidationService::isCurrencyValid($profileConfig->getCurrency(), $currency) ||
                !ValidationService::isCountryValid($profileConfig->getCountryCodeBilling(), $billingAddress->getCountry()) ||
                !ValidationService::isCountryValid($profileConfig->getCountryCodeDelivery(), $shippingAddress ? $shippingAddress->getCountry() : $billingAddress->getCountry()) ||
                (!ValidationService::areBillingAndShippingSame($billingAddress, $shippingAddress) && !$paymentConfig->getAddress())
            ) {
                continue;
            }

            if ($this->modules->Basket()) {
                $basket = $this->modules->Basket()->sGetAmount();
                $basket = floatval($basket['totalAmount']); // TODO is this always brutto?

                $this->logger->info('BasketAmount: ' . $basket);
                $isB2b = ValidationService::isCompanySet($billingAddress);

                if (!ValidationService::areAmountsValid($isB2b, $paymentConfig, $basket)) {
                    continue;
                }
                $availableRatePayMethods[$payment] = true;
            }
        }

        $paymentModel = $this->modelManager->find(Payment::class, $customer->getPaymentId());
        $setToDefaultPayment = false;

        $payments = [];
        foreach ($return as $payment) {
            if ($payment['name'] === PaymentMethods::PAYMENT_INVOICE && !$availableRatePayMethods[PaymentMethods::PAYMENT_INVOICE]) {
                $this->logger->info('RatePAY: Filter RatePAY-Invoice');
                $setToDefaultPayment = $paymentModel->getName() === $payment['name'] ?: $setToDefaultPayment;
                continue;
            }
            if ($payment['name'] === PaymentMethods::PAYMENT_DEBIT && !$availableRatePayMethods[PaymentMethods::PAYMENT_DEBIT]) {
                $this->logger->info('RatePAY: Filter RatePAY-Debit');
                $setToDefaultPayment = $paymentModel->getName() === $payment['name'] ?: $setToDefaultPayment;
                continue;
            }
            if ($payment['name'] === PaymentMethods::PAYMENT_RATE && !$availableRatePayMethods[PaymentMethods::PAYMENT_RATE]) {
                $this->logger->info('RatePAY: Filter RatePAY-Rate');
                $setToDefaultPayment = $paymentModel->getName() === $payment['name'] ?: $setToDefaultPayment;
                continue;
            }
            if ($payment['name'] === PaymentMethods::PAYMENT_INSTALLMENT0 && !$availableRatePayMethods[PaymentMethods::PAYMENT_INSTALLMENT0]) {
                $this->logger->info('RatePAY: Filter RatePAY-Rate0');
                $setToDefaultPayment = $paymentModel->getName() === $payment['name'] ?: $setToDefaultPayment;
                continue;
            }
            if ($payment['name'] === PaymentMethods::PAYMENT_PREPAYMENT && !$availableRatePayMethods[PaymentMethods::PAYMENT_PREPAYMENT]) {
                $this->logger->info('RatePAY: Filter RatePAY-Prepayment');
                $setToDefaultPayment = $paymentModel->getName() === $payment['name'] ?: $setToDefaultPayment;
                continue;
            }
            $payments[] = $payment;
        }

        if ($setToDefaultPayment) {
            $customer->setPaymentId($this->config->get('paymentdefault'));
            $this->modelManager->flush($customer);
        }

        return $payments;
    }

    /**
     * Get ratepay plugin config from rpay_ratepay_config table
     *
     * @param $shopId
     * @param $country
     * @return array
     */
    private function getRatePayPluginConfigByCountry($shopId, Country $country)
    {
        $return = [];
        foreach (PaymentMethods::getNames() as $payment) {
            $profileConfig = $this->profileConfig->getProfileConfig(
                $country->getIso(),
                $shopId,
                false,
                $payment == PaymentMethods::PAYMENT_INSTALLMENT0
            );
            if($profileConfig == null) {
                continue;
            }
            $paymentConfig = $this->profileConfig->getPaymentConfigForProfileAndMethod($profileConfig, $payment);

            if ($paymentConfig) {
                $return[$payment] = [
                    'profileConfig' => $profileConfig,
                    'paymentConfig' => $paymentConfig
                ];
            }
        }
        return $return;
    }
}
