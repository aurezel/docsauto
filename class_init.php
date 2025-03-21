<?php 
class StripeService {
    private $stripe;
	private $productNames = ["Entire Total","Full Total","Overall Total","Complete Total","Whole Total","Sum Total","Gross Total","Final Amount","Complete Sum","Grand Total"];
    private $customerEmail = [];
	public function __construct($apiKey) {
        \Stripe\Stripe::setApiKey($apiKey);
    }

	 
    /**
     * 查询交易信息
     * @param string $email 用户邮箱
     * @return array 交易详情
     */
    private function getCustomerIdByEmail($email) {
        $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 1]);
        return !empty($customers->data) ? $customers->data[0]->id : null;
    }

	public function getTransactions($emails) {
        $filteredOrders = [];
		$this->emails = $emails;
        foreach ($this->emails as $email) {
            echo "Fetching transactions for: $email\n";
            $customerId = $this->getCustomerIdByEmail($email);
            
            // **查询最近 6 个月的交易**
            $startDate = strtotime('-6 months');
            $hasMore = true;
            $lastChargeId = null;

            while ($hasMore) {
                $params = ['limit' => 100]; // 分页查询，每次取 100 条
                if ($lastChargeId) {
                    $params['starting_after'] = $lastChargeId;
                }

                // **查询 Charge**
                $charges = \Stripe\Charge::all($params);

                foreach ($charges->data as $charge) {
                    if (($customerId && $charge->customer === $customerId) || 
                        (isset($charge->billing_details->email) && $charge->billing_details->email === $email)) {
                        
                        // **时间范围过滤**
                        if ($charge->created >= $startDate) {
                            $filteredOrders[] = [
                                $email,
                                $charge->id,
                                $charge->amount / 100,
                                strtoupper($charge->currency),
                                $charge->status,
                                date('Y-m-d H:i:s', $charge->created),
                            ];
                        }
                    }
                }

                // **是否还有更多数据**
                $hasMore = $charges->has_more;
                if ($hasMore) {
                    $lastChargeId = end($charges->data)->id;
                }
            }
        }
 
        $this->saveToCsv($filteredOrders,1);
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
        $csvContent = "email,transaction_id,amount,currency,status,created_at\n"; // CSV 头部
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
     * @param int|null $amount 退款金额（单位：分），不填则全额退款
     * @return array 退款结果
     */
    public function refundTransaction($transactionId, $amount = null) {
        try {
            $refundData = ['charge' => $transactionId];
            if ($amount !== null) {
                $refundData['amount'] = $amount; // 单位是分
            }
			$refundData['reason'] = 'requested_by_customer';

            $refund = \Stripe\Refund::create($refundData); 
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
}
