<?php 
	require 'vendor/autoload.php';
	require 'class_init.php';
	require 'config.php';

// 	$data = ["transaction_id"=>"amount"]
    $data = [];
	$successStatus = 'succeeded'; 
	$stripe = new StripeService(STRIPE_SK);
	if(count($data)>0){
	    foreach($data as $transaction_id => $amount){
	        if($amount > 0){
	            $result = $stripe->refundTransaction($transaction_id, $amount);
	            if($result == $successStatus){
                    echo $transaction_id." 成功退款{$amount}\n";
                 }
	        }
	    }
	}

?> 