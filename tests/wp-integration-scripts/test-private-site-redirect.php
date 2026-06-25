<?php
$option_key = \Authorizenter\Core\Settings::OPTION;
$test_data = array("private_site" => array("enabled" => true));
update_option($option_key, $test_data);

$private_site = new \Authorizenter\Core\Private_Site(new \Authorizenter\Core\Settings());
if (!$private_site->is_enabled()) {
    echo "Failed to enable private site.\n";
    exit(1);
}

// Simulate an unauthenticated request to a frontend page
$should_block = $private_site->should_block(false, false, false);
if (!$should_block) {
    echo "Failed to block unauthenticated request.\n";
    exit(1);
}

echo "Private Site test passed!\n";
