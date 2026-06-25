<?php
$request = new WP_REST_Request("GET", "/authorizenter/v1/providers");
$response = rest_do_request($request);

if ($response->is_error()) {
    echo "REST API returned an error.\n";
    exit(1);
}

if ($response->get_status() !== 200) {
    echo "REST API returned non-200 status: " . $response->get_status() . "\n";
    exit(1);
}

echo "REST API test passed!\n";
