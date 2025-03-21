<?php 
class StripeService {
    private $stripe;

    public function __construct($apiKey) {
        \Stripe\Stripe::setApiKey($apiKey);
    }

	private $productName = ["Entire Total","Full Total","Overall Total","Complete Total","Whole Total","Sum Total","Gross Total","Final Amount","Complete Sum","Grand Total"];

    /**
     * 查询交易信息
     * @param string $email 用户邮箱
     * @return array 交易详情
     */
    public function getTransactionsByEmail($email) {
        try {
            $paymentIntents = \Stripe\PaymentIntent::all(['limit' => 100]);
            $transactions = [];

            foreach ($paymentIntents->autoPagingIterator() as $paymentIntent) {
                if (!empty($paymentIntent->charges->data)) {
                    $charge = $paymentIntent->charges->data[0];
                    if (isset($charge->billing_details->email) && $charge->billing_details->email === $email) {
                        $transactions[] = [
                            'transaction_id' => $charge->id,
                            'amount' => $charge->amount / 100,
                            'currency' => strtoupper($charge->currency),
                            'status' => $paymentIntent->status,
                            'created_at' => date('Y-m-d H:i:s', $paymentIntent->created),
                        ];
                    }
                }
            }
            return $transactions;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return ['error' => $e->getMessage()];
        }
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

            $refund = \Stripe\Refund::create($refundData);

            return [
                'refund_id' => $refund->id,
                'status' => $refund->status,
                'amount_refunded' => $refund->amount / 100,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return ['error' => $e->getMessage()];
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
