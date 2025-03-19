<?php 
				require 'vendor/autoload.php';
				$prices = [4.99,5.99,9.99,14.99,19.99,29.99,49.99,99.99,199.99,149.99,299.99,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116,117,118,119,120,121,122,123,124,125,126,127,128,129,130,131,132,133,134,135,136,137,138,139,140,141,142,143,144,145,146,147,148,149,150];
        $products_name = ["Complete Total","Grand Total","Final Total","Overall","Standard Plan","Premium Package","Basic Subscription","Pro Plan","Ultimate Access","Monthly Membership","Annual Subscription","Starter Tier","Enterprise Solution","One-Time Purchase","Deluxe Option","Essential Service","Advanced Features","Business Edition","Custom Package","Exclusive Deal","VIP Access","Complete Service","Extended License"];
        shuffle($products_name);
        $currency = "EUR";
 
       \Stripe\Stripe::setApiKey('');	//must fill stripe sk 
				$start_price = rand(40,60);
				$end_price = rand(90,110);
        try {
						$products = [];
						if(count($prices)>50){
							for ($i = 0; $i < 3; $i++) {
									$products[] = \Stripe\Product::create([
											'name' => $products_name[$i] ?? 'Complete Total', // 使用提供的名称或默认名称
									]);
							} 
						}else{
							$products[] = \Stripe\Product::create([
									'name' => $products_name[0], // 使用第一个产品名称
							]);
						}
						 
						$csvFile = 'products_prices.csv'; 
						$fileExists = file_exists($csvFile);
						$file = fopen($csvFile, 'a');
 
						if (!$fileExists) {
								fputcsv($file, ["Price ID","Product ID","Product Name","Product Statement Descriptor","Product Tax Code","Description","Created (UTC)","Amount","Currency","Interval","Interval Count","Usage Type","Aggregate Usage","Billing Scheme","Trial Period Days","Tax Behavior"]);
						}

						$csvMiniFile = 'miniProducts.csv';  
						$miniFile = fopen($csvMiniFile, 'a');

            foreach ($prices as $unitAmount) {
                if (count($prices) <= 50) {
										$product = $products[0]; // 只使用第一个产品
								} else {
										if ($unitAmount <= $start_price) {
												$product = $products[0]; // 价格 <= 50，使用第一个产品
										} elseif ($unitAmount <= $end_price) {
												$product = $products[1]; // 50 < 价格 <= 100，使用第二个产品
										} else {
												$product = $products[2]; // 价格 > 100，使用第三个产品
										}
								}

                $price = \Stripe\Price::create([
										'product' => $product->id,
                    'currency' => $currency,
                    'unit_amount' => $unitAmount * 100, // 金额以分计算 
                ]);
								fputcsv($file, [
										$price->id, // Price ID
										$product->id, // Product ID
										$product->name, // Product Name
										$product->statement_descriptor ?? '', // Product Statement Descriptor
										$product->tax_code ?? '', // Product Tax Code
										$product->description ?? '', // Description
										date('Y-m-d H:i:s', $product->created), // Created (UTC)
										$price->unit_amount / 100, // Amount（转换为标准金额格式）
										strtoupper($price->currency), // Currency
										$price->recurring->interval ?? '', // Interval
										$price->recurring->interval_count ?? '', // Interval Count
										$price->recurring->usage_type ?? '', // Usage Type
										$price->recurring->aggregate_usage ?? '', // Aggregate Usage
										$price->billing_scheme, // Billing Scheme
										$price->recurring->trial_period_days ?? '', // Trial Period Days
										$price->tax_behavior ?? '', // Tax Behavior
								]);

								fputcsv($miniFile, [
										$price->id, 
										$price->unit_amount / 100
								]);
 
            }

						fclose($file); // 关闭文件 
						echo 'Product and price created successfully. Data written to ' . $csvFile . PHP_EOL;
            
        }catch(\Exception $e) {
            echo "It's failure to add product price ";
        }
