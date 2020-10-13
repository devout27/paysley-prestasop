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

class PaysleyPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $paymentType   = 'DB';

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();
        
        $cart = $this->context->cart;
        if (!$this->module->checkCurrency($cart)) {
            Tools::redirect('index.php?controller=order');
        }
        $reference = 'ps17-' . date('ymd') . $cart->id . $this->randomNumber(4);
        PaysleyApi::$accessKey = Configuration::get('PAYSLEY_ACCESS_KEY');
        PaysleyApi::$isTestMode = $this->module->is_test_mode;
        $paymentUrl = $this->getPaymentUrl($reference);
        Tools::redirect($paymentUrl);
    }

     /**
     * get payment url that provide by the gateway.
     * @param string $reference
     *
     * @return strig|void
     */
    private function getPaymentUrl($reference)
    {
        $cart = $this->context->cart;
        $contextLink = $this->context->link;
        $currency = new Currency((int)$cart->id_currency);
        $secretKey     = $this->module->generateSecretKey(
            $this->context->cart->id,
            $currency->iso_code
        );

        $body = array(
            'reference'    => $reference,
            'payment_type' => $this->paymentType,
            'currency'     => $currency->iso_code,
            'amount'       => $this->context->cart->getOrderTotal(true, Cart::BOTH),
            'cart_items'   => $this->getCartItems(),
            'cancel_url'   => $contextLink->getPageLink('order', true, null, array('step' => '3')),
            'return_url'   => $contextLink->getModuleLink(
                'paysley',
                'validation',
                array('cart_id' => $cart->id),
                true
            ),
            'response_url' => $this->context->link->getModuleLink(
                'paysley',
                'paymentResponse',
                array(
                    'cart_id' => $this->context->cart->id,
                    'secure_payment' => $secretKey
                ),
                true
            ),
        );

        $logData = $body;
        $logData['response_url'] = $this->context->link->getModuleLink(
            'paysley',
            'paymentResponse',
            array(
                    'cart_id' => $this->context->cart->id,
                    'secure_payment' => '****'
                ),
            true
        );
        
        $messageLog = 'Paysley - postLink parameter '.json_encode($logData);
        $this->module->addPluginLogger($messageLog, 3, null, 'Cart', 0, true);

        $postLink = json_decode(PaysleyApi::generatePosLink($body), 1);

        $messageLog = 'Paysley - postLink  '.json_encode($postLink);
        $this->module->addPluginLogger($messageLog, 3, null, 'Cart', 0, true);

        if (isset($postLink['result']) && $postLink['result'] == 'success') {
            return $postLink['long_url'];
        } elseif (isset($postLink['error_field']) && $postLink['error_field'] == 'currency') {
            $this->module->addPluginLogger('Paysley - post link error currency', 3, null, 'Cart', $cart->id, true);
            $this->redirectError(
                $this->l('We are sorry, currency is not supported. Please contact us.')
            );
        } else {
            $this->module->addPluginLogger('Paysley - post link is not generated', 3, null, 'Cart', $cart->id, true);
            $this->redirectError(
                $this->l('Error while Processing Request: please try again.')
            );
        }
    }

    /**
     * Get cart items.
     *
     * @return array
     */
    public function getCartItems()
    {
        $carts = $this->context->cart->getSummaryDetails();
        $products = $carts['products'];
        $cartItems = array();
        foreach ($products as $product) {
            $cartItems[] = array(
                'name' => $product['name'],
                'qty'  => (int)$product['cart_quantity'],
                'unit_price'=> (float)$this->module->setNumberFormat($product['price_wt']),
                'sku'=> $product['reference']
            );
        }

        return $cartItems;
    }

    /**
     * Get cancel url.
     *
     * @return string
     */
    private function getPaysleyCancelUrl()
    {
        return $this->context->link->getModuleLink(
            'paysley',
            'cancelurl',
            array(),
            true
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
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, array(
            'step' => '3')));
    }

    /**
     * generate Random Number
     * @param string $length
     *
     * @return string
     */
    public function randomNumber($length)
    {
        $result = '';

        for ($i = 0; $i < $length; $i++) {
              $result .= mt_rand(0, 9);
        }

        return $result;
    }
}
