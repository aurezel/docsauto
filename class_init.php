<?php 
class StripeService {
    private $stripe;
	private $productNames = ["Entire Total","Full Total","Overall Total","Complete Total","Whole Total","Sum Total","Gross Total","Final Amount","Complete Sum","Grand Total","Entire Sum","Full Amount","Overall Sum","Whole Amount","Final Total","Aggregate Total","Final Sum","Net Total","Total Amount","Total Sum","Final Figure","Entire Amount","Final Value","Gross Amount","Grand Sum","Complete Figure","Cumulative Total","Complete Amount","Whole Figure","Net Amount","Full Sum","Absolute Total","Total Balance","Total Charge","Invoice Total","Final Count","Whole Count","Full Balance","Complete Balance","Total Value","Grand Figure","Final Payment","Total Quantity","Entire Balance","Final Settlement","Total Payable","Sum Amount","Final Gross","Gross Sum","Total Result","Total Revenue","Overall Charge","Overall Amount","Whole Charge","Total Collection","Total Number","Final Collection","Grand Amount","Complete Revenue","Final Charge","Entire Value","Full Count","Total Line","Full Settlement","Final Invoice","Total Cost","Final Output","Net Sum","Complete Output","Entire Figure","Whole Sum","Final Result","Total Due","Entire Invoice","Whole Payment","Overall Figure","Total Funds","Invoice Amount","Net Figure","Total Payment","Full Revenue","Invoice Sum","Final Total Value","Accumulated Total","Final Calculation","Summed Total","Finalized Amount","Full Gross","Calculated Total","Rounded Total","Fixed Total","Grand Invoice","Full Invoice","Closing Total","Statement Total","Entire Payable","Net Charge","Collected Total","Cleared Total","Statement Amount"];
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
	
	private function isValidCharge($charge, $customerId, $emails=[]) {
		return $customerId 
			? $charge->customer === $customerId 
			: in_array((strtolower(trim($charge->billing_details->email)) ?? null),$emails);// === strtolower(trim($emails));
	}
	//email,transaction_id,amount,currency,status,paymentIntent,refundStatus,refundAmount,created_at
	private function formatCharge($charge){
		return $filteredOrders[] = [
                                $charge->billing_details->email,
                                $charge->id,
                                $charge->amount / 100,
                                strtoupper($charge->currency),
                                $charge->status,
								$charge->payment_intent,
								$charge->refunded ? 'Refunded' : 'No Refund',
								$charge->amount_refunded / 100 . ' ' . strtoupper($charge->currency),
                                date('Y-m-d H:i:s', $charge->created),
                            ];
	}

	public function getTransactions($emails, $type = 0) {
		 
		$filteredOrders = [];
		$customerListId = []; 
		$guestEmails = [];
		foreach ($emails as $email) { 
			$customerId = $this->getCustomerIdByEmail($email);
			if($customerId){
				$customerListId[] = $customerId;
			}else{
				$guestEmails[] = strtolower(trim($email));
			} 
		}

		$params = [
				'limit' => 100, 
				'expand' => ['data.billing_details']
			];
		if(!empty($customerListId)){
			foreach($customerListId as $cusId){
				$this->log("Fetching transactions for:{$cusId} === $email");
				$params['customer'] = $cusId;
				try {
					foreach (\Stripe\Charge::all($params)->autoPagingIterator() as $charge) { 
						if ($this->isValidCharge($charge, $cusId)) {
							$filteredOrders[] = $this->formatCharge($charge);
						}
					}
				} catch (\Stripe\Exception\ApiErrorException $e) {
					$this->handleStripeError($e);
				}
			}
		}

		if(!empty($guestEmails)){
			[$startDate, $endDate] = $this->getDateRangeByType($type);
			$params['created'] = ['gte' => $startDate, 'lt' => $endDate];
			unset($params['customer']);
			try {
				foreach (\Stripe\Charge::all($params)->autoPagingIterator() as $charge) {
					if ($this->isValidCharge($charge, false, $guestEmails)) {
						$this->log("Fetching transactions for: ". $charge->billing_details->email);
						$filteredOrders[] = $this->formatCharge($charge);
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
            $miniCsvFile = 'product.csv';
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
