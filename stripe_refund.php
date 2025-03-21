<?php 
	require 'vendor/autoload.php';
	require 'class_init.php';
	require 'config.php';

	$csvFilePath = 'transaction.csv';   
	if (!file_exists($csvFilePath)) { 
		die("文件 $csvFilePath 不存在。"); 
	}  
	$file = fopen($csvFilePath, 'r');  
	$header = fgetcsv($file); 
	  
	$successStatus = 'succeeded'; 
	$stripe = new StripeService(STRIPE_SK);  
	while (($row = fgetcsv($file)) !== false) {  
		$transactionId = $row[1]; 
		$transactionStatus = $row[4]; 
	 
		// 检查交易状态是否为成功 
		if ($transactionStatus === $successStatus) { 
			 $result = $stripe->refundTransaction($transactionId);
			 if($result == $successStatus){
				echo $row[0]."\t".$row[1]."\t".$row[4]."\n";
			 }
		} 
	} 
	 
	// 关闭文件 
	fclose($file); 
?> 