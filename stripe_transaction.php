<?php 

require 'vendor/autoload.php';
require 'config.php';
\Stripe\Stripe::setApiKey(STRIPE_SK);

$customerEmail = CUSTOMER_EMAIL;

$paymentIntents = \Stripe\PaymentIntent::all(['limit' => 100]); // 获取最近 100 笔订单


$filteredOrders = [];

$csvFile = 'transaction.csv'; 
$fileExists = file_exists($csvFile);
$file = fopen($csvFile, 'a');

if (!$fileExists) {
		fputcsv($file, ["email","transaction_id","amount","currency","status","created_at"]);
}

foreach ($paymentIntents->autoPagingIterator() as $paymentIntent) {
    if (!empty($paymentIntent->charges->data)) {
        $charge = $paymentIntent->charges->data[0];

        if (isset($charge->billing_details->email) && $charge->billing_details->email === $customerEmail) {
            
			fputcsv($file, $filteredOrders[] = [
                'transaction_id' => $charge->id,
                'amount' => $charge->amount / 100, // Stripe 以分为单位，转换为元/美元
                'currency' => strtoupper($charge->currency),
                'status' => $paymentIntent->status,
                'created_at' => date('Y-m-d H:i:s', $paymentIntent->created),
            ]);
        }
    }
}
print_r($filteredOrders);
fclose($file); 