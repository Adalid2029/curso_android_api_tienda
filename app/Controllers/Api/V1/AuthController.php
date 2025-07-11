<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\UserProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Entities\User;

class AuthController extends BaseController
{
    protected $userModel;
    protected $userProfileModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->userProfileModel = new UserProfileModel();
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
        $jwtAuth = new \App\Libraries\JWTAuthenticator();
        $user = $jwtAuth->requireAuth();

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

        // Verificar contraseña actual
        if (!$user->checkPassword($data['current_password'])) {
            return $this->respuestaError('Contraseña actual incorrecta', 400);
        }

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
     * POST /api/v1/auth/forgot-password
     * Solicitar reset de contraseña
     */
    public function recuperarContrasena()
    {
        $rules = ['email' => 'required|valid_email'];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Email inválido', 400, $this->validator->getErrors());
        }

        $email = $this->request->getJSON()->email;

        // Buscar usuario por email
        $user = $this->userModel->findByCredentials(['email' => $email]);

        if (!$user) {
            // Por seguridad, no revelar si el email existe o no
            return $this->respuestaExitosa(null, 'Si el email existe, recibirás instrucciones para restablecer tu contraseña');
        }

        try {
            // Generar token de reset
            $resetToken = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + (60 * 60)); // 1 hora

            // Guardar token en base de datos (necesitarías crear una tabla para esto)
            // Por ahora simulamos el envío del email

            // TODO: Implementar envío de email con token de reset
            // $emailService = new \App\Libraries\EmailLibrary();
            // $emailService->enviarPasswordReset($user, $resetToken);

            return $this->respuestaExitosa(null, 'Instrucciones enviadas a tu email');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al procesar solicitud', 500);
        }
    }
}
