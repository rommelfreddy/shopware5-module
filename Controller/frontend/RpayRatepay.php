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
    use Shopware\Components\CSRFWhitelistAware;

    class Shopware_Controllers_Frontend_RpayRatepay extends Shopware_Controllers_Frontend_Payment implements CSRFWhitelistAware
    {
        /**
         * Stores an Instance of the Shopware\Models\Customer\Billing model
         *
         * @var Shopware\Models\Customer\Billing
         */
        private $_config;
        private $_modelFactory;
        private $_logging;
        private $_customerMessage;

        /**
         * Initiates the Object
         */
        public function init()
        {
            $Parameter = $this->Request()->getParams();

            if (!isset(Shopware()->Session()->sUserId) && !isset($Parameter['userid'])) {
                return "RatePAY frontend controller: No user set";
            }

            $this->_config = Shopware()->Plugins()->Frontend()->RpayRatePay()->Config();
            $this->_modelFactory = new Shopware_Plugins_Frontend_RpayRatePay_Component_Mapper_ModelFactory();
            $this->_logging      = new Shopware_Plugins_Frontend_RpayRatePay_Component_Logging();
        }

        /**
         *  Checks the Paymentmethod
         */
        public function indexAction()
        {
            Shopware()->Session()->RatePAY['errorRatenrechner'] = 'false';
            if (preg_match("/^rpayratepay(invoice|rate|debit|rate0)$/", $this->getPaymentShortName())) {
                if ($this->getPaymentShortName() === 'rpayratepayrate' && !isset(Shopware()->Session()->RatePAY['ratenrechner'])
                ) {
                    Shopware()->Session()->RatePAY['errorRatenrechner'] = 'true';
                    $this->redirect(
                        Shopware()->Front()->Router()->assemble(
                            array(
                                'controller'  => 'checkout',
                                'action'      => 'confirm',
                                'forceSecure' => true
                            )
                        )
                    );
                } elseif ($this->getPaymentShortName() === 'rpayratepayrate0' && !isset(Shopware()->Session()->RatePAY['ratenrechner'])) {
                    Shopware()->Session()->RatePAY['errorRatenrechner'] = 'true';
                    $this->redirect(
                        Shopware()->Front()->Router()->assemble(
                            array(
                                'controller'  => 'checkout',
                                'action'      => 'confirm',
                                'forceSecure' => true
                            )
                        )
                    );
                } else {
                    Shopware()->Pluginlogger()->info('proceed');
                    $this->_proceedPayment();
                }
            } else {
                $this->redirect(
                    Shopware()->Front()->Router()->assemble(
                        array(
                            'controller'  => 'checkout',
                            'action'      => 'confirm',
                            'forceSecure' => true
                        )
                    )
                );
            }
        }

        /**
         * Updates phone, ustid, company and the birthday for the current user.
         */
        public function saveUserDataAction()
        {
            Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
            $Parameter = $this->Request()->getParams();

            $customerModel = Shopware()->Models()->getRepository('Shopware\Models\Customer\Customer');
            $userModel = $customerModel->findOneBy(array('id' => Shopware()->Session()->sUserId));

            if (isset($Parameter['checkoutBillingAddressId']) && !is_null($Parameter['checkoutBillingAddressId'])) { // From Shopware 5.2 current billing address is sent by parameter
                $addressModel = Shopware()->Models()->getRepository('Shopware\Models\Customer\Address');
                $customerAddressBilling = $addressModel->findOneBy(array('id' => $Parameter['checkoutBillingAddressId']));
                Shopware()->Session()->RatePAY['checkoutBillingAddressId'] = $Parameter['checkoutBillingAddressId'];
                if (isset($Parameter['checkoutShippingAddressId']) && !is_null($Parameter['checkoutShippingAddressId'])) {
                    Shopware()->Session()->RatePAY['checkoutShippingAddressId'] = $Parameter['checkoutShippingAddressId'];
                } else {
                    unset(Shopware()->Session()->RatePAY['checkoutShippingAddressId']);
                }
            } else {
                $customerAddressBilling = $userModel->getBilling();
            }

            $return = 'OK';
            $updateUserData = array();
            $updateAddressData = array();

            if (!is_null($customerAddressBilling)) {
                if (method_exists($customerAddressBilling, 'getBirthday')) {
                    $updateAddressData['phone'] = $Parameter['ratepay_phone'] ? : $customerAddressBilling->getPhone();
                    if ($customerAddressBilling->getCompany() !== "") {
                        $updateAddressData['company'] = $Parameter['ratepay_company'] ? : $customerAddressBilling->getCompany();
                    } else {
                        $updateAddressData['birthday'] = $Parameter['ratepay_dob'] ? : $customerAddressBilling->getBirthday()->format("Y-m-d");
                    }

                    try {
                        Shopware()->Db()->update('s_user_billingaddress', $updateAddressData, 'userID=' . $Parameter['userid']); // ToDo: Why parameter?
                        Shopware()->Pluginlogger()->info('Kundendaten aktualisiert.');
                    } catch (Exception $exception) {
                        Shopware()->Pluginlogger()->error('Fehler beim Updaten der Userdaten: ' . $exception->getMessage());
                        $return = 'NOK';
                    }

                } elseif (method_exists($userModel, 'getBirthday')) { // From Shopware 5.2 birthday is moved to customer object
                    $updateAddressData['phone'] = $Parameter['ratepay_phone'] ? : $customerAddressBilling->getPhone();
                    if (!is_null($customerAddressBilling->getCompany())) {
                        $updateAddressData['company'] = $Parameter['ratepay_company'] ? : $customerAddressBilling->getCompany();
                    } else {
                        $updateUserData['birthday'] = $Parameter['ratepay_dob'] ? : $userModel->getBirthday()->format("Y-m-d");
                    }

                    try {
                        if (count($updateUserData) > 0) {
                            Shopware()->Db()->update('s_user', $updateUserData, 'id=' . $Parameter['userid']); // ToDo: Why parameter?
                        }
                        if (count($updateAddressData) > 0) {
                            Shopware()->Db()->update('s_user_addresses', $updateAddressData, 'id=' . $Parameter['checkoutBillingAddressId']);
                        }
                        Shopware()->Pluginlogger()->info('Kundendaten aktualisiert.');
                    } catch (Exception $exception) {
                        Shopware()->Pluginlogger()->error('Fehler beim Updaten der User oder Address daten: ' . $exception->getMessage());
                        $return = 'NOK';
                    }
                } else {
                    $return = 'NOK';
                }


            }

            if ($Parameter['ratepay_debit_updatedebitdata']) {
                Shopware()->Session()->RatePAY['bankdata']['account']    = $Parameter['ratepay_debit_accountnumber'];
                Shopware()->Session()->RatePAY['bankdata']['bankcode']   = $Parameter['ratepay_debit_bankcode'];
                Shopware()->Session()->RatePAY['bankdata']['bankholder'] = $customerAddressBilling->getFirstname() . " " . $customerAddressBilling->getLastname();
            }

            echo $return;
        }

        /**
         * Procceds the whole Paymentprocess
         */
        private function _proceedPayment()
        {

            $resultRequest = $this->_modelFactory->callRequest('PaymentRequest');

            if ($resultRequest->isSuccessful()) {
                Shopware()->Session()->RatePAY['transactionId'] = $resultRequest->getTransactionId();
                $uniqueId = $this->createPaymentUniqueId();
                $orderNumber = $this->saveOrder(Shopware()->Session()->RatePAY['transactionId'], $uniqueId, 17);
                $dgNumber = $resultRequest->getDescriptor();

                if (Shopware()->Session()->sOrderVariables['sBasket']['sShippingcosts'] > 0) {
                    $this->initShipping($orderNumber);
                }
                try {
                    $orderId = Shopware()->Db()->fetchOne(
                        'SELECT `id` FROM `s_order` WHERE `ordernumber`=?',
                        array($orderNumber)
                    );
                    Shopware()->Db()->update(
                        's_order_attributes',
                        array(
                            'attribute5' => $dgNumber,
                            'attribute6' => Shopware()->Session()->RatePAY['transactionId'],
                            'ratepay_fallback_shipping' => Shopware()->Plugins()->Frontend()->RpayRatePay()->Config()->get('RatePayUseFallbackShippingItem'),
                        ),
                        'orderID=' . $orderId
                    );
                } catch (Exception $exception) {
                    Shopware()->Pluginlogger()->error($exception->getMessage());
                }

                //set cleared date
                $dateTime = new DateTime();

                $order = Shopware()->Models()->find('Shopware\Models\Order\Order', $orderId);
                $order->setClearedDate($dateTime);
                Shopware()->Models()->flush($order);

                //set payments status to payed
                $this->savePaymentStatus(
                    Shopware()->Session()->RatePAY['transactionId'],
                    $uniqueId,
                    12
                );

                $bootstrap = new Shopware_Plugins_Frontend_RpayRatePay_Bootstrap();
                if ($bootstrap->getPCConfig() == true) {
                    $this->_modelFactory->setTransactionId($resultRequest->getTransactionId());
                    $this->_modelFactory->setOrderId($orderNumber);
                    $this->_modelFactory->callRequest('PaymentConfirm', array());
                }

                /**
                 * unset DFI token
                 */
                if (Shopware()->Session()->RatePAY['dfpToken']) {
                    unset(Shopware()->Session()->RatePAY['dfpToken']);
                }

                /*
                 * redirect to success page
                 */
                $this->redirect(
                    array(
                        'controller'  => 'checkout',
                        'action'      => 'finish',
                        'sUniqueID' => $uniqueId,
                        'forceSecure' => true
                    )
                );
            } else {
                $this->_customerMessage = $resultRequest->getCustomerMessage();
                $this->_error();
            }

            // Clear RatePAY session after call for authorization
            Shopware()->Session()->RatePAY = [];
        }

        /**
         * Redirects the User in case of an error
         */
        private function _error()
        {
            $this->View()->loadTemplate("frontend/payment_rpay_part/RatePAYErrorpage.tpl");
            $customerMessage = $this->_customerMessage;

            if (!empty($customerMessage)) {
                $this->View()->assign('rpCustomerMsg', $customerMessage);
            } else {
                Shopware()->Session()->RatePAY['hidePayment'] = true;

                $shopId = Shopware()->Shop()->getId();
                $customerModel = Shopware()->Models()->getRepository('Shopware\Models\Customer\Customer');
                $userModel = $customerModel->findOneBy(array('id' => Shopware()->Session()->sUserId));
                $countryBilling = Shopware()->Models()->find('Shopware\Models\Country\Country', $userModel->getBilling()->getCountryId());
                $config = $this->getRatePayPluginConfigByCountry($shopId, $countryBilling);

                $this->View()->assign('rpCustomerMsg', $config['error-default']);
            }
        }

        /**
         * Get ratepay plugin config from rpay_ratepay_config table
         *
         * @param $shopId
         * @param $country
         * @return array
         */
        private function getRatePayPluginConfigByCountry($shopId, $country, $backend=false) {
            //fetch correct config for current shop based on user country
            $profileId = Shopware()->Plugins()->Frontend()->RpayRatePay()->Config()->get('RatePayProfileID' . $country->getIso());

            //get ratepay config based on shopId and profileId
            return Shopware()->Db()->fetchRow('
                SELECT
                *
                FROM
                `rpay_ratepay_config`
                WHERE
                `shopId` =?
                AND
                `profileId`=?
                AND 
                backend=?
            ', array($shopId, $profileId, $backend));
        }

        /**
         * calcDesign-function for installment
         */
        public function calcDesignAction()
        {
            Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
            $calcPath = realpath(dirname(__FILE__) . '/../../Views/responsive/frontend/installment/php/');
            require_once $calcPath . '/PiRatepayRateCalc.php';
            require_once $calcPath . '/path.php';
            require_once $calcPath . '/PiRatepayRateCalcDesign.php';
        }

        /**
         * calcRequest-function for installment
         */
        public function calcRequestAction()
        {
            Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
            $calcPath = realpath(dirname(__FILE__) . '/../../Views/responsive/frontend/installment/php/');
            require_once $calcPath . '/PiRatepayRateCalc.php';
            require_once $calcPath . '/path.php';
            require_once $calcPath . '/PiRatepayRateCalcRequest.php';
        }

        /**
         * Initiates the Shipping-Position fo the given order
         *
         * @param string $orderNumber
         */
        private function initShipping($orderNumber)
        {
            try {
                $orderID = Shopware()->Db()->fetchOne(
                    "SELECT `id` FROM `s_order` WHERE `ordernumber`=?",
                    array($orderNumber)
                );
                Shopware()->Db()->query(
                    "INSERT INTO `rpay_ratepay_order_shipping` (`s_order_id`) VALUES(?)",
                    array($orderID)
                );
            } catch (Exception $exception) {
                Shopware()->Pluginlogger()->error($exception->getMessage());
            }
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

    }
