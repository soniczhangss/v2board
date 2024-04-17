<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use Stripe\Source;
use Stripe\Stripe;

use Illuminate\Support\Facades\Log;
require_once('defuse-crypto.phar');
use Defuse\Crypto\Key;
use Defuse\Crypto\Crypto;

use App\Models\User;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class MyCardPayments {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_pk_live' => [
                'label' => 'PK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => '',
                'type' => 'input',
            ],
            'encryption_key' => [
                'label' => '数据加密密钥',
                'description' => '',
                'type' => 'input',
            ],
            'return_base_url' => [
                'label' => '跳转URL',
                'description' => '仅含域名和路径(不含末尾斜杠)。例如: https://vps.utmtit.com.au/payment-gateway-dev',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $currency = $this->config['currency'];
        
        $user = User::find($order['user_id']);
        $authData = $this->getAuthData($order['user_id']);
        $data = array();
        $data['stripe_payment_intents_create_payload'] = array(
            'amount' => floor($order['total_amount'] * 1.0),
            'currency' => $currency,
            'payment_method_types' => ['card'],
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
                'email' => $user->email
            ]
        );
        $encrypted_data = $this->getEncryptedData(base64_encode(serialize($data)));
        return [
            'type' => 1,
            'data' => $this->config['return_base_url'] . "?data=" . $encrypted_data
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
}
