<?php
$shortcodes = array(
    "[authorizenter_login]",
    "[authorizenter_button]",
    "[authorizenter_url]"
);
foreach ($shortcodes as $shortcode) {
    $output = do_shortcode($shortcode);
    if ($output === $shortcode) {
        echo "Failed to render $shortcode\n";
        exit(1);
    }
}
echo "Shortcode rendering tests passed!\n";
