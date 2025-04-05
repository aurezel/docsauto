<?php 
class StripeService {
    private $stripe;
	private $productNames = ["Entire Total","Full Total","Overall Total","Complete Total","Whole Total","Sum Total","Gross Total","Final Amount","Complete Sum","Grand Total"];
    private $customerEmail = [];
	public function __construct($apiKey) {
        \Stripe\Stripe::setApiKey($apiKey);
    }

	 
    public function getTransactionsFromFile($filePath, $type = 1) {
		if (!file_exists($filePath)) {
			throw new Exception("File not found: $filePath");
		}

		// 读取文件并去除空行和空格
		$emails = array_filter(array_map('trim', file($filePath)));

		if (empty($emails)) {
			throw new Exception("No emails found in the file.");
		}
		return $emails;
	}

    private function getCustomerIdByEmail($email) {
		try {
			$customers = \Stripe\Customer::search([
				'query' => "email:'$email'",
				'limit' => 1
			]); 
			return !empty($customers->data) ? $customers->data[0]->id : null;
		} catch (\Stripe\Exception\ApiErrorException $e) {
			$this->handleStripeError($e);
			return null;
		}
}
	
	private function getDateRangeByType($type) {
		$timeZones = new DateTimeZone('UTC');
		switch ($type) {
			case 2:
				return [
					(new DateTime('6 months ago', $timeZones))->getTimestamp(),
					(new DateTime('4 months ago', $timeZones))->getTimestamp()
				];
			case 1:
				return [
					(new DateTime('4 months ago', $timeZones))->getTimestamp(),
					(new DateTime('2 months ago', $timeZones))->getTimestamp()
				];
			default:
				return [
					(new DateTime('2 months ago', $timeZones))->getTimestamp(),
					time()
				];
		}
	}
	
	private function isValidCharge($charge, $customerId, $email) {
		return $customerId 
			? $charge->customer === $customerId 
			: (strtolower(trim($charge->billing_details->email)) ?? null) === strtolower(trim($email));
	}
	//email,transaction_id,amount,currency,status,paymentIntent,refundStatus,refundAmount,created_at
	private function formatCharge($charge, $email){
		return $filteredOrders[] = [
                                $email,
                                $charge->id,
                                $charge->amount / 100,
                                strtoupper($charge->currency),
                                $charge->status,
								$charge->payment_intent,
								$charge->refunded ? 'Refunded' : 'No Refund',
								$charge->amount_refunded / 100 . ' ' . strtoupper($charge->currency)
                                date('Y-m-d H:i:s', $charge->created),
                            ];
	}

	public function getTransactions($emails, $type = 0) {
		 
		$filteredOrders = [];

		foreach ($emails as $email) {
			$this->log("Fetching transactions for: $email");
			$customerId = $this->getCustomerIdByEmail($email);
			var_dump($customerId,"==={$email}===");
			[$startDate, $endDate] = $this->getDateRangeByType($type);

			$params = [
				'limit' => 100, 
				'expand' => ['data.billing_details']
			];

			if ($customerId) {
				$params['customer'] = $customerId;
			}else{
				$params['created'] = ['gte' => $startDate, 'lt' => $endDate];
			}

			try {
				foreach (\Stripe\Charge::all($params)->autoPagingIterator() as $charge) {
					if ($this->isValidCharge($charge, $customerId, $email)) {
						$filteredOrders[] = $this->formatCharge($charge, $email);
					}
				}
			} catch (\Stripe\Exception\ApiErrorException $e) {
				$this->handleStripeError($e);
			}
		}

		$this->saveToCsv($filteredOrders, 1);
		return $filteredOrders;
	}

	private function saveToCsv($transactions,$init=0) {
		$file_csv = 'transaction.csv';
        if (empty($transactions)) {
            echo "No transactions found.\n";
            return;
        }
		if($init == 1){
			file_put_contents($file_csv, "");
		}
        // **构建 CSV 内容**
        $csvContent = "email,transaction_id,amount,currency,status,paymentIntent,refundStatus,refundAmount,created_at\n"; // CSV 头部
        foreach ($transactions as $order) {
            $csvContent .= implode(',', $order) . "\n";
        }

        // **写入文件**
        file_put_contents($file_csv, $csvContent, FILE_APPEND);

        echo "Transaction data saved to {$file_csv}\n";
    }
    /**
     * 退款交易
     * @param string $transactionId 交易 ID（Charge ID）
     * @param int|null $amount 退款金额（单位：USD），不填则全额退款
     * @return array 退款结果
     */
    public function refundTransaction($transaction_id, $amount = null) {
        try {
			if(substr($transaction_id, 0,3) == 'ch_') {
                $body = ['charge' => $transaction_id];
            } else if(substr($transaction_id, 0,3) == 'pi_') {
                $body = ['payment_intent' => $transaction_id];
            } 
            if ($amount !== null) {
                $body['amount'] = $amount * 100; // 单位是分
            }
			$body['reason'] = 'requested_by_customer';

            $refund = \Stripe\Refund::create($body); 
			return $refund->status;
            return [
                'refund_id' => $refund->id,
                'status' => $refund->status,
                'amount_refunded' => $refund->amount / 100,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return $e->getMessage();
        }
    }

    /**
     * 添加产品价格
     * @param string $productId 产品 ID
     * @param int $amount 价格（单位：分）
     * @param string $currency 货币类型（默认 USD）
     * @return array 价格创建结果
     */
  
	public function createProducts($count = 3) {
        $products = [];
		shuffle($this->productNames);
        for ($i = 0; $i < $count; $i++) {
            $products[] = \Stripe\Product::create([
                'name' => $this->productNames[$i] ?? 'Complete Total'
            ]);
        }
        return $products;
    }

	public function createPricesAndSaveToCSV($prices, $currency='USD') {
        try {
            $products = count($prices) > 50 ? $this->createProducts(3) : [$this->createProducts(1)[0]];

            $csvFile = 'products_prices.csv';
            $miniCsvFile = 'miniProducts.csv';
            $fileExists = file_exists($csvFile);
            $file = fopen($csvFile, 'a');
            $miniFile = fopen($miniCsvFile, 'a');

            // CSV 头部
            if (!$fileExists) {
                fputcsv($file, ["Price ID","Product ID","Product Name","Product Statement Descriptor","Product Tax Code","Description","Created (UTC)","Amount","Currency","Interval","Interval Count","Usage Type","Aggregate Usage","Billing Scheme","Trial Period Days","Tax Behavior"]);
            }

            $startPrice = rand(50, 60);
            $endPrice = rand(90, 110);

            foreach ($prices as $unitAmount) {
                if (count($prices) <= 50) {
                    $product = $products[0];
                } else {
                    if ($unitAmount <= $startPrice) {
                        $product = $products[0];
                    } elseif ($unitAmount <= $endPrice) {
                        $product = $products[1];
                    } else {
                        $product = $products[2];
                    }
                }

                $price = \Stripe\Price::create([
                    'product' => $product->id,
                    'currency' => $currency,
                    'unit_amount' => $unitAmount * 100
                ]);

                // 写入完整 CSV
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

                // 写入 mini CSV
                fputcsv($miniFile, [
                    $price->id,
                    $price->unit_amount / 100
                ]);
            }

            fclose($file);
            fclose($miniFile);
            echo 'Product and price created successfully. Data written to ' . $csvFile . PHP_EOL;
        } catch (\Exception $e) {
            echo "Failed to add product price: " . $e->getMessage();
        }
    }

	private function log($message) {
		echo "$message\n"; // 可改为 file_put_contents 或 Logger 记录
	}
}
