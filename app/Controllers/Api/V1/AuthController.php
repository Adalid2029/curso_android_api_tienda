<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\UserProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Entities\User;
use App\Services\TOTPService;

class AuthController extends BaseController
{
    protected $userModel;
    protected $userProfileModel;
    protected $totpService;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->userProfileModel = new UserProfileModel();
        $this->totpService = new TOTPService(
            getenv('TOTP_SECRET_KEY') ?: 'default-secret-key-change-in-production',
            60,
            6
        );
    }

    /**
     * POST /api/v1/auth/registro
     * Registrar nuevo usuario con Shield
     */
    public function registro()
    {
        $rules = [
            'username' => 'required|alpha_numeric|min_length[3]|max_length[30]|is_unique[users.username]',
            'email' => 'required|valid_email|is_unique[auth_identities.secret]',
            'password' => 'required|min_length[6]',
            'nombre_completo' => 'required|min_length[2]|max_length[100]',
            'telefono' => 'permit_empty|min_length[7]|max_length[20]'
        ];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
        }

        $data = $this->request->getJSON(true);

        // Crear usuario con Shield
        $user = new User([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        try {
            $this->userModel->save($user);
            $userId = $this->userModel->getInsertID();

            // Activar usuario automáticamente para API móvil
            $user = $this->userModel->findById($userId);
            $user->activate();

            // Agregar al grupo de clientes
            $user->addGroup('cliente');

            // Crear perfil del usuario
            $perfilData = [
                'user_id' => $userId,
                'nombre_completo' => $data['nombre_completo'],
                'telefono' => $data['telefono'] ?? null
            ];
            $this->userProfileModel->insert($perfilData);

            // Obtener datos completos del usuario
            $userData = $this->userProfileModel->getCompleteProfile($userId);

            // Generar token JWT
            $jwtAuth = new \App\Libraries\JWTAuthenticator();
            $token = $jwtAuth->generateJWTToken($user);

            return $this->respuestaExitosa([
                'token' => $token,
                'user' => [
                    'id' => $userData['user_id'],
                    'username' => $userData['username'],
                    'nombre_completo' => $userData['nombre_completo'],
                    'telefono' => $userData['telefono'],
                    'groups' => $user->getGroups()
                ]
            ], 'Usuario registrado exitosamente', 201);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al crear usuario: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/auth/login
     * Iniciar sesión con Shield
     */
    public function login()
    {
        $rules = [
            'login' => 'required',
            'password' => 'required'
        ];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
        }

        $credentials = $this->request->getJSON(true);

        // Intentar autenticación con Shield
        $auth = service('auth');

        $loginData = [
            'email' => $credentials['login'],
            'password' => $credentials['password']
        ];

        $result = $auth->attempt($loginData);

        if (!$result->isOK()) {
            return $this->respuestaError('Credenciales incorrectas', 401);
        }

        $user = $auth->user();

        if (!$user->isActivated()) {
            return $this->respuestaError('Usuario no activado', 403);
        }

        // Obtener perfil completo
        $perfil = $this->userProfileModel->getCompleteProfile($user->id);

        // Generar token JWT
        $jwtAuth = new \App\Libraries\JWTAuthenticator();
        $token = $jwtAuth->generateJWTToken($user);

        // Actualizar último acceso
        $user->last_active = date('Y-m-d H:i:s');
        $this->userModel->save($user);

        return $this->respuestaExitosa([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'nombre_completo' => $perfil['nombre_completo'] ?? '',
                'telefono' => $perfil['telefono'] ?? null,
                'groups' => $user->getGroups(),
                'permissions' => $user->getPermissions()
            ],
            'expires_at' => date('Y-m-d H:i:s', time() + (24 * 60 * 60))
        ], 'Inicio de sesión exitoso');
    }

    /**
     * GET /api/v1/auth/perfil
     * Obtener perfil del usuario autenticado
     */
    public function perfil()
    {
        $jwtAuth = new \App\Libraries\JWTAuthenticator();
        $user = $jwtAuth->requireAuth();

        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        $perfil = $this->userProfileModel->getCompleteProfile($user->id);

        return $this->respuestaExitosa([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'nombre_completo' => $perfil['nombre_completo'] ?? '',
            'telefono' => $perfil['telefono'] ?? null,
            'direccion' => $perfil['direccion'] ?? null,
            'avatar_url' => $perfil['avatar_url'] ?? null,
            'fecha_registro' => $perfil['fecha_registro'],
            'ultimo_acceso' => $user->last_active,
            'groups' => $user->getGroups(),
            'permissions' => $user->getPermissions()
        ]);
    }

    /**
     * PUT /api/v1/auth/perfil
     * Actualizar perfil del usuario
     */
    public function actualizarPerfil()
    {
        $jwtAuth = new \App\Libraries\JWTAuthenticator();
        $user = $jwtAuth->requireAuth();

        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        $data = $this->request->getJSON(true);

        // Datos que se pueden actualizar en el perfil
        $perfilData = [];
        $userData = [];

        // Actualizar datos en user_profiles
        if (isset($data['nombre_completo'])) {
            $perfilData['nombre_completo'] = $data['nombre_completo'];
        }
        if (isset($data['telefono'])) {
            $perfilData['telefono'] = $data['telefono'];
        }
        if (isset($data['direccion'])) {
            $perfilData['direccion'] = $data['direccion'];
        }

        // Actualizar email en users (requiere validación)
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $rules = ['email' => 'required|valid_email|is_unique[auth_identities.secret]'];
            if (!$this->validate($rules, ['email' => $data['email']])) {
                return $this->respuestaError('Email inválido o ya en uso', 400, $this->validator->getErrors());
            }
            $userData['email'] = $data['email'];
        }

        try {
            // Actualizar perfil
            if (!empty($perfilData)) {
                $this->userProfileModel->updateByUserId($user->id, $perfilData);
            }

            // Actualizar datos de usuario
            if (!empty($userData)) {
                $user->fill($userData);
                $this->userModel->save($user);
            }

            return $this->respuestaExitosa(null, 'Perfil actualizado exitosamente');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al actualizar perfil: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/auth/cambiar-password
     * Cambiar contraseña
     */
    public function cambiarContrasena()
    {
        $user = $this->request->user;

        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        $rules = [
            'current_password' => 'required',
            'new_password' => 'required|min_length[6]',
            'confirm_password' => 'required|matches[new_password]'
        ];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
        }

        $data = $this->request->getJSON(true);

        // var_dump($user, $user->email);

        $result = auth()->check([
            'email'    => $user->email,
            'password' => $data['current_password'],
        ]);
        if (!$result->isOK())
            return $this->respond(['type' => 'error', 'message' => lang('ModuloSeguridad.responseUserSetupNewPasswordErrorOldPassword')]);
        if ($data['current_password'] == $data['confirm_password'])
            return $this->respond(['type' => 'error', 'message' => lang('ModuloSeguridad.responseUserSetupNewPasswordErrorSamePassword')]);

        // Actualizar contraseña
        $user->password = $data['new_password'];

        if (!$this->userModel->save($user)) {
            return $this->respuestaError('Error al actualizar contraseña', 500);
        }

        return $this->respuestaExitosa(null, 'Contraseña actualizada exitosamente');
    }

    /**
     * POST /api/v1/auth/logout
     * Cerrar sesión (para JWT solo invalidamos en cliente)
     */
    public function logout()
    {
        // Con JWT, el logout se maneja principalmente en el cliente
        // eliminando el token del storage. Aquí podríamos implementar
        // una blacklist de tokens si fuera necesario.

        return $this->respuestaExitosa(null, 'Sesión cerrada exitosamente');
    }

    /**
     * POST /api/v1/auth/recuperar-paso1
     * Paso 1: Verificar usuario y generar token de sesión
     */
    public function recuperarPaso1()
    {
        try {
            // Validar entrada
            $rules = [
                'login' => 'required|min_length[3]|valid_email'
            ];

            if (!$this->validate($rules)) {
                return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
            }

            $data = $this->request->getJSON(true);
            $login = trim($data['login']);

            // Buscar usuario por email o username
            $user = $this->userModel->findByCredentials(['email' => $login]);

            if (!$user) {
                // Por seguridad, no revelar si el usuario existe o no
                return $this->respuestaError('Si el usuario existe, recibirás instrucciones', 404);
            }

            // Obtener perfil con teléfono
            $perfil = $this->userProfileModel->getCompleteProfile($user->id);

            if (empty($perfil['telefono'])) {
                return $this->respuestaError('Este usuario no tiene teléfono registrado', 400);
            }

            // Validar formato de teléfono
            if (!preg_match('/^[67]\d{7}$/', $perfil['telefono'])) {
                return $this->respuestaError('Número de teléfono inválido', 400);
            }

            // Generar token de sesión único para paso 2
            $sessionToken = $this->totpService->generateSessionToken(
                $user->id,
                'password_reset_step1',
                15 // 15 minutos de validez
            );

            // Crear payload seguro para el siguiente paso
            $payloadData = [
                'user_id' => $user->id,
                'telefono' => $perfil['telefono'],
                'step' => 1
            ];

            $securePayload = $this->totpService->generateSecurePayload($payloadData, 15);

            // Enmascarar teléfono para seguridad
            $telefonoEnmascarado = substr($perfil['telefono'], 0, 2) . '****' . substr($perfil['telefono'], -2);

            return $this->respuestaExitosa([
                'session_token' => $sessionToken['token'],
                'session_signature' => $sessionToken['signature'],
                'payload' => $securePayload['payload'],
                'payload_signature' => $securePayload['signature'],
                'telefono_enmascarado' => $telefonoEnmascarado,
                'user_id_hash' => hash('sha256', $user->id . getenv('APP_KEY')) // Hash del ID para verificación
            ], 'Usuario verificado. Confirma tu número de teléfono.');
        } catch (\Exception $e) {
            log_message('error', 'Error in recuperarPaso1: ' . $e->getMessage());
            return $this->respuestaError('Error interno del servidor', 500);
        }
    }

    /**
     * POST /api/v1/auth/recuperar-paso2
     * Paso 2: Verificar teléfono y enviar SMS con TOTP
     */
    public function recuperarPaso2()
    {
        try {
            $rules = [
                'session_token' => 'required',
                'session_signature' => 'required',
                'payload' => 'required',
                'payload_signature' => 'required',
                'telefono' => 'required|regex_match[/^[67]\d{7}$/]'
            ];

            if (!$this->validate($rules)) {
                return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
            }

            $data = $this->request->getJSON(true);

            // Verificar token de sesión del paso 1
            $tokenVerification = $this->totpService->verifySessionToken(
                $data['session_token'],
                $data['session_signature'],
                'password_reset_step1'
            );

            if (!$tokenVerification['valid']) {
                return $this->respuestaError('Token de sesión inválido: ' . $tokenVerification['error'], 401);
            }

            // Verificar payload seguro
            $payloadVerification = $this->totpService->verifySecurePayload(
                $data['payload'],
                $data['payload_signature']
            );

            if (!$payloadVerification['valid']) {
                return $this->respuestaError('Payload inválido: ' . $payloadVerification['error'], 401);
            }

            $payloadData = $payloadVerification['data'];
            $sessionData = $tokenVerification['payload'];

            // Verificar que el user_id coincida
            if ($payloadData['user_id'] !== $sessionData['user_id']) {
                return $this->respuestaError('Datos inconsistentes', 400);
            }

            // Verificar que el teléfono coincida
            if ($payloadData['telefono'] !== $data['telefono']) {
                return $this->respuestaError('El número de teléfono no coincide', 400);
            }

            $userId = $payloadData['user_id'];

            // Generar código TOTP para SMS
            $codigoSms = $this->totpService->generateTOTP(null, $userId);

            // Generar token para paso 3
            $step2Token = $this->totpService->generateSessionToken(
                $userId,
                'password_reset_step2',
                10 // 10 minutos para verificar SMS
            );

            // Crear payload para paso 3
            $step3PayloadData = [
                'user_id' => $userId,
                'telefono' => $data['telefono'],
                'step' => 2,
                'sms_sent_at' => time()
            ];

            $step3Payload = $this->totpService->generateSecurePayload($step3PayloadData, 10);

            // Enviar SMS
            try {
                $mensajeSms = "Tu código de verificación para Tienda Quirquincho es: {$codigoSms}";
                $resultadoSms = $this->enviarSMS($data['telefono'], $mensajeSms);

                if (!$resultadoSms['success']) {
                    return $this->respuestaError('Error al enviar SMS: ' . $resultadoSms['message'], 500);
                }

                return $this->respuestaExitosa([
                    'session_token' => $step2Token['token'],
                    'session_signature' => $step2Token['signature'],
                    'payload' => $step3Payload['payload'],
                    'payload_signature' => $step3Payload['signature'],
                    'telefono_enmascarado' => substr($data['telefono'], 0, 2) . '****' . substr($data['telefono'], -2)
                ], 'Código enviado por SMS');
            } catch (\Exception $e) {
                log_message('error', 'Error sending SMS: ' . $e->getMessage());
                return $this->respuestaError('Error al enviar SMS', 500);
            }
        } catch (\Exception $e) {
            log_message('error', 'Error in recuperarPaso2: ' . $e->getMessage());
            return $this->respuestaError('Error interno del servidor', 500);
        }
    }

    /**
     * POST /api/v1/auth/recuperar-paso3
     * Paso 3: Verificar código TOTP y cambiar contraseña
     */
    public function recuperarPaso3()
    {
        try {
            $rules = [
                'session_token' => 'required',
                'session_signature' => 'required',
                'payload' => 'required',
                'payload_signature' => 'required',
                'codigo_sms' => 'required|exact_length[6]|numeric',
                'nueva_password' => 'required|min_length[8]',
                'confirmar_password' => 'required|matches[nueva_password]'
            ];

            if (!$this->validate($rules)) {
                return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
            }

            $data = $this->request->getJSON(true);

            // Verificar token de sesión del paso 2
            $tokenVerification = $this->totpService->verifySessionToken(
                $data['session_token'],
                $data['session_signature'],
                'password_reset_step2'
            );

            if (!$tokenVerification['valid']) {
                return $this->respuestaError('Token de sesión inválido: ' . $tokenVerification['error'], 401);
            }

            // Verificar payload seguro
            $payloadVerification = $this->totpService->verifySecurePayload(
                $data['payload'],
                $data['payload_signature']
            );

            if (!$payloadVerification['valid']) {
                return $this->respuestaError('Payload inválido: ' . $payloadVerification['error'], 401);
            }

            $payloadData = $payloadVerification['data'];
            $sessionData = $tokenVerification['payload'];

            // Verificar que el user_id coincida
            if ($payloadData['user_id'] !== $sessionData['user_id']) {
                return $this->respuestaError('Datos inconsistentes', 400);
            }

            $userId = $payloadData['user_id'];

            // Verificar código TOTP con el token
            if (!$this->totpService->verifyTOTPWithToken($data['codigo_sms'], $sessionData)) {
                return $this->respuestaError('Código SMS incorrecto o expirado', 400);
            }
            // Obtener usuario
            $user = $this->userModel->find($userId);
            if (!$user) {
                return $this->respuestaError('Usuario no encontrado', 404);
            }

            // Cambiar contraseña
            $user->password = password_hash($data['nueva_password'], PASSWORD_BCRYPT);
            $this->userModel->save($user);

            // Log de seguridad
            log_message('info', "Password reset successful for user ID: {$userId}");

            return $this->respuestaExitosa(null, 'Contraseña restablecida exitosamente');
        } catch (\Exception $e) {
            log_message('error', 'Error in recuperarPaso3: ' . $e->getMessage());
            return $this->respuestaError('Error interno del servidor', 500);
        }
    }

    /**
     * Enviar SMS usando la API externa
     */
    private function enviarSMS($telefono, $mensaje)
    {
        try {
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://sms.hostrend.net/v1/gateway/sms/client/send',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer 4fd48cd8417bbc897c31be91e601cd7d2be45e683b17ca30b8812dc8850009c7',
                    'User-Agent: TiendaQuirquincho/1.0'
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'phone' => $telefono,
                    'message' => $mensaje
                ])
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if (curl_error($curl)) {
                throw new \Exception('CURL Error: ' . curl_error($curl));
            }

            curl_close($curl);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'success' => $data['type'] === 'success',
                    'message' => $data['message']
                ];
            }

            return ['success' => false, 'message' => 'Error HTTP: ' . $httpCode];
        } catch (\Exception $e) {
            log_message('error', 'SMS sending error: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
