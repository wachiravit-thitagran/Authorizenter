<?php
/**
 * Apply PHP 8.4+ explicit-nullable compatibility fixes to the locked OIDC library.
 *
 * The bundled jumbojett/openid-connect-php v1.0.2 uses implicit nullable
 * parameters that emit deprecations and can break OAuth redirect headers.
 * This script is intentionally strict and idempotent: fail packaging if the
 * upstream signatures change rather than silently releasing an unpatched ZIP.
 */
declare(strict_types=1);

$path = dirname(__DIR__) . '/vendor/jumbojett/openid-connect-php/src/OpenIDConnectClient.php';
if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Missing OIDC vendor source: $path\n");
    exit(1);
}

$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Cannot read OIDC vendor source\n");
    exit(1);
}

// Expected occurrences across the constructor and six affected methods.
$parameters = [
    'provider_url' => 1,
    'client_id' => 1,
    'client_secret' => 1,
    'issuer' => 1,
    'accessToken' => 1,
    'attribute' => 2,
    'post_body' => 1,
    'clientId' => 2,
    'clientSecret' => 2,
];

$updated = 0;
foreach ($parameters as $parameter => $expected) {
    $old = 'string $' . $parameter . ' = null';
    $new = '?' . $old;
    $pattern = '/(?<![?a-zA-Z0-9_])' . preg_quote($old, '/') . '/';
    $oldCount = preg_match_all($pattern, $source);
    $newCount = substr_count($source, $new);

    if ($oldCount === false || ($oldCount + $newCount) !== $expected) {
        fwrite(STDERR, sprintf(
            "Unexpected OIDC signature for %s (legacy=%d, explicit=%d, expected=%d)\n",
            $parameter,
            $oldCount,
            $newCount,
            $expected
        ));
        exit(1);
    }
    if ($oldCount > 0) {
        $source = preg_replace_callback($pattern, static fn () => $new, $source);
        if (!is_string($source)) {
            fwrite(STDERR, "Failed to patch OIDC signatures\n");
            exit(1);
        }
        $updated += $oldCount;
    }
}

if ($updated > 0) {
    if (file_put_contents($path, $source, LOCK_EX) === false) {
        fwrite(STDERR, "Failed to write OIDC vendor compatibility patch\n");
        exit(1);
    }
}
echo "OIDC nullable compatibility verified ($updated signatures patched).\n";
