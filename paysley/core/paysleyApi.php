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

/**
 * Handles POS Link, Refunds and other API requests.
 *
 * @since 1.0.0
 */
class PaysleyApi
{

    /**
     * API Access Key
     *
     * @var string
     */
    public static $accessKey;

    /**
     * Is use test server or not
     *
     * @var bool
     */
    public static $isTestMode = false;

    /**
     * API live url
     *
     * @var string
     */
    public static $apiLiveUrl = 'https://live.paysley.io/v2';

    /**
     * API test url
     *
     * @var string
     */
    public static $apiTestUrl = 'https://stagetest.paysley.io/v2';

    /**
     * Get API url
     *
     * @return string
     */
    public static function getApiUrl()
    {
        if (self::$isTestMode) {
            return self::$apiTestUrl;
        }
        return self::$apiLiveUrl;
    }

    /**
     * Send request to the API
     *
     * @param string $url Url.
     * @param array  $body Body.
     * @param string $method Method.
     *
     * @return array
     */
    public static function sendRequest($url, $body = '', $method = 'GET')
    {
        $headers = array(
                "Authorization:Bearer ".self::$accessKey
            );
        if ('POST' === $method || 'PUT' === $method) {
            array_push($headers, "content-type: application/json");
        }

        $data = json_encode($body);
        $curl = curl_init();

        curl_setopt_array($curl, array(
          CURLOPT_CUSTOMREQUEST => Tools::strtoupper($method),
          CURLOPT_URL => $url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 70,
          CURLOPT_SSL_VERIFYPEER => false,
          CURLOPT_POSTFIELDS => $data,
          CURLOPT_HTTPHEADER => $headers,
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            echo "cURL Error #:" . $err;
        }
        
        return $response;
    }

    /**
     * Get pos link url with the API.
     *
     * @param array $body Body.
     *
     * @return array
     */
    public static function generatePosLink($body)
    {
        $url = self::getApiUrl() . '/pos/generate-link';
        return self::sendRequest($url, $body, 'POST');
    }

    /**
     * Do refund with the API.
     *
     * @param string $payment_id Payment ID.
     * @param array  $body Body.
     *
     * @return array
     */
    public static function doRefund($payment_id, $body)
    {
        $url = self::getApiUrl() . '/refunds/' . $payment_id;
        return self::sendRequest($url, $body, 'POST');
    }


    /**
     * Get payment detail with the API.
     *
     * @param string $payment_id Payment ID.
     *
     * @return array
     */
    public static function getPayment($payment_id)
    {
        $url = self::get_api_url() . '/payments/' . $payment_id;
        return self::send_request($url);
    }
}
