<?php
if ($argc < 3) {
    echo "Usage: php auto.php <base_name> <payment_name>\n";
    echo "Example: php auto.php opalifypay tffy\n";
    exit(1);
}
$envFile = '../public_html/checkout/.env';
$htaccessFile = '../public_html/.htaccess';
$baseName = $argv[1];  // 第一个参数，如 "opalifypay"
$suffix = $argv[2];  // 第二个参数，如 "tffy"

// 定义要查找和替换的配置行
$searchLines = [
    'checkout_success_path = "/opalifypay/successs"',
    'checkout_notify_path = "/opalifypay/notifys"',
    'checkout_cancel_path = "/opalifypay/cancels"'
];

// 定义新的配置行
$replaceLines = [
    "checkout_success_path = \"/{$baseName}pay/success{$suffix}\"",
    "checkout_notify_path = \"/{$baseName}pay/notify{$suffix}\"",
    "checkout_cancel_path = \"/{$baseName}pay/cancel{$suffix}\""
];
// 检查.env文件是否存在
if (!file_exists($envFile)) {
    echo "Error: .env file not found in current directory.\n";
    exit(1);
}

// 读取.env文件内容
$envContent = file_get_contents($envFile);

// 执行替换
$newEnvContent = str_replace($searchLines, $replaceLines, $envContent);

// 写入.env文件
if (file_put_contents($envFile, $newEnvContent) !== false) {
    echo "Successfully updated .env file with new paths:\n";
    echo implode("\n", $replaceLines) . "\n";
} else {
    echo "Error: Failed to write to .env file.\n";
    exit(1);
}

// ==================== 更新 .htaccess 文件 ====================

if (!file_exists($htaccessFile)) {
    echo "Error: .htaccess file not found.\n";
    exit(1);
}

$htaccessContent = file_get_contents($htaccessFile);

// 检查是否已存在这些规则
$newRules = <<<EOT
RewriteRule ^{$baseName}pay/pay{$suffix}\$ checkout/checkout.php [QSA,PT,L]
RewriteRule ^{$baseName}pay/notify{$suffix}\$ /checkout/pay/stckWebhook [QSA,PT,L]
RewriteRule ^{$baseName}pay/success{$suffix}\$ /checkout/pay/stckSuccess [QSA,PT,L]
RewriteRule ^{$baseName}pay/cancel{$suffix}\$ /checkout/pay/stckCancel [QSA,PT,L]
RewriteRule ^{$baseName}pay/(.*)\$ checkout/\$1 [QSA,PT,L]
EOT;

// 如果规则已存在则不重复添加
if (strpos($htaccessContent, $newRules) !== false) {
    echo "Rewrite rules already exist in .htaccess.\n";
    exit(0);
}

// 在 RewriteBase / 和 RewriteRule ^index\.php$ - [L] 之间插入新规则
$marker = "RewriteBase /\n";
$insertPosition = strpos($htaccessContent, $marker);

if ($insertPosition === false) {
    echo "Error: Could not find 'RewriteBase /' in .htaccess.\n";
    exit(1);
}

$insertPosition += strlen($marker);

$newHtaccessContent = substr_replace(
    $htaccessContent,
    "\n" . $newRules . "\n",
    $insertPosition,
    0
);

if (file_put_contents($htaccessFile, $newHtaccessContent) === false) {
    echo "Error: Failed to update .htaccess file.\n";
    exit(1);
}

echo "Added rewrite rules to .htaccess successfully.\n";
echo "\n\n	/{$baseName}pay/pay{$suffix}\n";
echo "	/{$baseName}pay/notify{$suffix}\n\n"
#echo "New rules added:\n" . $newRules . "\n";
?>