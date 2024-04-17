<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use Stripe\Source;
use Stripe\Stripe;

use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
require_once('defuse-crypto.phar');
use Defuse\Crypto\Key;
use Defuse\Crypto\Crypto;

use App\Models\User;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use App\Models\Order;

class PaymentGateway {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            // Common
            'currency' => [
                'label' => '货币单位',
                'description' => '',
                'type' => 'input',
            ],
            'encryption_key' => [
                'label' => '数据加密密钥',
                'description' => '',
                'type' => 'input',
            ],
            // Stripe
            // 'stripe_enabled' => [
            //     'label' => 'Stripe 启用',
            //     'description' => '启用：1，禁用：0',
            //     'type' => 'input',
            // ],
            'stripe_pk_live' => [
                'label' => 'Stripe PK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'Stripe SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'Stripe WebHook密钥签名',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_payment_url' => [
                'label' => 'Stripe支付入口URL',
                'description' => '例如: https://vps.utmtit.com.au/payment-gateway-v2/stripe/index.php',
                'type' => 'input',
            ],
            // SeaPay
            'seapay_enabled' => [
                'label' => 'SeaPay 启用',
                'description' => '启用：1，禁用：0',
                'type' => 'input',
            ],
            'seapay_gateway' => [
                'label' => 'SeaPay ApiUrl',
                'description' => '请填写下单地址',
                'type' => 'input',
            ],
            'seapay_user_id' => [
                'label' => 'SeaPay UserID',
                'description' => '请填写商户号',
                'type' => 'input',
            ],
            'seapay_user_key' => [
                'label' => 'SeaPay UserKey',
                'description' => '请填写商户秘钥',
                'type' => 'input',
            ],
            'seapay_payment_url' => [
                'label' => 'SeaPay支付入口URL',
                'description' => '例如: https://vps.utmtit.com.au/payment-gateway-v2/seapay/index.php',
                'type' => 'input',
            ],
            'seapay_callback_url' => [
                'label' => 'SeaPay支付成功回调URL',
                'description' => '例如: https://vps.utmtit.com.au/payment-gateway-v2/seapay/redirect.php',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        // determine the payment method (the API must find if there is an on-going transaction and must use the same payment method if there is an existing one)
        // passing in v2b order no., user_id, username, payment method
        $authData = $this->getAuthData($order['user_id']);
        $params = [
            'source' => 'v2b_op',
            'payment_gateway' => 'stripe',
            'payment_method' => 'card',
            'user_id' => $order['user_id'],
            'order_no' => $order['trade_no'],
            'status' => 'created',
            'from_host' => $_SERVER["HTTP_HOST"],
            'total_amount' => sprintf('%0.2f', ($order['total_amount'] / 100)),
            'v2b_auth_token' => $authData
        ];

        $data = $this->getStripePayload($order);
        $redirect_url = $this->config['stripe_payment_url'];
        if ($this->config["seapay_enabled"]) {
            $params['payment_gateway'] = 'seapay';
            $params['payment_method'] = 'unknown';

            $data = $this->getSeaPayPayload($order);
            $redirect_url = $this->config['seapay_payment_url'];
        }
        $encrypted_data = $this->getEncryptedData(base64_encode(serialize($data)));

        $this->callAPI($params);

        return [
            'type' => 1,
            'data' => $redirect_url . "?data=" . $encrypted_data
        ];
    }

    public function notify($params)
    {
        $array = json_decode(array_key_first($params), true);
        return [
            'trade_no' => $array['trade_no'],
            'callback_no' => $array['callback_no']
        ];
     	die('success');
    }

    private function getDogPayPayload($order) {
        $notifyUrl = "https://" . parse_url($this->config['seapay_callback_url'], PHP_URL_HOST) . parse_url($order['notify_url'], PHP_URL_PATH) . "?order_no=" . $order['trade_no'] . "&source=v2b_op";

        $data = array();
        $params = array(
            'pid' => $this->config['seapay_user_id'],
            'OrderNo' => $order['trade_no'],
            'TotalFee' => sprintf('%0.2f', ($order['total_amount'] / 100)),
			'RequestTime' => time(),
            'NotifyUrl' => $notifyUrl,
            'CallBackUrl' => $this->config['seapay_callback_url'] . "?order_no=" . $order['trade_no'] . "&source=v2b_op"    //单域名同步回调       与多域名仅可开启一个                        
            //'CallBackUrl' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/#/order/' . $order['trade_no']      //多域名同步回调
        );
		$params['Sign'] = $this->sign($params, $this->config['seapay_user_key']);
        $data["params"] = $params;
        $authData = $this->getAuthData($order['user_id']);
        $data["metadata"] = array(
            'source' => 'v2b_op',
            'out_trade_no' => $order['trade_no']
        );

        return $data;
    }

    private function getSeaPayPayload($order) {
        $notifyUrl = "https://" . parse_url($this->config['seapay_callback_url'], PHP_URL_HOST) . parse_url($order['notify_url'], PHP_URL_PATH) . "?order_no=" . $order['trade_no'] . "&source=v2b_op";

        $data = array();
        $params = array(
            'UserID' => $this->config['seapay_user_id'],
            'OrderNo' => $order['trade_no'],
            'TotalFee' => sprintf('%0.2f', ($order['total_amount'] / 100)),
			'RequestTime' => time(),
            'NotifyUrl' => $notifyUrl,
            'CallBackUrl' => $this->config['seapay_callback_url'] . "?order_no=" . $order['trade_no'] . "&source=v2b_op"    //单域名同步回调       与多域名仅可开启一个                        
            //'CallBackUrl' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/#/order/' . $order['trade_no']      //多域名同步回调
        );
		$params['Sign'] = $this->sign($params, $this->config['seapay_user_key']);
        $data["params"] = $params;
        $authData = $this->getAuthData($order['user_id']);
        $data["metadata"] = array(
            'source' => 'v2b_op',
            'out_trade_no' => $order['trade_no']
        );

        return $data;
    }

    private function getStripePayload($order) {
        $anySuccessOrder = Order::firstWhere(['user_id' => $order['user_id'], 'status' => 3]);
        $paymentTypes = ['card'];
        // if (isset($anySuccessOrder)) {
        //     $paymentTypes[] = "alipay";
        // }
        $currency = $this->config['currency'];
        
        $user = User::find($order['user_id']);
        $authData = $this->getAuthData($order['user_id']);
        $data = array();
        $data['stripe_payment_intents_create_payload'] = array(
            'amount' => floor($order['total_amount'] * 1.0),
            'currency' => $currency,
            'payment_method_types' => $paymentTypes,
            'description' => $order['trade_no'],
            'metadata' => [
                'user_id' => $order['user_id'],
                'out_trade_no' => $order['trade_no'],
                'identifier' => '',
                'auth' => $this->getEncryptedData($authData),
                'from_host' => $this->getEncryptedData($_SERVER["HTTP_HOST"]),
                'sk' => $this->getEncryptedData($this->config['stripe_sk_live']),
                'pk' => $this->getEncryptedData($this->config['stripe_pk_live']),
                'whsec' => $this->getEncryptedData($this->config['stripe_webhook_key']),
                'email' => $user->email,
                'source' => 'v2b_op'
            ]
        );
        
        return $data;
    }

    private function getEncryptedData($raw_data) {
        $keyAscii = $this->config['encryption_key'];
        $key = Key::loadFromAsciiSafeString($keyAscii);
        try {
            return Crypto::encrypt($raw_data, $key);
        } catch (\Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex) {
            // An attack! Either the wrong key was loaded, or the ciphertext has
            // changed since it was created -- either corrupted in the database or
            // intentionally modified by Eve trying to carry out an attack.
    
            // ... handle this case in a way that's suitable to your application ...
            die("Access denied.");
        }
    }

    private function getAuthData($userId) {
        $guid = $this->getSessions($userId);
        $authData = JWT::encode([
            'id' => $userId,
            'session' => array_key_last($guid),
        ], config('app.key'), 'HS256');
        return $authData;
    }

    private function getSessions($userId) {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId), []);
    }

    private function sign(array $params, string $key){
        $args = array_filter($params, function ($i, $k){
            if($k != 'Sign' && !empty($i)) return true;
        }, ARRAY_FILTER_USE_BOTH);
        ksort($args);
        $str = urldecode(http_build_query($args));
        return md5($str . "&UserKey={$key}");
    }

    private function callAPI($params) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://vps.utmtit.com.au/checkout-api/orders");
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8', 'x-api-key: g3rHrFfX739b']);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
        $res = curl_exec($curl);
        curl_close($curl);
        return json_decode($res, true);
    }
}
