<?php 
	require 'vendor/autoload.php';
	require 'class_init.php';
	require 'config.php';

	$stripe = new StripeService(STRIPE_SK);
	$stripe->getTransactions(CUSTOMER_EMAIL, 1); //交易时间，-30 days,1 month,2 months
?> 