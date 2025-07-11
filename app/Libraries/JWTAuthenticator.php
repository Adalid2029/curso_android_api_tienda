<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Result;

/**
 * JWT Authenticator Library
 * Maneja todas las funcionalidades relacionadas con JWT tokens
 */
class JWTAuthenticator
{
    protected $userModel;
    protected $config;

    public function __construct()
    {
        $this->userModel = model(UserModel::class);
        $this->config = config('Auth');
    }

    /**
     * Extrae el token del header Authorization
     * 
     * @return string|null
     */
    public function extractTokenFromHeader(): ?string
    {
        $request = service('request');
        $header = $request->getHeaderLine('Authorization');

        if (!$header) {
            return null;
        }

        // Verificar formato Bearer token
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }

    /**
     * Valida un token JWT
     * 
     * @param string|null $token
     * @return Result
     */
    public function validateToken(?string $token): Result
    {
        if (!$token) {
            return new Result([
                'success' => false,
                'reason'  => 'Token requerido',
            ]);
        }

        try {
            $key = (string) $this->config->jwtKeys['default']['key'];
            $algorithm = $this->config->jwtKeys['default']['alg'];

            $decoded = JWT::decode($token, new Key($key, $algorithm));

            $user = $this->userModel->find($decoded->user_id);

            if (!$user || !$user->isActivated()) {
                return new Result([
                    'success' => false,
                    'reason'  => 'Usuario no válido',
                ]);
            }

            return new Result([
                'success' => true,
                'extraInfo' => $user  // Pasar el user directamente como extraInfo
            ]);
        } catch (\Exception $e) {
            return new Result([
                'success' => false,
                'reason'  => 'Token inválido: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Valida token desde el header automáticamente
     * 
     * @return Result
     */
    public function validateFromHeader(): Result
    {
        $token = $this->extractTokenFromHeader();
        return $this->validateToken($token);
    }

    /**
     * Genera un token JWT para un usuario
     * 
     * @param User $user
     * @param int $expiresIn Tiempo de expiración en segundos (default: 24 horas)
     * @return string
     */
    public function generateJWTToken(User $user, int $expiresIn = 86400): string
    {
        $key = (string) $this->config->jwtKeys['default']['key'];
        $algorithm = $this->config->jwtKeys['default']['alg'];

        $issuedAt = time();
        $expiresAt = $issuedAt + $expiresIn;

        $payload = [
            'iss' => base_url(),
            'aud' => base_url(),
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'user_id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'groups' => $user->getGroups(),
        ];

        return JWT::encode($payload, $key, $algorithm);
    }

    /**
     * Genera un access token similar al de Shield pero con JWT
     * Compatible con el método que usabas antes: generateAccessToken()
     * 
     * @param User $user
     * @param string $deviceName
     * @param int $expiresIn
     * @return object
     */
    public function generateAccessToken(User $user, string $deviceName, int $expiresIn = 86400): object
    {
        $token = $this->generateJWTToken($user, $expiresIn);

        return (object)[
            'raw_token' => $token,
            'device_name' => $deviceName,
            'expires_at' => date('Y-m-d H:i:s', time() + $expiresIn),
            'user_id' => $user->id,
            'token_type' => 'Bearer'
        ];
    }

    /**
     * Obtiene el usuario autenticado desde el token
     * 
     * @param string|null $token Si no se proporciona, lo extrae del header
     * @return User|null
     */
    public function getAuthenticatedUser(?string $token = null): ?User
    {
        if (!$token) {
            $token = $this->extractTokenFromHeader();
        }

        $result = $this->validateToken($token);

        if (!$result->isOK()) {
            return null;
        }

        return $result->extraInfo(); // extraInfo ahora contiene directamente el User
    }

    /**
     * Verifica si un token está expirado
     * 
     * @param string $token
     * @return bool
     */
    public function isTokenExpired(string $token): bool
    {
        try {
            $key = (string) $this->config->jwtKeys['default']['key'];
            $algorithm = $this->config->jwtKeys['default']['alg'];

            $decoded = JWT::decode($token, new Key($key, $algorithm));

            return $decoded->exp < time();
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Obtiene el payload completo del token
     * 
     * @param string $token
     * @return array|null
     */
    public function getTokenPayload(string $token): ?array
    {
        try {
            $key = (string) $this->config->jwtKeys['default']['key'];
            $algorithm = $this->config->jwtKeys['default']['alg'];

            $decoded = JWT::decode($token, new Key($key, $algorithm));

            return (array) $decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtiene información del token
     * 
     * @param string $token
     * @return array|null
     */
    public function getTokenInfo(string $token): ?array
    {
        $payload = $this->getTokenPayload($token);

        if (!$payload) {
            return null;
        }

        return [
            'user_id' => $payload['user_id'] ?? null,
            'username' => $payload['username'] ?? null,
            'email' => $payload['email'] ?? null,
            'groups' => $payload['groups'] ?? [],
            'issued_at' => $payload['iat'] ?? null,
            'expires_at' => $payload['exp'] ?? null,
            'is_expired' => ($payload['exp'] ?? 0) < time(),
            'time_to_expire' => max(0, ($payload['exp'] ?? 0) - time())
        ];
    }

    /**
     * Refresca un token si está próximo a expirar
     * 
     * @param string $token
     * @param int $refreshThreshold Segundos antes de expirar para refrescar (default: 1 hora)
     * @return string|null Nuevo token o null si no es necesario/inválido
     */
    public function refreshToken(string $token, int $refreshThreshold = 3600): ?string
    {
        $payload = $this->getTokenPayload($token);

        if (!$payload) {
            return null;
        }

        $timeToExpire = $payload['exp'] - time();

        // Solo refrescar si el token expira dentro del threshold
        if ($timeToExpire > $refreshThreshold) {
            return null;
        }

        $user = $this->userModel->find($payload['user_id']);

        if (!$user || !$user->isActivated()) {
            return null;
        }

        return $this->generateJWTToken($user);
    }

    /**
     * Verifica si el usuario tiene un permiso específico
     * 
     * @param string $permission
     * @param string|null $token
     * @return bool
     */
    public function hasPermission(string $permission, ?string $token = null): bool
    {
        $user = $this->getAuthenticatedUser($token);

        if (!$user) {
            return false;
        }

        return $user->can($permission);
    }

    /**
     * Verifica si el usuario pertenece a un grupo específico
     * 
     * @param string $group
     * @param string|null $token
     * @return bool
     */
    public function inGroup(string $group, ?string $token = null): bool
    {
        $user = $this->getAuthenticatedUser($token);

        if (!$user) {
            return false;
        }

        return $user->inGroup($group);
    }

    /**
     * Middleware para proteger rutas
     * Retorna el usuario si está autenticado, null si no
     * 
     * @return User|null
     */
    public function requireAuth(): ?User
    {
        return $this->getAuthenticatedUser();
    }

    /**
     * Intenta autenticar con credenciales (compatible con Shield)
     * 
     * @param array $credentials
     * @return Result
     */
    public function attempt(array $credentials): Result
    {
        $user = $this->userModel->findByCredentials($credentials);

        if (!$user || !$user->checkPassword($credentials['password'])) {
            return new Result([
                'success' => false,
                'reason'  => 'Credenciales inválidas',
            ]);
        }

        if (!$user->isActivated()) {
            return new Result([
                'success' => false,
                'reason'  => 'Usuario no activado',
            ]);
        }

        return new Result([
            'success' => true,
            'user'    => $user,
        ]);
    }

    /**
     * Logout (para JWT es principalmente del lado del cliente)
     * Aquí podrías implementar blacklist si fuera necesario
     * 
     * @return void
     */
    public function logout(): void
    {
        // Para JWT, el logout se maneja principalmente en el cliente
        // eliminando el token del storage.
        // Aquí podrías implementar una blacklist de tokens si fuera necesario.
    }

    /**
     * Valida configuración JWT
     * 
     * @return bool
     */
    public function validateConfig(): bool
    {
        return isset($this->config->jwtKeys['default']['key']) &&
            isset($this->config->jwtKeys['default']['alg']) &&
            !empty($this->config->jwtKeys['default']['key']);
    }
}
