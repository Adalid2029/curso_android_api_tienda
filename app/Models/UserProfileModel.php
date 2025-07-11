<?php

namespace App\Models;

use CodeIgniter\Model;

class UserProfileModel extends Model
{
    protected $table            = 'user_profiles';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields = [
        'user_id',
        'nombre_completo',
        'telefono',
        'direccion',
        'fecha_nacimiento',
        'avatar_url',
        'preferencias'
    ];
    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    // Dates
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules = [
        'user_id' => 'required|integer',
        'nombre_completo' => 'required|min_length[2]|max_length[100]',
        'telefono' => 'permit_empty|min_length[7]|max_length[20]',
    ];

    protected $validationMessages = [
        'nombre_completo' => [
            'required' => 'El nombre completo es obligatorio',
            'min_length' => 'El nombre debe tener al menos 2 caracteres'
        ]
    ];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];


    /**
     * Obtener perfil por user_id
     */
    public function getByUserId(int $userId)
    {
        return $this->where('user_id', $userId)->first();
    }

    /**
     * Crear perfil para nuevo usuario
     */
    public function createForUser(int $userId, array $data)
    {
        $data['user_id'] = $userId;
        return $this->insert($data);
    }

    /**
     * Actualizar perfil de usuario
     */
    public function updateByUserId(int $userId, array $data)
    {
        return $this->where('user_id', $userId)->set($data)->update();
    }

    /**
     * Obtener perfil completo con datos de usuario
     */
    public function getCompleteProfile(int $userId)
    {
        return $this->select('user_profiles.*, users.username, users.created_at as fecha_registro')
            ->join('users', 'users.id = user_profiles.user_id')
            ->where('user_profiles.user_id', $userId)
            ->first();
    }
}
