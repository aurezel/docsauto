<?php 
	require 'vendor/autoload.php';
	require 'class_init.php';
	require 'config.php';

	$stripe = new StripeService(STRIPE_SK);
	$stripe->getTransactions(CUSTOMER_EMAIL); //交易时间，0，1，2
?> 