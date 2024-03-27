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

require_once(dirname(__FILE__).'/core/paysleyApi.php');

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Paysley extends PaymentModule
{
    private $html = '';
    private $postErrors = array();

    public $paymentDescription;
    public $title;
    public $accessKey;
    public $enableLogOption;
    public $isLoggerActive;

    /**
     * constructor of the Paysley plugin.
     *
     */
    public function __construct()
    {
        $this->name = 'paysley';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Paysley';
        $this->module_key = '1569498170f6de365fb2248b7a12cc52';
        $this->currencies = true;
        $this->is_test_mode = Tools::substr(Configuration::get('PAYSLEY_ACCESS_KEY'), 0, 7) == 'py_test';
        $this->currencies_mode = 'checkbox';

        if (Configuration::get('PAYSLEY_ENABLE_LOGGING') == 'log_option_yes') {
            $this->isLoggerActive = true;
        } else {
            $this->isLoggerActive = false;
        }
        
        $config = Configuration::getMultiple(
            array(
                'PAYSLEY_DESCRIPTION',
                'PAYSLEY_TITLE',
                'PAYSLEY_ACCESS_KEY',
                'PAYSLEY_ENABLE_LOGGING'
            )
        );
        if (!empty($config['PAYSLEY_TITLE'])) {
            $this->title = $config['PAYSLEY_TITLE'];
        }
        if (!empty($config['PAYSLEY_DESCRIPTION'])) {
            $this->paymentDescription = $config['PAYSLEY_DESCRIPTION'];
        }
        if (!empty($config['PAYSLEY_ACCESS_KEY'])) {
            $this->accessKey = $config['PAYSLEY_ACCESS_KEY'];
        }
        if (!empty($config['PAYSLEY_ENABLE_LOGGING'])) {
            $this->enableLogOption = $config['PAYSLEY_ENABLE_LOGGING'];
        }


        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = 'Paysley';
        $this->description = "Receive payments using Paysley.";
        $this->confirmUninstall = $this->l('Are you sure you want to delete these details?');
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);

        if (!isset($this->accessKey)) {
            $this->warning = $this->l('Access key must be configured before using this module.');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }

    /**
     * install the Paysley plugin.
     *
     * @return boolean
     */
    public function install()
    {
        $this->warning = null;

        if (is_null($this->warning) && !function_exists('curl_init')) {
            $this->warning = $this->l('cURL is required to use this module. Please install the php extention cURL.');
        }

        if (is_null($this->warning) && !$this->addPaysleyOrderStatus()) {
            $this->warning = $this->l('There was an Error creating a custom order status.');
        }

        if (is_null($this->warning)
            && !(parent::install()
            && $this->registerHook('paymentReturn')
            && $this->registerHook('updateOrderStatus')
            && $this->registerHook('displayInvoice')
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('header')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('paymentOptions'))) {
            $this->warning = $this->l('There was an Error installing the module.');
        }
        if (is_null($this->warning) && !$this->createOrderRefTables()) {
            $this->warning = $this->l('There was an Error creating a custom table.');
        }
        
        return is_null($this->warning);
    }

    /**
     * uninstall the Paysley plugin.
     *
     * @return boolean
     */
    public function uninstall()
    {
        $sql = "DELETE FROM `"._DB_PREFIX_."order_state` WHERE `module_name`='paysley'";
        if (!Db::getInstance()->Execute($sql)) {
            return false;
        }

        $sql = "DELETE FROM `"._DB_PREFIX_."order_state_lang`
        WHERE `id_order_state`='".Configuration::get('PAYSLEY_PAYMENT_STATUS_PENDING')."'";
        if (!Db::getInstance()->Execute($sql)) {
            return false;
        }

        if (!Configuration::deleteByName('PAYSLEY_DESCRIPTION')
                || !Configuration::deleteByName('PAYSLEY_TITLE')
                || !Configuration::deleteByName('PAYSLEY_PAYMENT_STATUS_PENDING')
                || !Configuration::deleteByName('PAYSLEY_ACCESS_KEY')
                || !Configuration::deleteByName('PAYSLEY_ENABLE_LOGGING')
                || !$this->unregisterHook('paymentReturn')
                || !$this->unregisterHook('updateOrderStatus')
                || !$this->unregisterHook('displayInvoice')
                || !$this->unregisterHook('displayAdminOrder')
                || !$this->unregisterHook('actionOrderSlipAdd')
                || !$this->unregisterHook('header')
                || !$this->unregisterHook('paymentOptions')
                || !parent::uninstall()) {
            return false;
        }
        return true;
    }

    /**
     * create paysley_order_ref table to save
     * payment response from gateway.
     *
     * @return boolean
     */
    public function createOrderRefTables()
    {
        $sql= "CREATE TABLE IF NOT EXISTS `paysley_order_ref`(
            `id` INT(10) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT(10) NOT NULL,
            `transaction_id` VARCHAR(32) NOT NULL,
            `cart_id` VARCHAR(32) NOT NULL,
            `order_status` VARCHAR(2) NOT NULL,
            `payment_id` VARCHAR(32) NOT NULL,
            `currency` VARCHAR(3) NOT NULL,
            `amount` decimal(17,2) NOT NULL,
            `payment_response` LONGTEXT NULL,
            `refund_response` LONGTEXT NULL)";

        if (!Db::getInstance()->Execute($sql)) {
            return false;
        }
        return true;
    }

    /**
     * validate required field in paysley backend configuration.
     *
     * @return string
     */
    private function postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('PAYSLEY_ACCESS_KEY') && !Configuration::get('PAYSLEY_ACCESS_KEY')) {
                $this->postErrors[] = $this->l('Please enter an access key!');
            }
        }
    }

    /**
     * save all paysley backend configuration.
     *
     * @return string
     */
    private function postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('PAYSLEY_DESCRIPTION', Tools::getValue('PAYSLEY_DESCRIPTION'));
            Configuration::updateValue('PAYSLEY_TITLE', Tools::getValue('PAYSLEY_TITLE'));
            if (Tools::getValue('PAYSLEY_ACCESS_KEY') && Tools::getValue('PAYSLEY_ACCESS_KEY') != "********") {
                Configuration::updateValue('PAYSLEY_ACCESS_KEY', Tools::getValue('PAYSLEY_ACCESS_KEY'));
            }
            Configuration::updateValue('PAYSLEY_ENABLE_LOGGING', Tools::getValue('PAYSLEY_ENABLE_LOGGING'));
        }
        $this->html .= $this->displayConfirmation(
            $this->trans($this->l('Settings updated'), array(), 'Admin.Notifications.Success')
        );
    }

    /**
     * add infos on paysley backend configuration
     *
     * @return html
     */
    protected function displayPaysleyInfos()
    {
        return $this->display(__FILE__, 'infos.tpl');
    }

     /**
     * get html structure for backend configuration view
     *
     * @return html
     */
    public function getContent()
    {
        $this->html = '';

        if (Tools::isSubmit('btnSubmit')) {
            $this->postValidation();
            if (!count($this->postErrors)) {
                $this->postProcess();
            } else {
                foreach ($this->postErrors as $err) {
                    $this->html .= $this->displayError($err);
                }
            }
        }

        $this->html .= $this->displayPaysleyInfos();
        $this->html .= $this->renderForm();

        return $this->html;
    }

    /**
     * the PrestaShop hook to display payment methods list when proceeding to checkout payment
     * @param  array $params
     *
     * @return array
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }
        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $this->smarty->assign(
            $this->getTemplateVars()
        );

        $newOption = new PaymentOption();
        $paymentController = $this->context->link->getModuleLink(
            $this->name,
            'payment',
            array(),
            true
        );
        $logo = Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/views/img/logo.png');
        
        $newOption->setCallToActionText(Configuration::get('PAYSLEY_TITLE'))
                ->setAction($paymentController)
                ->setAdditionalInformation(
                    $this->fetch('module:paysley/views/templates/front/paysley_payment_infos.tpl')
                )
                ->setLogo($logo);

        return [$newOption];
    }

    /**
     * the PrestaShop hook to add script on header of prestashop template
     * @param  array $parameters
     *
     * @return void
     */
    public function hookHeader($parameters)
    {
        $this->context->controller->addCSS(($this->_path).'views/css/payment_options.css', 'all');
    }

    /**
     * the PrestaShop hook to additional script/template/text
     * on success payment page.
     * @param  array $parameters
     *
     * @return string
     */
    public function hookPaymentReturn($parameters)
    {
        if (!$this->active) {
            return;
        }

        $state = $parameters['order']->getCurrentState();
        $this->addPluginLogger(
            'State payment return: '.$state,
            1,
            null,
            'cart',
            $this->context->cart->id,
            true
        );
        
        if ($state == Configuration::get('PS_OS_PAYMENT') || $state == 0) {
            $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'status' => 'ok'
            ));
            if (isset($parameters['order']->reference) && !empty($parameters['order']->reference)) {
                $this->smarty->assign('reference', $parameters['order']->reference);
            }
        }

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * check currency is supported by paysley plugin.
     * @param  object $cart
     *
     * @return boolean
     */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency((int)($cart->id_currency));
        $currencies_module = $this->getCurrency((int)$cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * render a configuration form.
     *
     * @return string
     */
    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Paysley'),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'name' => 'PAYSLEY_TITLE',
                        'desc' => $this->l('This is the title which the user sees during checkout.'),
                        'required' => false
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Description'),
                        'name' => 'PAYSLEY_DESCRIPTION',
                        'desc' => $this->l('This is the description which the user sees during checkout.'),
                        'required' => false
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Access Key'),
                        'name' => 'PAYSLEY_ACCESS_KEY',
                        'desc' =>
                        $this->l('*This is the access key, received from Paysley developer portal. ( required )'),
                        'required' => true
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Enable transaction logging for paysley.'),
                        'name' => 'PAYSLEY_ENABLE_LOGGING',
                        'desc' => $this->l('Enable transaction logging for paysley.'),
                        'options'   =>
                               array(
                               'query' => $this->getEnableLogOption(),
                               'id'     => 'id',
                               'name'   => 'name'
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).
        '&configure='.
        $this->name.
        '&tab_module='.
        $this->tab.
        '&module_name='.
        $this->name;

        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
        );

        $this->fields_form = array();

        return $helper->generateForm(array($fields_form));
    }

    /**
     * get option value for logger.
     *
     * @return array
     */
    protected function getEnableLogOption()
    {
        $displayList = array (
            array(
               'id'     => 'log_option_yes',
               'name'   => $this->l('Yes')
            ),
            array(
               'id'     => "log_option_no",
               'name'   => $this->l('No')
            )
        );

        return $displayList;
    }

    /**
     * get value from paysley backend configuration.
     *
     * @return array
     */
    public function getConfigFieldsValues()
    {
        return array(
            'PAYSLEY_DESCRIPTION' => Tools::getValue(
                'PAYSLEY_DESCRIPTION',
                Configuration::get('PAYSLEY_DESCRIPTION')
            ),
            'PAYSLEY_TITLE' => Tools::getValue('PAYSLEY_TITLE', Configuration::get('PAYSLEY_TITLE')),
            'PAYSLEY_ACCESS_KEY' => "********",
            'PAYSLEY_ENABLE_LOGGING' => Tools::getValue(
                'PAYSLEY_ENABLE_LOGGING',
                Configuration::get('PAYSLEY_ENABLE_LOGGING')
            ),
        );
    }

    /**
     * get data order from database using cart id.
     * @param string $cartId
     *
     * @return array
     */
    public function getOrderByCartId($cartId)
    {
        $sql = "SELECT * FROM paysley_order_ref WHERE cart_id ='".pSQL($cartId)."'";
        $order = Db::getInstance()->getRow($sql);

        return $order;
    }

    /**
     * get template variable.
     *
     * @return array
     */
    public function getTemplateVars()
    {
        return [
            'paysley_description' => Configuration::get('PAYSLEY_DESCRIPTION'),
        ];
    }


    /**
     * generate secret key using cartId and currency
     * @param string $cartId
     * @param string $currency
     *
     * @return string
     */
    public function generateSecretKey($cartId, $currency)
    {
        $secretKey = md5($cartId.$currency);
        return $secretKey;
    }

    /**
     * add additional order status to prestashop.
     *
     * @return boolean
     */
    public function addPaysleyOrderStatus()
    {
        $stateConfig = array();
        try {
            $stateConfig['color'] = 'blue';
            $this->addOrderStatus(
                'PAYSLEY_PAYMENT_STATUS_PENDING',
                $this->l('Awaiting paysley payment'),
                $stateConfig
            );
            return true;
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * adding rule for additional order status.
     * @param string $configKey
     * @param string $statusName
     * @param array $stateConfig
     *
     * @return void
     */
    public function addOrderStatus($configKey, $statusName, $stateConfig)
    {
        if (!Configuration::get($configKey)) {
            $orderState = new OrderState();
            $orderState->name = array();
            $orderState->module_name = $this->name;
            $orderState->send_email = true;
            $orderState->color = $stateConfig['color'];
            $orderState->hidden = false;
            $orderState->delivery = false;
            $orderState->logable = true;
            $orderState->invoice = false;
            $orderState->paid = false;
            foreach (Language::getLanguages() as $language) {
                $orderState->template[$language['id_lang']] = 'payment';
                $orderState->name[$language['id_lang']] = $statusName;
            }

            if ($orderState->add()) {
                $paysleyIcon = dirname(__FILE__).'/logo.gif';
                $newStateIcon = dirname(__FILE__).'/../../img/os/'.(int)$orderState->id.'.gif';
                copy($paysleyIcon, $newStateIcon);
            }

            Configuration::updateValue($configKey, (int)$orderState->id);
        }
    }

    /**
     * the PrestaShop hook that will be triggered when doing update order status.
     * @param  array $params
     *
     * @return void
     */
    public function hookUpdateOrderStatus($params)
    {
        $order = new Order((int)($params['id_order']));
        $transactionLog = $this->getTransactionLogByOrderId($params['id_order']);
        
        if ($order->module == "paysley"
            && $order->current_state == Configuration::get('PS_OS_PAYMENT')
            && $params['newOrderStatus']->id == Configuration::get('PS_OS_REFUND')
        ) {
            if ($transactionLog['order_status'] == Configuration::get('PS_OS_PAYMENT')) {
                $refundResult = $this->refundOrder($params, $transactionLog);
                $refundStatus =  $refundResult["status"];

                if ($refundStatus == "refund") {
                    $this->updateTransactionLogStatus(
                        $transactionLog["payment_id"],
                        Configuration::get('PS_OS_REFUND'),
                        $params['id_order']
                    );
                    foreach ($order->getProductsDetail() as $product) {
                        StockAvailable::updateQuantity(
                            $product['product_id'],
                            $product['product_attribute_id'],
                            (int) $product['product_quantity'],
                            $order->id_shop
                        );
                    }
                    $this->context->cookie->paysley_status_refund = 'success';
                    if (isset($refundResult["balance"]) && $refundResult["balance"] != "0") {
                        $this->context->cookie->paysley_additional_info =
                        $this->l('Paysley notes: You still have amount to be refunded,').
                        $this->l(' because Merchant use tax/tip when customer paid. ').
                        $this->l('Please contact the merchant to refund the tax/tip amount.');
                    }
                    $messageLog = 'Paysley - order has been successfully refunded';
                    $this->addPluginLogger($messageLog, 1, null, 'Order', $params['id_order'], true);
                } else {
                    $this->context->cookie->paysley_status_refund = 'failed';
                    $messageLog = 'Paysley - order has not been successfully refunded';
                    $this->addPluginLogger($messageLog, 3, null, 'Order', $params['id_order'], true);
                    $this->redirectOrderDetail($params['id_order']);
                }
            }
        }
    }

    /**
     * update table paysley_order_ref using paymen_id.
     * @param  string $payment_id
     * @param  string $orderStatus
     * @param  string $orderId
     *
     * @return void
     */
    public function updateTransactionLogStatus($payment_id, $orderStatus, $orderId = false)
    {
        $sql = "UPDATE paysley_order_ref SET order_status = '".
        pSQL($orderStatus).
        "' where payment_id = '".
        pSQL($payment_id)."'";

        if ($orderId) {
            $objectType = 'Order';
            $objectId = $orderId;
        } else {
            $objectType = 'Cart';
            $objectId = $this->context->cart->id;
        }

        $messageLog = 'Paysley - update transaction log status : '. $sql;
        $this->addPluginLogger($messageLog, 1, null, $objectType, $objectId, true);

        if (!Db::getInstance()->execute($sql)) {
            $messageLog = 'Paysley - failed when updating transaction log status';
            $this->addPluginLogger($messageLog, 3, null, $objectType, $objectId, true);
            die('Erreur etc.');
        }
        $messageLog = 'Paysley - transaction log status succefully saved';
        $this->addPluginLogger($messageLog, 1, null, $objectType, $objectId, true);
    }

    /**
     * get transaction data from table paysley_order_ref using orderId.
     * @param  string $orderId
     *
     * @return array
     */
    protected function getTransactionLogByOrderId($orderId)
    {
        $sql = "SELECT * FROM paysley_order_ref WHERE order_id ='".(int)$orderId."'";
        return Db::getInstance()->getRow($sql);
    }

    /**
     * refund and partial refund to payment gateway.
     * @param  array $params
     * @param  array $transactionLog
     * @param  boolean $isPartial
     *
     * @return array
     */
    public function refundOrder($params, $transactionLog, $isPartial = false)
    {

        $objectType = 'Order';
        $objectId = $params['id_order'];
        $this->addPluginLogger('Paysley - start refund process', 1, null, $objectType, $objectId, true);

        $amount = isset($params['partial_amount']) ? $params['partial_amount'] : $transactionLog['amount'];
        $customer = $this->context->customer;
        $payment_id = $transactionLog['payment_id'];
        
        if ($isPartial) {
            $amount = $this->setNumberFormat($params['partial_amount']);
            $payment_id = $transactionLog['payment_id'];
        }

        $this->addPluginLogger('Paysley - get refund parameters', 1, null, $objectType, $objectId, true);
        $body       = array(
               'email'  => $customer->email,
               'amount' => (float)round($amount, 2),
           );
        $this->addPluginLogger(json_encode($body), 1, null, $objectType, $objectId, true);
        
        PaysleyApi::$accessKey = Configuration::get('PAYSLEY_ACCESS_KEY');
        $results = PaysleyApi::doRefund($payment_id, $body);

        $messageLog = 'Paysley - refund response : '. $results;
        $this->addPluginLogger($messageLog, 1, null, $objectType, $objectId, true);

        $refundResult = json_decode($results, 1);
        
        if ($refundResult["status"] != "refund") {
            $refundResult['status'] = "error";
        }

        return $refundResult;
    }


    /**
    * Hook action order slip add
    * @param $params array
    *
    * @return void
    */
    public function hookActionOrderSlipAdd($params)
    {
        $order = new Order((int)($params['order']->id));

        if (Tools::isSubmit('partialRefundProduct') && $order->module == "paysley"
            && ($refunds = Tools::getValue('partialRefundProduct'))
            && is_array($refunds)
        ) {
            $amount = 0;
        

            foreach ($params['productList'] as $product) {
                $amount += (float)$product['amount'];
            }
            
            if (Tools::getValue('partialRefundShippingCost')) {
                $amount += (float)Tools::getValue('partialRefundShippingCost');
            }
            
            $transactionLog = $this->getTransactionLogByOrderId($params['order']->id);
            $refundParams = array(
                        'id_order' => $params['order']->id,
                        'partial_amount' => $amount
                    );
            $messageLog = 'Paysley - refund parameters '.json_encode($refundParams);
            $this->addPluginLogger($messageLog, 3, null, 'Order', $params['id_order'], true);
            $messageLog = 'Paysley - transactionLog parameters '.json_encode($transactionLog);
            $this->addPluginLogger($messageLog, 3, null, 'Order', $params['id_order'], true);
            $refundStatus = $this->refundOrder(
                $refundParams,
                $transactionLog,
                false,
                true
            );

            if (isset($refundStatus['status']) && $refundStatus['status'] == 'error') {
                $this->context->cookie->paysley_status_partial_refund = 'failed';
                $messageLog = 'Paysley - order has not been successfully partial refunded';
                $this->addPluginLogger($messageLog, 3, null, 'Order', $params['id_order'], true);
                $this->redirectOrderDetail($params['order']->id);
            } else {
                $this->context->cookie->paysley_status_partial_refund = 'success';
                $paymentFee = round($this->getPaymentFee($transactionLog), 2);
                $balance = round((float)$refundStatus["balance"], 2);
                if ($balance <= $paymentFee && $balance > 0) {
                    $this->context->cookie->paysley_additional_info =
                    $this->l('Paysley notes: You still have amount to be refunded,').
                    $this->l(' because Merchant use tax/tip when customer paid. ').
                    $this->l('Please contact the merchant to refund the tax/tip amount.');
                }
                $messageLog = 'Paysley - order has been successfully partial refunded';
                $this->addPluginLogger($messageLog, 3, null, 'Order', $params['id_order'], true);
                $this->redirectOrderDetail($params['order']->id);
            }
        }
    }

    /**
     * Set the separator for the decimal point,
     * Set the number of decimal points.
     * @param string|float $number
     *
     * @return string
     */
    public function setNumberFormat($number)
    {
        $number = (float) str_replace(',', '.', $number);
        return number_format($number, 2, '.', '');
    }

    /**
     * redirection to order detail
     * @param string $orderId
     *
     * @return string
     */
    protected function redirectOrderDetail($orderId)
    {
        $getAdminLink = $this->context->link->getAdminLink('AdminOrders');
        $getViewOrder = $getAdminLink.'&vieworder&id_order='.$orderId;
        Tools::redirectAdmin($getViewOrder);
    }

    /**
     * the PrestaShop hook to display error/success message when
     * capture/refund/create an order at admin page.
     *
     * @return string
     */
    public function hookdisplayInvoice()
    {
        $tplVars = array();
        $tplVars['successMessage'] = '';
        $tplVars['errorMessage'] = '';

        if (isset($this->context->cookie->paysley_status_refund) ||
            isset($this->context->cookie->paysley_status_partial_refund)) {
            $notificationMessage = $this->getRefundedNotificationMessage();
        }

        $orderId = Tools::getValue('id_order');
        $order = new Order((int)$orderId);
        $tplVars['module'] = $order->module;

        if (isset($notificationMessage['success'])) {
            $tplVars['successMessage'] = $notificationMessage['success'];
        }
        if (isset($notificationMessage['error'])) {
            $tplVars['errorMessage'] = $notificationMessage['error'];
        }
        if (isset($this->context->cookie->paysley_additional_info)) {
            $tplVars['additionalInfo'] = $this->context->cookie->paysley_additional_info;
            unset($this->context->cookie->paysley_additional_info);
        }

        $this->context->smarty->assign($tplVars);

        return $this->display(__FILE__, 'views/templates/hook/displayStatusOrder.tpl');
    }

    /**
     * get refund notification message base on cookie
     *
     * @return string
     */
    protected function getRefundedNotificationMessage()
    {
        $notificationMessage = array();
        if ($this->context->cookie->paysley_status_refund == 'success') {
            $notificationMessage['success'] = "refund";
        } elseif ($this->context->cookie->paysley_status_refund == 'failed') {
            $notificationMessage['error'] = "refund";
        }

        if ($this->context->cookie->paysley_status_partial_refund == 'success') {
            $notificationMessage['success'] = "partial-refund";
        } elseif ($this->context->cookie->paysley_status_partial_refund == 'failed') {
            $notificationMessage['error'] = "partial-refund";
        }

        unset($this->context->cookie->paysley_status_partial_refund);
        unset($this->context->cookie->paysley_status_refund);

        return $notificationMessage;
    }

    /**
     * get payment fee from payment gateway using transactionLog
     * @param array $transactionLog
     *
     * @return string
     */
    protected function getPaymentFee($transactionLog)
    {
        $paymentResponse = unserialize($transactionLog["payment_response"]);
        $fee = (float)$paymentResponse["response"]["amount"] - (float)$transactionLog["amount"];
        return $fee;
    }


    /**
     * add plugin logger when logger config enable
     * @param string $message
     * @param int $severity
     * @param int $errorCode
     * @param string $objectType
     * @param string $objectId
     * @param boolead $allowDuplicate
     *
     * @return void
     */
    public function addPluginLogger(
        $message,
        $severity = 1,
        $errorCode = null,
        $objectType = null,
        $objectId = null,
        $allowDuplicate = false
    ) {

        if ($this->isLoggerActive) {
            PrestaShopLogger::addLog(
                $message,
                $severity,
                $errorCode,
                $objectType,
                $objectId,
                $allowDuplicate
            );
        }
    }

    /**
     * save transaction log into table paysley_order_ref.
     * @param  arrat $transactionLog
     * @param  string $orderId
     *
     * @return void
     */

    public function saveTransactionLog($transactionLog, $orderId)
    {
        $sql = "INSERT INTO paysley_order_ref (
            order_id,
            transaction_id,
            cart_id,
            order_status,
            payment_id,
            currency,
            amount,
            payment_response
        )
        VALUES "."('".
            (int)$orderId."','".
            pSQL($transactionLog['transaction_id'])."','".
            pSQL($transactionLog['cart_id'])."','".
            pSQL($transactionLog['order_status'])."','".
            pSQL($transactionLog['payment_id'])."','".
            pSQL($transactionLog['currency'])."','".
            (float)$transactionLog['amount']."','".
            pSQL($transactionLog['payment_response']).
        "')";

        $this->addPluginLogger('Paysley - save transaction log : ' . $sql, 1, null, 'Order', $orderId, true);

        if (!Db::getInstance()->execute($sql)) {
            $this->addPluginLogger(
                'Paysley - failed when saving transaction log',
                3,
                null,
                'Order',
                $orderId,
                true
            );
            die('Erreur etc.');
        }
        $this->addPluginLogger('Paysley - transaction log succefully saved', 1, null, 'Order', $orderId, true);
    }
}
