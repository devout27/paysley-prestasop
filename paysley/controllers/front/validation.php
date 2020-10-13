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

require_once(dirname(__FILE__).'/../../core/paysleyApi.php');
require_once(dirname(__FILE__).'/paymentResponse.php');

class PaysleyValidationModuleFrontController extends ModuleFrontController
{
    protected $orderConfirmationUrl = 'index.php?controller=order-confirmation';

    /**
     * this function run when we access the controller.
     */
    public function postProcess()
    {
        $cartId = (int)Tools::getValue('cart_id');
        $this->module->addPluginLogger('Paysley - process return url', 1, null, 'Cart', $cartId, true);

        $this->module->addPluginLogger('validate order', 1, null, 'Cart', $cartId, true);
        $this->validateOrder($cartId);
    }

    /**
     * to validate order status and redirect to success or failed page.
     *
     * @param int $cartId
     *
     * @return void
     */
    protected function validateOrder($cartId)
    {
        for ($i=1; $i<= 10; $i++) {
            $this->module->addPluginLogger('get transaction log data: '.$i, 1, null, 'Cart', $cartId, true);
            $order = $this->module->getOrderByCartId($cartId);
            if (!empty($order)) {
                break;
            }
            sleep(1);
        }

        $this->module->addPluginLogger(
            'transaction log order : '.json_encode($order),
            1,
            null,
            'Cart',
            $cartId,
            true
        );
        
        if (empty($order) || empty($order['order_status'])) {
            $this->module->addPluginLogger('Paysley - status url late', 1, null, 'Cart', $cartId, true);
            $cart = $this->context->cart;
            $cartId = $cart->id;
            $currency = $this->context->currency;
            $total = (float)($cart->getOrderTotal(true, Cart::BOTH));
            $customer = new Customer($cart->id_customer);

            $this->module->validateOrder(
                $cartId,
                Configuration::get('PAYSLEY_PAYMENT_STATUS_PENDING'),
                $total,
                $this->module->displayName,
                null,
                array(),
                $currency->id,
                false,
                $customer->secure_key
            );
            $transactionLog = array();
            $transactionLog['transaction_id'] = 0;
            $transactionLog['cart_id'] = $cartId;
            $transactionLog['order_status'] = Configuration::get('PAYSLEY_PAYMENT_STATUS_PENDING');
            $transactionLog['payment_id'] = 0;
            $transactionLog['currency'] = $currency->iso_code;
            $transactionLog['amount'] = $total;
            $transactionLog['payment_response'] = "";
            $orderId = $this->module->currentOrder;

            $this->module->saveTransactionLog($transactionLog, $orderId);
            $this->redirectPaymentReturn();
        } else {
                $this->module->addPluginLogger(
                    'Paysley - redirect success validate return url',
                    1,
                    null,
                    'Cart',
                    $cartId,
                    true
                );
                $this->redirectSuccess($cartId);
        }
    }

    /**
     * redirect to pending payment page
     *
     * @return void
     */
    protected function redirectPaymentReturn()
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
}
