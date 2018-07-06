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
     */
    class Shopware_Plugins_Frontend_RpayRatePay_Component_Service_Util
    {
        /**
         * Return the methodname for RatePAY
         *
         * @return string
         */
        public static function getPaymentMethod($payment)
        {
            switch ($payment) {
                case 'rpayratepayinvoice':
                    return 'INVOICE';
                    break;
                case 'rpayratepayrate':
                    return 'INSTALLMENT';
                    break;
                case 'rpayratepaydebit':
                    return 'ELV';
                    break;
                case 'rpayratepayrate0':
                    return 'INSTALLMENT0';
                    break;
            }
        }

        /**
         * @param $table string
         * @param $column string
         *
         * @return bool
         */
        public static function tableHasColumn($table, $column)
        {
            $sql = "SHOW COLUMNS FROM $table LIKE '$column'";
            $res = Shopware()->Db()->fetchRow($sql);
            if (empty($res)) {
                return false;
            }
            return true;
        }
    }