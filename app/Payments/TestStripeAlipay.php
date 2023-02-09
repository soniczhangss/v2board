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

class TestStripeAlipay {
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
        $exchange = $this->exchange('CNY', strtoupper($currency));
        if (!$exchange) {
            abort(500, __('Currency conversion has timed out, please try again later'));
        }
        $return_url_components = parse_url($order['return_url']);
        
        $user = User::find($order['user_id']);
        $data = array();
        $data['stripe_source_create_payload'] = array(
            'amount' => floor($order['total_amount'] * $exchange),
            'currency' => $currency,
            'type' => 'alipay',
            'statement_descriptor' => $order['trade_no'],
            'metadata' => [
                'user_id' => $order['user_id'],
                'out_trade_no' => $order['trade_no'],
                'identifier' => '',
                'from_host' => $this->getEncryptedData($return_url_components['host']),
                'sk' => $this->getEncryptedData($this->config['stripe_sk_live']),
                'whsec' => $this->getEncryptedData($this->config['stripe_webhook_key']),
                'email' => $user->email
            ],
            'redirect' => [
                'return_url' => str_replace($return_url_components['scheme'] . "://" . $return_url_components['host'], $this->config['return_base_url'] . "/redirect.php", $order['return_url'])
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

    private function exchange($from, $to)
    {
        $result = file_get_contents('https://api.exchangerate.host/latest?symbols=' . $to . '&base=' . $from);
        $result = json_decode($result, true);
        return $result['rates'][$to];
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
}
