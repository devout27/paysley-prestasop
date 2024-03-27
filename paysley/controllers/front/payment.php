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
        $cartObj = new Cart($cart->id);
        $customerId = (int)$cartObj->id_customer;
        $customer = new Customer($customerId);
        if (empty($customer)) {
            $this->redirectError(
                $this->l('Error while Processing Request: please try again.')
            );
        }
        $contextLink = $this->context->link;
        $currency = new Currency((int)$cart->id_currency);
        $secretKey = $this->module->generateSecretKey(
            $this->context->cart->id,
            $currency->iso_code
        );
        $customerEmail = $customer->email ?? "";
        $deliveryData = $this->context->cart->getSummaryDetails();
        $mobileNumber = !empty($deliveryData['delivery']->phone) ? PaysleyApi::getCountryPhoneCode(PaysleyApi::getCountryIso2($deliveryData['delivery']->country ?? "")).$deliveryData['delivery']->phone : "";
        $this->createOrUpdateCustomerOnPaysley($deliveryData, $customerEmail, $mobileNumber);
        $body = array(
            'reference_number'      => $reference,
            'payment_type'          => $this->paymentType,
            'request_methods'       => ["WEB"],
            'email'                 => $customerEmail,
            'currency'              => $currency->iso_code,
            'mobile_number'         => $mobileNumber,
            'customer_first_name'   => $customer->firstname ?? "",
            'customer_last_name'    => $customer->lastname ?? "",
            'amount'                => $this->context->cart->getOrderTotal(true, Cart::BOTH),
            'cart_items'            => $this->getCartItems(),
            'fixed_amount'          => true,
            'cancel_url'            => $contextLink->getPageLink('order', true, null),
            'redirect_url' => $this->context->link->getModuleLink(
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
        $messageLog = 'Paysley - postLink parameter '.json_encode($logData);
        $this->module->addPluginLogger($messageLog, 3, null, 'Cart', 0, true);
        $postLink = PaysleyApi::generatePosLink($body);
        $messageLog = 'Paysley - postLink  '.json_encode($postLink);
        $this->module->addPluginLogger($messageLog, 3, null, 'Cart', 0, true);

        if (isset($postLink['result']) && $postLink['result'] == 'success') {
            $this->context->cookie->id_transaction =  $postLink['transaction_id'];
            return $postLink['long_url'];
        } elseif (isset($postLink['error_field']) && $postLink['error_field'] == 'currency') {
            $this->module->addPluginLogger('Paysley - post link error currency', 3, null, 'Cart', $cart->id, true);
            $this->redirectError(
                $this->l('We are sorry, currency is not supported. Please contact us.')
            );
        } elseif (isset($postLink['error_field']) && !empty($postLink['error_message'])) {
            $this->redirectError(
                $this->l($postLink['error_message'])
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
    public function getCartItems ()
    {
        $carts = $this->context->cart->getSummaryDetails();
        $products = $carts['products'] ?? [];
        $cartItems = array();
        if (!empty($products)) {
            foreach ($products as $product) {
                $cartItems[] = array(
                    'name' => $product['name'],
                    'qty'  => (int)$product['cart_quantity'],
                    'sales_price' => (float)$this->module->setNumberFormat($product['price_wt']),
                    // 'sku '=> $product['reference'] ?? "",
                    'unit' => ['pc'],
                    'product_service_id' => $this->createOrUpdateProductOnPaysley($product),
                );
            }
        }
        return $cartItems;
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

    /**
     * Function to create or update products in paysley
     * @param array $product
     * @return int $paysleyProductId
     */
    public function createOrUpdateProductOnPaysley($product = [])
	{
        $paysleyProductId = null;
		if (empty($product)) {
			return $paysleyProductId;
		}
		$data = [];
		$data['name'] = $product['name'];
		$data['description'] = $product['description_short'];
		$data['sku'] = $product['reference'];
		$data['category_id'] = $this->checkAndCreateProductCategoryOnPaysley($product['category']);
		$data['type'] = 'product';
		$data['manage_inventory'] = $product['quantity_available'] ? 1 : 0;
		$data['unit_in_stock'] = $product['stock_quantity'];
		$data['unit_low_stock'] = 2;
		$data['unit_type'] = 'flat-rate';
		$data['cost'] = (float)$this->module->setNumberFormat($product['price_wt']);
		$data['sales_price'] = (float)$this->module->setNumberFormat($product['price_wt']); 
		$data['image'] = $this->getProductImage($product); 
		$existingProducts = PaysleyApi::getProducts($product['name']);
		if (!empty($existingProducts['result']) && $existingProducts['result'] === "success" && !empty($existingProducts['product_services'])) {
            $data['id'] = $existingProducts['product_services'][0]['id'];
			$productResult = PaysleyApi::updateProduct($data);
		} else {
			$productResult = PaysleyApi::createProduct($data);
		}
		if (!empty($productResult['result']) && 'success' === $productResult['result']) {
			$paysleyProductId = !empty($productResult['product_and_service']) ? $productResult['product_and_service']['id'] : $productResult['id'];
		}
		return $paysleyProductId;
	}


    /**
     * Function to checkAndCreateProductCategory if category already exists then return the category else create category on paysley
     * @param string $category
     * @return $categoryid if data exists else null
     */
    protected function checkAndCreateProductCategoryOnPaysley ($category = "")
    {
        if (empty($category)) {
            $category = "No Category";
        }
        $categoryResult = PaysleyApi::categoryList($category);
        if (!empty($categoryResult['result']) && 'success' === $categoryResult['result'] && !empty($categoryResult['categories'])) {
            return $categoryResult['categories'][0]['id'];
        }
        $categoryCreateResult = PaysleyApi::createCategory(['name' => $category]);
        if (!empty($categoryCreateResult)) {
            return $categoryCreateResult['id'];
        }
        return null;
    }


    /**
	 * Create/Update Customer on paysley
     * @param array $deliveryData
     * @param string $customerEmail
     * @param string $mobileNumber
     * @return int $customerPaysleyId
	 */
	protected function createOrUpdateCustomerOnPaysley($deliveryData, $customerEmail, $mobileNumber)
	{
		$customerPaysleyId = null;
        //Get the exists customer lists
		$existsCustomers = PaysleyApi::customerList($customerEmail);
		if (!empty($existsCustomers['result']) && 'success' === $existsCustomers['result']) {
			$customerDataToUpdate = [];
			// Customer billing information details
			$customerDataToUpdate['email']         = $customerEmail;
			$customerDataToUpdate['mobile_no']     = $mobileNumber;
			$customerDataToUpdate['first_name']    = $deliveryData['delivery']->firstname ?? "";
			$customerDataToUpdate['last_name']     = $deliveryData['delivery']->lastname ?? "";
			$customerDataToUpdate['company_name']  = $deliveryData['delivery']->company ?? "";
			$customerDataToUpdate['listing_type']  = 'individual';
			$customerDataToUpdate['address_line1'] = $deliveryData['delivery']->address1 ?? "";
			$customerDataToUpdate['address_line2'] = $deliveryData['delivery']->address2 ?? "";
			$customerDataToUpdate['city'] 		   = $deliveryData['delivery']->city ?? "";
			$customerDataToUpdate['state'] 		   = $deliveryData['delivery_state'] ?? "";
			$customerDataToUpdate['postal_code']   = $deliveryData['delivery']->postcode ?? "";
			$customerDataToUpdate['country_iso']   = PaysleyApi::getCountryIso2($deliveryData['delivery']->country ?? "");
            //Check customers exists if exists then get set customer paysley id
			if (!empty($existsCustomers['customers'])) {
				$customerDataIndex = array_search($customerEmail, array_column($existsCustomers['customers'], 'email'));			
				$customerDataToUpdate['customer_id'] = $customerPaysleyId = $existsCustomers['customers'][$customerDataIndex]['customer_id'] ?? null;
			}
			if (!empty($customerPaysleyId)) {
                //Update customer
                $updateCustomerOnPaysleyResult = PaysleyApi::updateCustomer($customerDataToUpdate);
				if (!empty($updateCustomerOnPaysleyResult['result']) && 'success' === $updateCustomerOnPaysleyResult['result']) {
				}
			} else {
                //Create customer
				$createCustomerOnPaysleyResult = PaysleyApi::createCustomer($customerDataToUpdate);
				if (!empty($createCustomerOnPaysleyResult['result']) && 'success' === $createCustomerOnPaysleyResult['result']) {
					$customerPaysleyId = $createCustomerOnPaysleyResult['customer_id'];
				}
			}
		}
		return $customerPaysleyId;
	}

    /**
     * Function to get the product image
     * @param int $productId
     * @return string $imagePath
     */
    public function getProductImage ($product) {
        if (empty($product)) {
            return "";
        }
        $image = Image::getCover($product['id_product']);
        // Get the image URL
        $image_url = $this->context->link->getImageLink($product['link_rewrite'], $image['id_image'], 'home_default');
        return $image_url;
    }
}
