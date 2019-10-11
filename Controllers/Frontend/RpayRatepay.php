<?php

/**
 * This program is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, see <http://www.gnu.org/licenses/>.
 *
 * RpayRatepay
 *
 * @category   RatePAY
 * @copyright  Copyright (c) 2013 RatePAY GmbH (http://www.ratepay.com)
 */

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\TransactionRequiredException;
use Monolog\Logger;
use RatePAY\Model\Response\PaymentRequest;
use RpayRatePay\Component\Mapper\ModelFactory;
use RpayRatePay\Component\Model\ShopwareCustomerWrapper;
use RpayRatePay\Component\Service\ConfigLoader;
use RpayRatePay\Component\Service\SessionLoader;
use RpayRatePay\Component\Service\ShopwareUtil;
use RpayRatePay\Enum\PaymentMethods;
use RpayRatePay\Helper\SessionHelper;
use RpayRatePay\Services\Config\ConfigService;
use RpayRatePay\Services\Config\ProfileConfigService;
use RpayRatePay\Services\DfpService;
use RpayRatePay\Services\Factory\PaymentRequestDataFactory;
use RpayRatePay\Services\Logger\RequestLogger;
use RpayRatePay\Services\PaymentProcessorService;
use RpayRatePay\Services\Request\PaymentConfirmService;
use RpayRatePay\Services\Request\PaymentRequestService;
use Shopware\Components\CSRFWhitelistAware;
use Shopware\Components\DependencyInjection\Container;
use Shopware\Models\Order;

class Shopware_Controllers_Frontend_RpayRatepay extends Shopware_Controllers_Frontend_Payment implements CSRFWhitelistAware
{
    /** @var DfpService */
    protected $dfpService;

    /**
     * @var Logger
     */
    protected $logger;
    /**
     * @var object|PaymentRequestService
     */
    protected $paymentRequestService;
    /**
     * @var object|PaymentRequestDataFactory
     */
    protected $paymentRequestDataFactory;
    /**
     * @var object|ConfigService
     */
    private $configService;
    /**
     * @var PaymentConfirmService
     */
    private $paymentConfirmService;
    /**
     * @var SessionHelper
     */
    protected $sessionHelper;
    /**
     * @var object|ProfileConfigService
     */
    private $profileConfigService;


    public function setContainer(Container $container = null)
    {
        parent::setContainer($container);

        $this->paymentRequestDataFactory = $this->container->get(PaymentRequestDataFactory::class);
        $this->paymentRequestService = $this->container->get(PaymentRequestService::class);
        $this->paymentConfirmService = $this->container->get(PaymentConfirmService::class);
        $this->logger = $container->get('rpay_rate_pay.logger');
        $this->dfpService = $this->container->get(DfpService::class);
        $this->configService = $this->container->get(ConfigService::class);
        $this->profileConfigService = $this->container->get(ProfileConfigService::class);
        $this->sessionHelper = $this->container->get(SessionHelper::class);
    }

    /**
     *  Checks the Paymentmethod
     */
    public function indexAction()
    {
        if (!$this->isRatePayPayment()) {
            $this->redirect(
                Shopware()->Front()->Router()->assemble(
                    [
                        'controller' => 'checkout',
                        'action' => 'confirm',
                        'forceSecure' => true
                    ]
                )
            );
            return;
        }

        if (true /*!$this->isInstallmentPaymentWithoutCalculation()*/) { //TODO
            $this->logger->info('Proceed with RatePAY payment');
            Shopware()->Session()->RatePAY['errorRatenrechner'] = 'false';
            $this->_proceedPayment();
            return;
        }

        $this->logger->info('RatePAY installment has incomplete calculation');
        Shopware()->Session()->RatePAY['errorRatenrechner'] = 'true';
        $this->redirect(
            Shopware()->Front()->Router()->assemble(
                [
                    'controller' => 'checkout',
                    'action' => 'confirm',
                    'forceSecure' => true
                ]
            )
        );
    }

    public function getQualifiedCustomerDetailsFromParameters()
    {
        $parameters = $this->Request()->getParams();

        $qualifiedParameters = [];

        if (ShopwareUtil::hasValueAndIsNotEmpty('checkoutBillingAddressId', $parameters)) {
            $qualifiedParameters['checkoutBillingAddressId'] = $parameters['checkoutBillingAddressId'];
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('ratepay_company', $parameters)) {
            $qualifiedParameters['ratepay_company'] = $parameters['ratepay_company'];
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('ratepay_phone', $parameters)) {
            // find out the rules from gateway for validation!
            $qualifiedParameters['ratepay_phone'] = $parameters['ratepay_phone'];
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('ratepay_birthyear', $parameters)
            && ShopwareUtil::hasValueAndIsNotEmpty('ratepay_birthmonth', $parameters)
            && ShopwareUtil::hasValueAndIsNotEmpty('ratepay_birthday', $parameters)) {

            $day = $parameters['ratepay_birthday'];
            $month = $parameters['ratepay_birthmonth'];
            $year = $parameters['ratepay_birthyear'];

            if (checkdate($month, $day, $year)) {
                $date = DateTime::createFromFormat('Y-m-d', "$year-$month-$day");
                $qualifiedParameters['ratepay_dob'] = $date->format('Y-m-d');
            }
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('ratepay_debit_accountnumber', $parameters)) {
            $qualifiedParameters['ratepay_debit_accountnumber'] = $parameters['ratepay_debit_accountnumber'];
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('ratepay_debit_updatedebitdata', $parameters)) {
            $qualifiedParameters['ratepay_debit_updatedebitdata'] = $parameters['ratepay_debit_updatedebitdata'];
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('ratepay_debit_bankcode', $parameters)) {
            $qualifiedParameters['ratepay_debit_bankcode'] = $parameters['ratepay_debit_bankcode'];
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('ratepay_agb', $parameters)) {
            $qualifiedParameters['ratepay_agb'] = $parameters['ratepay_agb'];
        }

        if (ShopwareUtil::hasValueAndIsNotEmpty('userid', $parameters)) {
            $qualifiedParameters['userid'] = $parameters['userid'];
        }

        return $qualifiedParameters;
    }

    /**
     * Updates phone, ustid, company and the birthday for the current user.
     */
    public function saveUserDataAction()
    {
        Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
        $Parameter = $this->getQualifiedCustomerDetailsFromParameters();

        $customerModel = Shopware()->Models()->getRepository('Shopware\Models\Customer\Customer');

        /** @var Shopware\Models\Customer\Customer $userModel $userModel */
        $userModel = $customerModel->findOneBy(['id' => Shopware()->Session()->sUserId]);
        $userWrapped = new ShopwareCustomerWrapper($userModel, Shopware()->Models());

        if (isset($Parameter['checkoutBillingAddressId']) && !is_null($Parameter['checkoutBillingAddressId'])) { // From Shopware 5.2 current billing address is sent by parameter
            $addressModel = Shopware()->Models()->getRepository('Shopware\Models\Customer\Address');
            $customerAddressBilling = $addressModel->findOneBy(['id' => $Parameter['checkoutBillingAddressId']]);
            Shopware()->Session()->RatePAY['checkoutBillingAddressId'] = $Parameter['checkoutBillingAddressId'];
            if (isset($Parameter['checkoutShippingAddressId']) && !is_null($Parameter['checkoutShippingAddressId'])) {
                Shopware()->Session()->RatePAY['checkoutShippingAddressId'] = $Parameter['checkoutShippingAddressId'];
            } else {
                unset(Shopware()->Session()->RatePAY['checkoutShippingAddressId']);
            }
        } else {
            $customerAddressBilling = $userWrapped->getBilling();
        }


        $return = 'OK';
        $updateUserData = [];
        $updateAddressData = [];

        if (!is_null($customerAddressBilling)) {
            //shopware before 5.2 ... we could try changing order of if and ifelse
            if (method_exists($customerAddressBilling, 'getBirthday')) {
                $updateAddressData['phone'] = $Parameter['ratepay_phone'] ?: $customerAddressBilling->getPhone();
                if ($customerAddressBilling->getCompany() !== '') {
                    $updateAddressData['company'] = $Parameter['ratepay_company'] ?: $customerAddressBilling->getCompany();
                } else {
                    $updateAddressData['birthday'] = $Parameter['ratepay_dob'] ?: $customerAddressBilling->getBirthday()->format('Y-m-d');
                }

                try {
                    Shopware()->Db()->update('s_user_billingaddress', $updateAddressData, 'userID=' . $Parameter['userid']); // TODO: Parameterize or make otherwise safe
                    $this->logger->info('Customer data was updated');
                } catch (Exception $exception) {
                    $this->logger->error('RatePAY was unable to update customer data: ' . $exception->getMessage());
                    $return = 'NOK';
                }
            } elseif (method_exists($userModel, 'getBirthday')) { // From Shopware 5.2 birthday is moved to customer object
                $updateAddressData['phone'] = $Parameter['ratepay_phone'] ?: $customerAddressBilling->getPhone();
                if (!is_null($customerAddressBilling->getCompany())) {
                    $updateAddressData['company'] = $Parameter['ratepay_company'] ?: $customerAddressBilling->getCompany();
                } else {
                    $updateUserData['birthday'] = $Parameter['ratepay_dob'] ?: $userModel->getBirthday()->format('Y-m-d');
                }

                try {
                    if (count($updateUserData) > 0) {
                        Shopware()->Db()->update('s_user', $updateUserData, 'id=' . $Parameter['userid']); // ToDo: Why parameter?
                    }
                    if (count($updateAddressData) > 0) {
                        Shopware()->Db()->update('s_user_addresses', $updateAddressData, 'id=' . $Parameter['checkoutBillingAddressId']);
                    }
                    $this->logger->info('Customer data was updated');
                } catch (Exception $exception) {
                    $this->logger->error('RatePAY was unable to update customer data: ' . $exception->getMessage());
                    $return = 'NOK';
                }
            } else {
                $return = 'NOK';
            }
        }

        $sessionLoader = new SessionLoader(Shopware()->Session());
        if ($Parameter['ratepay_debit_updatedebitdata']) {
            $sessionLoader->setBankData(
                $userModel->getId(),
                //            $customerAddressBilling->getFirstname() . " " . $customerAddressBilling->getLastname(),
                $Parameter['ratepay_debit_accountnumber'],
                $Parameter['ratepay_debit_bankcode']
            );
        }

        echo $return;
    }

    /**
     * Procceds the whole Paymentprocess
     */
    private function _proceedPayment()
    {

        try {
            $this->paymentRequestService->setIsBackend(false);
            $paymentRequestData = $this->paymentRequestDataFactory->createFromFrontendSession();
            $this->paymentRequestService->setPaymentRequestData($paymentRequestData);
            /** @var PaymentRequest $requestResponse */
            $requestResponse = $this->paymentRequestService->doRequest();

            if ($requestResponse->isSuccessful()) {

                $transactionId = $requestResponse->getTransactionId();
                $uniqueId = $this->createPaymentUniqueId();

                $statusId = $this->configService->getPaymentStatusAfterPayment($paymentRequestData->getMethod());
                $orderNumber = $this->saveOrder($transactionId, $uniqueId, $statusId ? $statusId : 17);

                $order = Shopware()->Models()->getRepository(Order\Order::class)
                    ->findOneBy(['number' => $orderNumber]);

                $this->paymentRequestService->completeOrder($order, $requestResponse);

                $this->paymentConfirmService->setOrder($order);
                $this->paymentConfirmService->doRequest();

                // Clear RatePAY session after call for authorization
                $this->sessionHelper->cleanUp();
                $this->dfpService->deleteDfpId();

                /*
                 * redirect to success page
                 */
                $this->redirect(
                    [
                        'controller' => 'checkout',
                        'action' => 'finish',
                        'sUniqueID' => $uniqueId,
                        'forceSecure' => true
                    ]
                );
            } else {
                $this->redirect(
                    [
                        'controller' => 'checkout',
                        'action' => 'confirm',
                        'rpay_message' => !empty($requestResponse->getCustomerMessage()) ? $requestResponse->getCustomerMessage() : $requestResponse->getReasonMessage()
                    ]
                );
            }
        } catch(\Exception $e) {
            $this->redirect(
                [
                    'controller' => 'checkout',
                    'action' => 'confirm',
                    'rpay_message' => $e->getMessage()
                ]
            );
        }
    }


    /**
     * Get ratepay plugin config from rpay_ratepay_config table
     *
     * @param $shopId
     * @param $country
     * @return array
     */
    private function getRatePayPluginConfigByCountry($shopId, $country, $backend = false)
    {
        //fetch correct config for current shop based on user country
        $profileId = Shopware()->Plugins()->Frontend()->RpayRatePay()->Config()->get('RatePayProfileID' . $country->getIso());

        //get ratepay config based on shopId and profileId
        return Shopware()->Db()->fetchRow('
            SELECT *
            FROM `rpay_ratepay_config`
            WHERE `shopId` =?
            AND `profileId`=?
            AND backend=?
        ', [$shopId, $profileId, $backend]);
    }

    /**
     * calcRequest-function for installment
     */
    public function calcRequestAction()
    {
        Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
        $params = $this->Request()->getParams();
        if(!isset($params['calculationAmount']) ||
            !isset($params['calculationValue']) ||
            !isset($params['calculationType']) ||
            !isset($params['paymentFirstday'])) {
            exit(0);
        }
        $paymentMethod = $this->sessionHelper->getPaymentMethod();
        $billingAddress = $this->sessionHelper->getBillingAddress();
        $profileConfig = $this->profileConfigService->getProfileConfig(
            $billingAddress->getCountry()->getIso(),
            Shopware()->Shop()->getId(),
            false,
            PaymentMethods::isZeroPercentInstallment($paymentMethod)
        );

        $pluginDir = Shopware()->Container()->getParameter('rpay_rate_pay.plugin_dir');
        $template = file_get_contents($pluginDir.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'template.installmentPlan.html');
        $ib = new \RatePAY\Frontend\InstallmentBuilder($profileConfig->isSandbox()); // true = sandbox mode
        $ib->setProfileId($profileConfig->getProfileId());
        $ib->setSecuritycode($profileConfig->getSecurityCode());
        echo $ib->getInstallmentPlanByTemplate(
            $template,
            $params['calculationAmount'],
            $params['calculationType'],
            $params['calculationValue']
        );


        return;
        $calcPath = realpath(dirname(__FILE__) . '/../../Views/responsive/frontend/installment/php/');
        require_once $calcPath . '/PiRatepayRateCalc.php';
        require_once $calcPath . '/path.php';
        require_once $calcPath . '/PiRatepayRateCalcRequest.php';
    }

    public function getWhitelistedCSRFActions()
    {
        return [
            'index',
            'saveUserData',
            'calcDesign',
            'calcRequest'
        ];
    }

    /**
     * @return bool
     */
    private function isRatePayPayment()
    {
        return 1 === preg_match('/^rpayratepay(invoice|rate|debit|rate0|prepayment)$/', $this->getPaymentShortName());
    }

    /**
     * @return bool
     */
    private function isInstallmentPaymentWithoutCalculation()
    {
        return in_array($this->getPaymentShortName(), ['rpayratepayrate', 'rpayratepayrate0'])
            && !isset(Shopware()->Session()->RatePAY['ratenrechner']);
    }
}
