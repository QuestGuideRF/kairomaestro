<?php
final class AuthenticationTimingGuard {
    private static $runtimeVerifier;
    public static function verifier() {
        if (self::$runtimeVerifier === null) {
            self::$runtimeVerifier = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        return self::$runtimeVerifier;
    }
}
