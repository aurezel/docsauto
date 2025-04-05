<?php
class StripeService {
    private const CSV_HEADER = "email,transaction_id,amount,currency,status,paymentIntent,refundStatus,refundAmount,created_at\n";
    private const PRODUCT_NAMES = [
        "Entire Total", "Full Total", "Overall Total", "Complete Total", 
        "Whole Total", "Sum Total", "Gross Total", "Final Amount", 
        "Complete Sum", "Grand Total"
    ];
    
    private $stripe;
    private $logger;

    public function __construct(string $apiKey, ?callable $logger = null) {
        \Stripe\Stripe::setApiKey($apiKey);
        $this->logger = $logger ?? function($message) {
            echo date('[Y-m-d H:i:s]') . " $message\n";
        };
    }

    public function getTransactionsFromFile(string $filePath, int $type = 1): array {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: $filePath");
        }

        $emails = array_filter(
            array_map('trim', file($filePath)),
            fn($email) => !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
        );

        if (empty($emails)) {
            throw new \RuntimeException("No valid emails found in the file.");
        }

        return $emails;
    }

    private function getCustomerIdByEmail(string $email): ?string {
        try {
            $customers = \Stripe\Customer::search([
                'query' => "email:'" . addslashes($email) . "'",
                'limit' => 1
            ]);
            
            return $customers->data[0]->id ?? null;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->handleStripeError($e);
            return null;
        }
    }
    
    private function getDateRangeByType(int $type): array {
        $timeZone = new DateTimeZone('UTC');
        $now = new DateTime('now', $timeZone);
        
        return match ($type) {
            2 => [
                (new DateTime('6 months ago', $timeZone))->getTimestamp(),
                (new DateTime('4 months ago', $timeZone))->getTimestamp()
            ],
            1 => [
                (new DateTime('4 months ago', $timeZone))->getTimestamp(),
                (new DateTime('2 months ago', $timeZone))->getTimestamp()
            ],
            default => [
                (new DateTime('2 months ago', $timeZone))->getTimestamp(),
                $now->getTimestamp()
            ]
        };
    }
    
    private function isValidCharge(\Stripe\Charge $charge, ?string $customerId, array $emails = []): bool {
        if ($customerId) {
            return $charge->customer === $customerId;
        }
        
        $chargeEmail = strtolower(trim($charge->billing_details->email ?? ''));
        return in_array($chargeEmail, $emails);
    }

    private function formatCharge(\Stripe\Charge $charge, string $email): array {
        return [
            $email,
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

    public function getTransactions(array $emails, int $type = 0): array {
        $processedData = $this->preprocessEmails($emails);
        $customerList = $processedData['customers'];
        $guestEmails = $processedData['guestEmails'];
        
        $transactions = [];
        
        // Process registered customers
        if (!empty($customerList)) {
            $transactions = array_merge(
                $transactions,
                $this->fetchCustomerTransactions($customerList)
            );
        }
        
        // Process guest customers
        if (!empty($guestEmails)) {
            [$startDate, $endDate] = $this->getDateRangeByType($type);
            $transactions = array_merge(
                $transactions,
                $this->fetchGuestTransactions($guestEmails, $startDate, $endDate)
            );
        }
        
        $this->saveToCsv($transactions);
        return $transactions;
    }

    private function preprocessEmails(array $emails): array {
        $customers = [];
        $guestEmails = [];
        
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            $customerId = $this->getCustomerIdByEmail($email);
            
            if ($customerId) {
                $customers[$customerId] = $email;
            } else {
                $guestEmails[] = $email;
            }
        }
        
        return [
            'customers' => $customers,
            'guestEmails' => $guestEmails
        ];
    }

    private function fetchCustomerTransactions(array $customerList): array {
        $transactions = [];
        $params = [
            'limit' => 100,
            'expand' => ['data.billing_details']
        ];
        
        foreach ($customerList as $customerId => $email) {
            $this->log("Fetching transactions for customer: $customerId (Email: $email)");
            $params['customer'] = $customerId;
            
            try {
                $charges = \Stripe\Charge::all($params);
                
                foreach ($charges->autoPagingIterator() as $charge) {
                    if ($this->isValidCharge($charge, $customerId)) {
                        $transactions[] = $this->formatCharge($charge, $email);
                    }
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $this->handleStripeError($e);
            }
        }
        
        return $transactions;
    }

    private function fetchGuestTransactions(array $guestEmails, int $startDate, int $endDate): array {
        $transactions = [];
        $params = [
            'limit' => 100,
            'expand' => ['data.billing_details'],
            'created' => ['gte' => $startDate, 'lt' => $endDate]
        ];
        
        try {
            $charges = \Stripe\Charge::all($params);
            $guestEmailLookup = array_flip($guestEmails);
            
            foreach ($charges->autoPagingIterator() as $charge) {
                $chargeEmail = strtolower(trim($charge->billing_details->email ?? ''));
                
                if (isset($guestEmailLookup[$chargeEmail]) && $this->isValidCharge($charge, null)) {
                    $this->log("Fetching guest transaction for: $chargeEmail");
                    $transactions[] = $this->formatCharge($charge, $chargeEmail);
                }
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->handleStripeError($e);
        }
        
        return $transactions;
    }

    private function saveToCsv(array $transactions, string $filename = 'transactions.csv'): void {
        if (empty($transactions)) {
            $this->log("No transactions to save.");
            return;
        }
        
        $csvContent = self::CSV_HEADER;
        foreach ($transactions as $transaction) {
            $csvContent .= implode(',', array_map(
                fn($field) => '"' . str_replace('"', '""', $field) . '"',
                $transaction
            )) . "\n";
        }
        
        file_put_contents($filename, $csvContent, LOCK_EX);
        $this->log("Transaction data saved to $filename");
    }

    public function refundTransaction(string $transactionId, ?float $amount = null): array {
        try {
            $body = [
                'reason' => 'requested_by_customer'
            ];
            
            if (str_starts_with($transactionId, 'ch_')) {
                $body['charge'] = $transactionId;
            } elseif (str_starts_with($transactionId, 'pi_')) {
                $body['payment_intent'] = $transactionId;
            } else {
                throw new \InvalidArgumentException("Invalid transaction ID format");
            }
            
            if ($amount !== null) {
                $body['amount'] = (int)($amount * 100);
            }
            
            $refund = \Stripe\Refund::create($body);
            
            return [
                'refund_id' => $refund->id,
                'status' => $refund->status,
                'amount_refunded' => $refund->amount / 100,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new \RuntimeException("Refund failed: " . $e->getMessage());
        }
    }

    public function createProducts(int $count = 3): array {
        if ($count < 1 || $count > count(self::PRODUCT_NAMES)) {
            throw new \InvalidArgumentException("Invalid product count");
        }
        
        $products = [];
        $productNames = self::PRODUCT_NAMES;
        shuffle($productNames);
        
        for ($i = 0; $i < $count; $i++) {
            $products[] = \Stripe\Product::create([
                'name' => $productNames[$i]
            ]);
        }
        
        return $products;
    }

    public function createPricesAndSaveToCSV(array $prices, string $currency = 'USD'): void {
        if (empty($prices)) {
            throw new \InvalidArgumentException("Prices array cannot be empty");
        }
        
        try {
            $products = $this->createProducts(min(3, count($prices)));
            
            $csvFile = 'products_prices.csv';
            $miniCsvFile = 'miniProducts.csv';
            
            $mainCsv = fopen($csvFile, 'a');
            $miniCsv = fopen($miniCsvFile, 'a');
            
            if (!filesize($csvFile)) {
                fputcsv($mainCsv, [
                    "Price ID", "Product ID", "Product Name", "Product Statement Descriptor",
                    "Product Tax Code", "Description", "Created (UTC)", "Amount", "Currency",
                    "Interval", "Interval Count", "Usage Type", "Aggregate Usage", 
                    "Billing Scheme", "Trial Period Days", "Tax Behavior"
                ]);
            }
            
            foreach ($prices as $unitAmount) {
                $product = $this->selectProductForPrice($products, $unitAmount);
                $price = $this->createPrice($product->id, $unitAmount, $currency);
                
                $this->writePriceToCsv($mainCsv, $price, $product);
                fputcsv($miniCsv, [$price->id, $price->unit_amount / 100]);
            }
            
            fclose($mainCsv);
            fclose($miniCsv);
            
            $this->log("Created " . count($prices) . " prices. Data saved to $csvFile");
        } catch (\Exception $e) {
            throw new \RuntimeException("Price creation failed: " . $e->getMessage());
        }
    }
    
    private function selectProductForPrice(array $products, float $unitAmount): \Stripe\Product {
        if (count($products) === 1) {
            return $products[0];
        }
        
        $startPrice = rand(50, 60);
        $endPrice = rand(90, 110);
        
        if ($unitAmount <= $startPrice) {
            return $products[0];
        } elseif ($unitAmount <= $endPrice) {
            return $products[1];
        }
        
        return $products[2];
    }
    
    private function createPrice(string $productId, float $unitAmount, string $currency): \Stripe\Price {
        return \Stripe\Price::create([
            'product' => $productId,
            'currency' => $currency,
            'unit_amount' => (int)($unitAmount * 100)
        ]);
    }
    
    private function writePriceToCsv($handle, \Stripe\Price $price, \Stripe\Product $product): void {
        fputcsv($handle, [
            $price->id,
            $product->id,
            $product->name,
            $product->statement_descriptor ?? '',
            $product->tax_code ?? '',
            $product->description ?? '',
            date('Y-m-d H:i:s', $product->created),
            $price->unit_amount / 100,
            strtoupper($price->currency),
            $price->recurring->interval ?? '',
            $price->recurring->interval_count ?? '',
            $price->recurring->usage_type ?? '',
            $price->recurring->aggregate_usage ?? '',
            $price->billing_scheme,
            $price->recurring->trial_period_days ?? '',
            $price->tax_behavior ?? ''
        ]);
    }

    private function handleStripeError(\Stripe\Exception\ApiErrorException $e): void {
        $this->log("Stripe API Error: " . $e->getMessage());
        // You might want to add more sophisticated error handling here
    }

    private function log(string $message): void {
        ($this->logger)($message);
    }
}