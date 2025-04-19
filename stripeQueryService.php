<?php

require 'vendor/autoload.php';

use Stripe\Stripe;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\Payout;
use Stripe\PaymentIntent;
use Stripe\Refund;

class StripeService {
    public function __construct($apiKey) {
        Stripe::setApiKey($apiKey);
    }

    /**
     * 查询单个交易，支持类型 ch_, pi_, py_
     */
    public function findByTransactionId(string $id): array {
        try {
            if (str_starts_with($id, 'ch_')) return $this->formatCharge(Charge::retrieve($id));
            if (str_starts_with($id, 'pi_')) return [PaymentIntent::retrieve($id)];
            if (str_starts_with($id, 'py_')) return [Payout::retrieve($id)];
            return ['error' => 'Unknown transaction type'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 综合查询：支持邮箱、卡后四位、金额范围、时间范围
     */
    public function searchTransactions(array $params): array {
        $results = [];

        if (!empty($params['transaction_id'])) {
            $results[] = $this->findByTransactionId($params['transaction_id']);
            return $results;
        }

        [$startDate, $endDate] = $this->getDateRangeByType($params['time_type'] ?? 0);

        foreach (Charge::all(['created' => ['gte' => $startDate, 'lte' => $endDate]])->autoPagingIterator() as $charge) {
            if (!empty($params['email'])) {
                $email = strtolower($params['email']);
                $billingEmail = strtolower($charge->billing_details->email ?? '');
                $customerEmail = strtolower($charge->customer_email ?? '');
                if ($email !== $billingEmail && $email !== $customerEmail) continue;
            }

            if (!empty($params['last4'])) {
                if (($charge->payment_method_details->card->last4 ?? '') !== $params['last4']) continue;
            }

            if (!empty($params['amount'])) {
                $amt = $params['amount'];
                if (is_array($amt)) {
                    if ($charge->amount < $amt[0] || $charge->amount > $amt[1]) continue;
                } elseif ($charge->amount !== $amt) {
                    continue;
                }
            }

            $results[] = $this->formatCharge($charge);
        }

        $this->saveToCsv($results);
        return $results;
    }

    /**
     * 批量查询
     */
    public function batchSearch(array $queryList): array {
        $allResults = [];
        foreach ($queryList as $params) {
            $allResults[] = [
                'query' => $params,
                'result' => $this->searchTransactions($params)
            ];
        }
        return $allResults;
    }

    /**
     * 退款功能
     */
    public function refundTransaction(string $transactionId, ?int $amount = null): string {
        try {
            $body = [];
            if (str_starts_with($transactionId, 'ch_')) {
                $body['charge'] = $transactionId;
            } elseif (str_starts_with($transactionId, 'pi_')) {
                $body['payment_intent'] = $transactionId;
            }

            if ($amount !== null) {
                $body['amount'] = $amount * 100;
            }
            $body['reason'] = 'requested_by_customer';
            $refund = Refund::create($body);
            return $refund->status;
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 格式化 Charge 数据
     */
    public function formatCharge($charge): array {
        return [
            $charge->billing_details->email ?? $charge->customer_email ?? '',
            $charge->id,
            $charge->amount / 100,
            strtoupper($charge->currency),
            $charge->status,
            $charge->payment_intent ?? '',
            ($charge->refunded || $charge->amount_refunded > 0) ? 'Refunded' : 'No Refund',
            ($charge->amount_refunded / 100) . ' ' . strtoupper($charge->currency),
            date('Y-m-d H:i:s', $charge->created),
        ];
    }

    /**
     * 保存到 CSV
     */
    private function saveToCsv(array $data) {
        $file = 'transaction.csv';
        file_put_contents($file, "");
        $fp = fopen($file, 'w');
        fputcsv($fp, ['email','transaction_id','amount','currency','status','paymentIntent','refundStatus','refundAmount','created_at']);
        foreach ($data as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        echo "Saved to {$file}\n";
    }

    /**
     * 返回查询时间范围
     */
    private function getDateRangeByType($type): array {
        $now = time();
        return match ($type) {
            1 => [strtotime('-120 days', $now), strtotime('-60 days', $now)],
            2 => [strtotime('-180 days', $now), strtotime('-120 days', $now)],
			3 => [strtotime('-30 days', $now), $now],
			4 => [strtotime('-15 days', $now), $now],
            default => [strtotime('-60 days', $now), $now],
        };
    }

    private function log($msg): void {
        echo "[LOG] $msg\n";
    }

	public function getChargesWithArn(int $daysAgo = 7): array {
        $now = time();
        $startDate = strtotime("-{$daysAgo} days", $now);

        $results = [];

        foreach (Charge::all(['created' => ['gte' => $startDate, 'lte' => $now]])->autoPagingIterator() as $charge) {
             $results[] = [
                    'transaction_id' => $charge->id,
					'arn' => $charge->transfer_data->destination_payment ?? 'N/A',
					'descriptor' => $charge->description ?? 'N/A',
					'card_brand' => $charge->payment_method_details->card->brand ?? 'N/A',
					'last4' => $charge->payment_method_details->card->last4 ?? 'N/A',
					'created_at' => date('Y-m-d H:i:s', $charge->created),
                ];
        }

        $this->saveArnReportCsv($results);
        return $results;
    }

    private function saveArnReportCsv(array $data): void {
        $file = 'arn_report.csv';
        file_put_contents($file, "");
        $fp = fopen($file, 'w');
        fputcsv($fp, ['transaction_id', 'arn', 'descriptor', 'card_brand', 'last4', 'created_at']);
        foreach ($data as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        echo "ARN report saved to {$file}\n";
    }
}
