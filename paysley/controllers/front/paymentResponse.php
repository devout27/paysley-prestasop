<?php
/**
* 2020 Paysley
*
* NOTICE OF Paysley
*
* This source file is subject to the General Public License) (GPL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/gpl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
*  @author    Paysley <info@paysley.com>
*  @copyright 2020 Paysley
*  @license   https://www.gnu.org/licenses/gpl-3.0.html  General Public License (GPL 3.0)
*  International Registered Trademark & Property of Paysley
*/

class PaysleyPaymentResponseModuleFrontController extends ModuleFrontController
{

    protected $orderConfirmationUrl = 'index.php?controller=order-confirmation';
    /**
     * Process payment response from the gateway in the background process.
     *
     * @return void
     */
    public function postProcess()
    {
        $cartId = Tools::getValue('cart_id');
        if (empty($cartId)) {
            $this->module->addPluginLogger('Paysley - cart id is not found', 1, null, 'Cart', "", true);
            $this->redirectError(
                $this->l('Error while Processing Request: please try again.')
            );
        }
        if (empty($this->context->cookie->id_transaction)) {
            $this->module->addPluginLogger('Paysley - transaction id is not generated', 1, null, 'Cart', $cartId, true);
            $this->redirectError(
                $this->l('Error while Processing Request: please try again.')
            );
        }
        $paymentResponse = PaysleyApi::getPaymentDetails($this->context->cookie->id_transaction);
        unset($this->context->cookie->id_transaction);
        if (empty($paymentResponse['transaction'])) {
            $this->module->addPluginLogger('Paysley - Not able to get payment transaction detail', 1, null, 'Cart',$cartId, true);
            $this->redirectError(
                $this->l('Error while Processing Request: please try again.')
            );
        }
        $paymentResult = [];
        $paymentResult['payment_id']     = $paymentResponse['transaction']['payment_id'] ?? '';
        $paymentResult['transaction_id'] = $paymentResponse['transaction']['transaction_id'] ?? '';
        $paymentResult['amount']         = $paymentResponse['transaction']['amount'] ?? '';
        $paymentResult['result']         = $paymentResponse['transaction']['status'] ?? '';
        $paymentResult['currency']       = $paymentResponse['transaction']['currency'] ?? '';
        $orderStatus = $paymentResult['result'] === "success" ? Configuration::get('PS_OS_PAYMENT') : Configuration::get('PS_OS_ERROR');
        $this->module->addPluginLogger('Paysley - use payment gateway', 1, null, 'Cart', $cartId, true);
        $isTransactionLogExist = $this->isTransactionLogExist($cartId);
        if (!$isTransactionLogExist) {
            Context::getContext()->cart = new Cart((int)$cartId);
            $transactionLog = $this->setTransactionLog($paymentResult);
            $secretkey = $this->module->generateSecretKey(
                $cartId,
                $paymentResult['currency']
            );
            if ($secretkey != Tools::getValue('secure_payment')) {
                $this->module->addPluginLogger(
                    'Paysley - FRAUD Transaction',
                    1,
                    null,
                    'Cart',
                    $cartId,
                    true
                );
            }

            $this->module->addPluginLogger(
                'Paysley - save transaction log from status URL',
                1,
                null,
                'Cart',
                $cartId,
                true
            );
            $this->module->saveTransactionLog($transactionLog, 0);
            $this->validatePayment($cartId, $orderStatus);
        } else {
            $this->module->addPluginLogger(
                'Paysley - process existing order ',
                1,
                null,
                'Cart',
                $cartId,
                true
            );
            $this->updatePrestashopOrderStatus($cartId, $orderStatus, $paymentResult);
        }
    }

    /**
     * validate payment response from gateway.
     * @param  string $cartId
     *
     * @return void
     */
    public function validatePayment($cartId, $orderStatus)
    {
        Context::getContext()->cart = new Cart((int)$cartId);
        $cart = $this->context->cart;
        Context::getContext()->currency = new Currency((int)$cart->id_currency);
        $customer = new Customer($cart->id_customer);
        $messageLog =
            'Paysley - Module Status : '. $this->module->active .
            ', Customer Id : '. $cart->id_customer .
            ', Delivery Address : '. $cart->id_address_delivery .
            ', Invoice Address : '. $cart->id_address_invoice;
        $this->module->addPluginLogger($messageLog, 1, null, 'Cart', $cart->id, true);
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0 || !$this->module->active
            || !Validate::isLoadedObject($customer)) {
            $this->module->addPluginLogger('Paysley - customer datas are not valid', 3, null, 'Cart', $cart->id, true);
            $this->redirectError(
                $this->l('Error while Processing Request: please try again.')
            );
        }
        $this->processSuccessPayment($customer, $orderStatus);
    }

    /**
     * create order for success payment.
     * @param  Object $customer
     *
     * @return void
     */
    protected function processSuccessPayment($customer, $orderStatus)
    {
        $cart = $this->context->cart;
        $cartId = $cart->id;        
        $currency = $this->context->currency;
        $total = (float)($cart->getOrderTotal(true, Cart::BOTH));
        $this->module->validateOrder(
            $cartId,
            $orderStatus,
            $total,
            $this->module->displayName,
            null,
            array(),
            $currency->id,
            false,
            $customer->secure_key
        );
        $orderId = $this->module->currentOrder;
        $this->module->addPluginLogger("get order_id ".$orderId, 1, null, 'Cart', $cartId, true);
        $this-> updateTransactionLog(
            $orderId,
            $cartId
        );
        $messageLog = 'Paysley - order ('. $orderId .') has been successfully created';
        $this->module->addPluginLogger($messageLog, 1, null, 'Cart', $cartId, true);
        $this->redirectSuccess($cartId);
    }


    /**
     * set Transaction Log from payment response.
     * @param array $paymentResponse
     *
     * @return array
     */
    public function setTransactionLog($paymentResponse)
    {
        $cart = $this->context->cart;
        $context = Context::getContext();
        $transactionLog = array();
        $transactionLog['order_id'] = Tools::getValue('cart_id');
        $transactionLog['transaction_id'] = $paymentResponse['transaction_id'];
        $transactionLog['cart_id'] = Tools::getValue('cart_id');
        $transactionLog['order_status'] = $paymentResponse['result'] === "success" ? Configuration::get('PS_OS_PAYMENT') : Configuration::get('PS_OS_ERROR');
        $transactionLog['payment_id'] = $paymentResponse['payment_id'];
        $transactionLog['currency'] = $paymentResponse['currency'];
        $transactionLog['amount'] = (float)($cart->getOrderTotal(true, Cart::BOTH));
        $transactionLog['payment_response'] = serialize($paymentResponse);
        return $transactionLog;
    }


    /**
     * update Transaction Log from table paysley_order_ref.
     * @param  string $orderId
     * @param  string $cart_id
     *
     * @return void
     */
    protected function updateTransactionLog($orderId, $cart_id)
    {
        $sql = "UPDATE paysley_order_ref SET
            order_id = '".pSQL($orderId)."' 
            where cart_id = '".pSQL($cart_id)."'";
        $messageLog = 'Paysley - update payment response from payment gateway : ' . $sql;
        $this->module->addPluginLogger($messageLog, 1, null, 'Order', $orderId, true);
        if (!Db::getInstance()->execute($sql)) {
            $messageLog = 'Paysley - failed when updating payment response from payment gateway';
            $this->module->addPluginLogger($messageLog, 3, null, 'Order', $orderId, true);
            $this->redirectPaymentFailed();
        }
        $this->module->addPluginLogger(
            'Paysley - payment gateway response succefully updated',
            1,
            null,
            'Order',
            $orderId,
            true
        );
    }

    /**
     * validate if Transaction log exists on database.
     * @param  string  $cartId
     *
     * @return boolean
     */
    public function isTransactionLogExist($cartId)
    {
        $order = $this->module->getOrderByCartId($cartId);
        $messageLog = 'Paysley - existing order : ' . json_encode($order);
        $this->module->addPluginLogger($messageLog, 1, null, 'Cart', $this->context->cart->id, true);
        return !empty($order);
    }


    /**
     * update order status in table paysley_order_ref.
     * @param  string $orderId
     * @param  string $orderStatus
     *
     * @return void
     */
    protected function updateTransactionLogOrderStatus($orderId, $orderStatus, $paymentResponse = "")
    {
        $sql = "UPDATE paysley_order_ref SET
            order_status = '".pSQL($orderStatus)."',
            payment_response = '".pSQL(serialize($paymentResponse))."'
            where order_id = '".pSQL($orderId)."'";

        $messageLog = 'Paysley - update order status : ' . $sql;
        $this->module->addPluginLogger($messageLog, 1, null, 'Order', $orderId, true);

        if (!Db::getInstance()->execute($sql)) {
            $messageLog = 'Paysley - failed when updating order status';
            $this->module->addPluginLogger($messageLog, 3, null, 'Order', $orderId, true);
            $this->redirectPaymentFailed();
        }
        $this->module->addPluginLogger(
            'Paysley - order status succefully updated',
            1,
            null,
            'Order',
            $orderId,
            true
        );
    }

    /**
     * update order status from existing order base on cartId.
     * @param  string $cartId
     * @param  string $orderStatus
     *
     * @return void
     */
    protected function updatePrestashopOrderStatus($cartId, $orderStatus, $paymentResponse = "")
    {
        $orderLog = $this->module->getOrderByCartId($cartId);
        $orderId = $orderLog['order_id'];
        $history = new OrderHistory();
        $history->id_order = (int)$orderId;
        $history->changeIdOrderState($orderStatus, (int)($orderId));
        $history->addWithemail(true);
        $this->updateTransactionLogOrderStatus($orderId, $orderStatus, $paymentResponse);
        $this->redirectSuccess($cartId);
    }

        /**
     * redirect to pending payment page
     *
     * @return void
     */
    protected function redirectPaymentFailed()
    {
        $url = $this->context->link->getModuleLink('paysley', 'paymentReturn', array(
            'secure_key' => $this->context->customer->secure_key), true);
        $this->module->addPluginLogger(
            'rediret to payment return : '.$url,
            1,
            null,
            'Cart',
            $this->context->cart->id,
            true
        );
        Tools::redirect($url);
        exit;
    }

    /**
     * redirect to thankyou page
     *
     * @return void
     */
    protected function redirectSuccess($cartId)
    {
        Tools::redirect(
            $this->orderConfirmationUrl.
            '&id_cart='.$cartId.
            '&id_module='.(int)$this->module->id.
            '&key='.$this->context->customer->secure_key
        );
    }

    /**
     * redirect to checkout page with error message.
     * @param string $returnMessage
     *
     * @return string
     */
    private function redirectError($returnMessage)
    {
        $this->errors[] = $this->l($returnMessage);
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null));
    }
}
