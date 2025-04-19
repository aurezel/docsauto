<?php

require 'config.php';
require 'stripeQueryService.php';

// php stripCliOption.php --arn --arn_days=7
// php stripCliOption.php --refund=ch_1ABC123 --amount=10
// php stripCliOption.php --transaction_id=ch_1ABC123 --email=user@example.com --last4=4242 --amount_min=10 --amount_max=100 --time_type=1
// php stripCliOption.php --batch=queries.txt


// CLI 参数解析
$options = getopt("", [
    "transaction_id:",
    "email:",
    "last4:",
    "amount:",
    "amount_min:",
    "amount_max:",
    "time_type::",
    "refund::",
	"batch",
    "arn::",      // 支持查询 ARN
    "arn_days::",  // 支持查询 ARN，天数可自定义
    "logfile::"
]);

$stripe = new StripeService(STRIPE_SK);  // 请替换为你的 API Key

// 记录日志的函数
function log_query($message, $logfile = 'stripe_cli.log') {
    $entry = '[' . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents($logfile, $entry, FILE_APPEND);
}

try {
    // ✅ 退款处理
    if (!empty($options['refund'])) {
        $amount = $options['amount'] ?? null;
        $status = $stripe->refundTransaction($options['refund'], $amount);
        echo "Refund Status: $status\n";
        log_query("Refunded {$options['refund']} with amount: {$amount}");
        exit;
    }

    // ✅ 查询 ARN 交易
    if (isset($options['arn'])) {
        $arnResults = $stripe->getChargesWithArn($options['arn_days'] ?? 7);
        foreach ($arnResults as $row) {
            echo implode(" | ", $row) . "\n";
        }
        log_query("ARN query executed");
        exit;
    }

    // ✅ 构造查询参数
    $query = [];
    if (!empty($options['transaction_id'])) $query['transaction_id'] = $options['transaction_id'];
    if (!empty($options['email'])) $query['email'] = $options['email'];
    if (!empty($options['last4'])) $query['last4'] = $options['last4'];

    if (!empty($options['amount'])) {
        $query['amount'] = (int)($options['amount'] * 100);
    } elseif (!empty($options['amount_min']) && !empty($options['amount_max'])) {
        $query['amount'] = [
            (int)($options['amount_min'] * 100),
            (int)($options['amount_max'] * 100)
        ];
    }

    if (isset($options['time_type'])) {
        $query['time_type'] = (int)$options['time_type'];
    }

	if (!empty($options['batch'])) {
        // 读取文件并批量处理查询
        $file = $options['batch'];
        if (!file_exists($file)) {
            throw new Exception("Batch query file not found: $file");
        }

        $queryList = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            // 每行解析 email, last4, datetime
            list($email, $last4, $datetime) = explode(',', $line);
            $datetime = strtotime($datetime);
            $start = $datetime - 12 * 3600;  // 前12小时
            $end = $datetime + 12 * 3600;    // 后12小时
            $queryList[] = [
                'email' => $email,
                'last4' => $last4,
                'time_range' => [$start, $end]
            ];
        }

        // 执行批量查询
        $allResults = $stripe->batchSearch($queryList);
        foreach ($allResults as $result) {
            echo "=== Query: " . json_encode($result['query']) . " ===\n";
            foreach ($result['result'] as $row) {
                echo implode(" | ", $row) . "\n";
            }
        }

        log_query("Batch query executed from $file");
        exit;
    }
	if(empty($query)){
		log_query("query executed is empty");
        exit;
	}
    // ✅ 执行查询
    $results = $stripe->searchTransactions($query);
    foreach ($results as $row) {
        echo implode(" | ", $row) . "\n";
    }

    log_query("Single query executed: " . json_encode($query));

} catch (Exception $e) {
    log_query("ERROR: " . $e->getMessage());
    echo "[ERROR] " . $e->getMessage() . "\n";
}
