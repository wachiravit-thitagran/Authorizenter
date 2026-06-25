<?php
$option_key = \Authorizenter\Core\Settings::OPTION;
$test_data = array("client_id" => "test_client_id_123");
update_option($option_key, $test_data);
$saved = get_option($option_key);
if (empty($saved["client_id"]) || $saved["client_id"] !== "test_client_id_123") {
    echo "Failed to save settings.\n";
    exit(1);
}
echo "Settings API test passed!\n";
