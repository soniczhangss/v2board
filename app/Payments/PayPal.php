<?php

/**
 * 自己写别抄，抄NMB抄
 */
namespace App\Payments;

use Illuminate\Support\Facades\Log;

class PayPal {
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
            'return_base_url' => [
                'label' => '跳转URL',
                'description' => '仅含域名和路径(不含末尾斜杠)。例如: https://vps.utmtit.com.au/payment-gateway-dev',
                'type' => 'input',
            ],
            'client_id' => [
                'label' => 'client-id',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        info($order);
        $currency = $this->config['currency'];
        $exchange = $this->exchange('CNY', strtoupper($currency));
        if (!$exchange) {
            abort(500, __('Currency conversion has timed out, please try again later'));
        }
        $amount = floor($order['total_amount'] * $exchange) / 100;
        $client_id = $this->config['client_id'];
        
        return [
            'type' => 1,
            'data' => $this->config['return_base_url'] . "/paypal.php?redirect_url=" . urlencode($order['return_url']) . "&out_trade_no=" . $order['trade_no'] . "&client_id=" . $client_id . "&currency=" . $currency . "&amount=" . $amount
        ];
    }

    public function notify($params)
    {
        $trade_no = $params['resource']['purchase_units'][0]['custom_id'];
        $callback_no = $params['id'];
        Log::debug($params);
        return [
            'trade_no' => $trade_no,
            'callback_no' => $callback_no
        ];
        die('success');
    }

    private function exchange($from, $to)
    {
        $result = file_get_contents('https://api.exchangerate.host/latest?symbols=' . $to . '&base=' . $from);
        $result = json_decode($result, true);
        return $result['rates'][$to];
    }
}
