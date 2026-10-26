<?php
/**
 * Runtime smoke test: OIDC vendor and wrapper must not emit PHP 8.4+ deprecations.
 *
 * Run under PHP 8.5 after composer install:
 * php -d error_reporting=-1 -d display_errors=1 tests/php85-oidc-compat.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(
    static function (int $severity, string $message): bool {
        if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
            throw new RuntimeException("Unexpected PHP deprecation: $message");
        }
        return false;
    },
    E_DEPRECATED | E_USER_DEPRECATED
);

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = [
    '__construct' => ['provider_url', 'client_id', 'client_secret', 'issuer'],
    'verifyJWTClaims' => ['accessToken'],
    'requestUserInfo' => ['attribute'],
    'getVerifiedClaims' => ['attribute'],
    'fetchURL' => ['post_body'],
    'introspectToken' => ['clientId', 'clientSecret'],
    'revokeToken' => ['clientId', 'clientSecret'],
];

$class = new ReflectionClass(\Jumbojett\OpenIDConnectClient::class);
foreach ($checks as $method => $names) {
    $params = [];
    foreach ($class->getMethod($method)->getParameters() as $parameter) {
        $params[$parameter->getName()] = $parameter;
    }
    foreach ($names as $name) {
        if (!isset($params[$name])) {
            throw new RuntimeException("Missing OIDC parameter $method::$name");
        }
        $type = $params[$name]->getType();
        if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string'
            || !$type->allowsNull() || !$params[$name]->isDefaultValueAvailable()
            || $params[$name]->getDefaultValue() !== null) {
            throw new RuntimeException("Incorrect nullable parameter $method::$name");
        }
    }
}

$wrapper = file_get_contents(dirname(__DIR__) . '/plugins/authorizenter-core/includes/class-oidc-client.php');
if ($wrapper === false || !str_contains($wrapper, 'protected function fetchURL( string $url, ?string $post_body = null')) {
    throw new RuntimeException('Authorizenter OIDC HTTP transport override is not explicitly nullable');
}

restore_error_handler();
echo "PHP OIDC nullable smoke test passed; no deprecations on library load.\n";
