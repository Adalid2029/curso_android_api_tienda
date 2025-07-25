<?php

namespace App\Services;

use DateTimeImmutable;
use Exception;

class TOTPService
{
    private $secretKey;
    private $validityPeriod;
    private $digits;
    private $timeWindow = 1;
    private $algorithm = 'sha256';

    public function __construct(string $secretKey, int $validityPeriod = 60, int $digits = 6)
    {
        $this->secretKey = $secretKey;
        $this->validityPeriod = $validityPeriod;
        $this->digits = $digits;
    }

    /**
     * Genera un TOTP único basado en tiempo y usuario
     */
    public function generateTOTP(int $timestamp = null, int $userIdentifier): string
    {
        $timestamp = $timestamp ?? time();
        $timeSlice = floor($timestamp / $this->validityPeriod);

        // Combinar time slice con user identifier para unicidad
        $hashInput = pack('N*', 0) . pack('N*', $timeSlice) . pack('N*', $userIdentifier);

        $hash = hash_hmac($this->algorithm, $hashInput, $this->secretKey, true);

        $offset = ord($hash[31]) & 0xf; // SHA256 produce 32 bytes, usar el último

        $otp = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % pow(10, $this->digits);

        return str_pad($otp, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica un código TOTP
     */
    public function verifyTOTP(string $userProvidedCode, int $userIdentifier): bool
    {
        $currentTimestamp = time();

        for ($i = -$this->timeWindow; $i <= $this->timeWindow; $i++) {
            $checkTime = $currentTimestamp + ($i * $this->validityPeriod);
            $currentCode = $this->generateTOTP($checkTime, $userIdentifier);

            log_message('debug', "Checking TOTP for time: " . date('Y-m-d H:i:s', $checkTime));
            log_message('debug', "Generated TOTP: $currentCode, User provided: $userProvidedCode");

            if (hash_equals($currentCode, $userProvidedCode)) {
                log_message('info', "TOTP verified successfully for user: $userIdentifier");
                return true;
            }
        }

        log_message('error', "TOTP verification failed for user: $userIdentifier");
        return false;
    }

    /**
     * Genera un token de sesión único con TOTP integrado
     */
    public function generateSessionToken(int $userId, string $action, int $expirationMinutes = 15): array
    {
        $nonce = bin2hex(random_bytes(16)); // Nonce único para cada request
        $timestamp = time();
        $expiry = $timestamp + ($expirationMinutes * 60);

        // Generar TOTP basado en acción específica
        $actionHash = hash('sha256', $action . $userId . $nonce);
        $totp = $this->generateTOTP($timestamp, $userId);

        $payload = [
            'user_id' => $userId,
            'action' => $action,
            'nonce' => $nonce,
            'timestamp' => $timestamp,
            'expiry' => $expiry,
            'totp_hash' => hash('sha256', $totp . $actionHash)
        ];

        // Crear firma HMAC del payload
        $signature = hash_hmac('sha256', json_encode($payload), $this->secretKey);

        return [
            'token' => base64_encode(json_encode($payload)),
            'signature' => $signature,
            'totp' => $totp // Solo para desarrollo, en producción no devolver
        ];
    }

    /**
     * Verifica un token de sesión
     */
    public function verifySessionToken(string $token, string $signature, string $action): array
    {
        try {
            // Decodificar payload
            $payloadJson = base64_decode($token);
            $payload = json_decode($payloadJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Token inválido: JSON malformado');
            }

            // Verificar firma HMAC
            $expectedSignature = hash_hmac('sha256', $payloadJson, $this->secretKey);
            if (!hash_equals($expectedSignature, $signature)) {
                throw new Exception('Token inválido: Firma incorrecta');
            }

            // Verificar expiración
            if (time() > $payload['expiry']) {
                throw new Exception('Token expirado');
            }

            // Verificar acción
            if ($payload['action'] !== $action) {
                throw new Exception('Token inválido: Acción incorrecta');
            }

            // Verificar que el nonce no haya sido usado (implementar cache Redis/Memcached)
            if ($this->isNonceUsed($payload['nonce'])) {
                throw new Exception('Token ya utilizado');
            }

            return [
                'valid' => true,
                'payload' => $payload
            ];
        } catch (Exception $e) {
            log_message('error', 'Token verification failed: ' . $e->getMessage());
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica un código TOTP contra el token de sesión
     */
    public function verifyTOTPWithToken(string $userProvidedCode, array $tokenPayload): bool
    {
        $userId = $tokenPayload['user_id'];
        $action = $tokenPayload['action'];
        $nonce = $tokenPayload['nonce'];

        // Generar TOTP actual
        $currentTOTP = $this->generateTOTP(null, $userId);

        // Verificar TOTP
        if (!$this->verifyTOTP($userProvidedCode, $userId)) {
            return false;
        }

        // Verificar hash TOTP del token
        $actionHash = hash('sha256', $action . $userId . $nonce);
        $expectedTOTPHash = hash('sha256', $currentTOTP . $actionHash);

        if (!hash_equals($expectedTOTPHash, $tokenPayload['totp_hash'])) {
            log_message('error', 'TOTP hash mismatch');
            return false;
        }

        // Marcar nonce como usado
        $this->markNonceAsUsed($tokenPayload['nonce']);

        return true;
    }

    /**
     * Genera un payload seguro para transferencia
     */
    public function generateSecurePayload(array $data, int $expirationMinutes = 15): array
    {
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();
        $expiry = $timestamp + ($expirationMinutes * 60);

        $payload = array_merge($data, [
            'nonce' => $nonce,
            'timestamp' => $timestamp,
            'expiry' => $expiry
        ]);

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $this->secretKey);

        return [
            'payload' => base64_encode($payloadJson),
            'signature' => $signature
        ];
    }

    /**
     * Verifica un payload seguro
     */
    public function verifySecurePayload(string $payload, string $signature): array
    {
        try {
            $payloadJson = base64_decode($payload);
            $data = json_decode($payloadJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Payload inválido');
            }

            // Verificar firma
            $expectedSignature = hash_hmac('sha256', $payloadJson, $this->secretKey);
            if (!hash_equals($expectedSignature, $signature)) {
                throw new Exception('Firma inválida');
            }

            // Verificar expiración
            if (time() > $data['expiry']) {
                throw new Exception('Payload expirado');
            }

            // Verificar nonce único
            if ($this->isNonceUsed($data['nonce'])) {
                throw new Exception('Payload ya utilizado');
            }

            return [
                'valid' => true,
                'data' => $data
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica si un nonce ya fue usado (implementar con Redis/Cache)
     */
    private function isNonceUsed(string $nonce): bool
    {
        // Implementar con Redis o Cache de CodeIgniter
        $cache = \Config\Services::cache();
        return $cache->get("nonce_$nonce") !== null;
    }

    /**
     * Marca un nonce como usado
     */
    private function markNonceAsUsed(string $nonce): void
    {
        // Implementar con Redis o Cache de CodeIgniter
        $cache = \Config\Services::cache();
        $cache->save("nonce_$nonce", true, 3600); // Guardar por 1 hora
    }

    /**
     * Limpia nonces expirados (ejecutar en cron job)
     */
    public function cleanupExpiredNonces(): void
    {
        // Implementar limpieza de nonces expirados
        log_message('info', 'Cleanup expired nonces executed');
    }
}
