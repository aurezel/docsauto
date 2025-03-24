<?php 
	require 'vendor/autoload.php';
	require 'class_init.php';
	require 'config.php';

	$stripe = new StripeService(STRIPE_SK);
	$emails = $stripe->getTransactionsFromFile(CUSTOMER_EMAIL_FILE);
	var_dump($emails);
	if($_GET['act']){
		$stripe->getTransactions($emails, $_GET['act']); //交易时间，0，1，2
	}else{
		$stripe->getTransactions($emails); //交易时间，0，1，2
	}
	
    
?> 