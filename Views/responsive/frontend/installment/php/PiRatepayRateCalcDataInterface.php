<?php
/**
 * Copyright (c) 2020 Ratepay GmbH
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

    interface PiRatepayRateCalcDataInterface
    {

        public function getTransactionId();

        public function getTransactionShortId();

        public function getOrderId();

        public function getMerchantConsumerId();

        public function getMerchantConsumerClassification();

        public function getAmount();

        public function getData();

        public function getPaymentFirstdayConfig();

        public function setData($total_amount, $amount, $interest_rate, $interest_amount, $service_charge, $annual_percentage_rate, $monthly_debit_interest, $number_of_rates, $rate, $last_rate, $payment_firstday);

        public function unsetData();

        public function getGetParameter($var);

        public function getPostParameter($var);
    }
