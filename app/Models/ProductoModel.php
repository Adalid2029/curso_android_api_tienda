<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductoModel extends Model
{
    protected $table            = 'productos';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields = [
        'nombre',
        'descripcion',
        'precio',
        'cantidad_stock',
        'categoria_id',
        'imagen_url',
        'peso',
        'dimensiones',
        'marca',
        'disponible',
        'destacado',
        'usuario_creador'
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
        'nombre' => 'required|min_length[2]|max_length[200]',
        'descripcion' => 'required|min_length[10]',
        'precio' => 'required|decimal|greater_than[0]',
        'cantidad_stock' => 'required|integer|greater_than_equal_to[0]',
        'categoria_id' => 'required|integer|is_natural_no_zero',
        'usuario_creador' => 'required|integer'
    ];
    protected $validationMessages = [
        'nombre' => [
            'required' => 'El nombre del producto es obligatorio',
            'min_length' => 'El nombre debe tener al menos 2 caracteres'
        ],
        'precio' => [
            'required' => 'El precio es obligatorio',
            'greater_than' => 'El precio debe ser mayor a 0'
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
     * Busca productos por término con información completa
     */
    public function buscarProductos($termino, $categoriaId = null)
    {
        $query = $this->select('productos.*, categorias.nombre as categoria_nombre, categorias.color as categoria_color')
            ->join('categorias', 'categorias.id = productos.categoria_id')
            ->where('productos.disponible', true)
            ->where('categorias.estado', 'activo');

        if ($termino) {
            $query->groupStart()
                ->like('productos.nombre', $termino)
                ->orLike('productos.descripcion', $termino)
                ->orLike('productos.marca', $termino)
                ->groupEnd();
        }

        if ($categoriaId) {
            $query->where('productos.categoria_id', $categoriaId);
        }

        return $query->orderBy('productos.nombre', 'ASC')->findAll();
    }

    /**
     * Obtiene productos por categoría
     */
    public function obtenerProductosPorCategoria($categoriaId, $limit = null, $offset = 0)
    {
        $query = $this->select('productos.*, categorias.nombre as categoria_nombre')
            ->join('categorias', 'categorias.id = productos.categoria_id')
            ->where('productos.categoria_id', $categoriaId)
            ->where('productos.disponible', true)
            ->where('categorias.estado', 'activo')
            ->orderBy('productos.nombre', 'ASC');

        if ($limit) {
            $query->limit($limit, $offset);
        }

        return $query->findAll();
    }

    /**
     * Obtiene productos con información de categoría y creador
     */
    public function obtenerProductosConCategoria($limit = null, $offset = 0)
    {
        $query = $this->select('productos.*, categorias.nombre as categoria_nombre, categorias.color as categoria_color, users.username as creador_username, user_profiles.nombre_completo as creador_nombre')
            ->join('categorias', 'categorias.id = productos.categoria_id')
            ->join('users', 'users.id = productos.usuario_creador', 'left')
            ->join('user_profiles', 'user_profiles.user_id = productos.usuario_creador', 'left')
            ->where('productos.disponible', true)
            ->where('categorias.estado', 'activo')
            ->orderBy('productos.created_at', 'DESC');

        if ($limit) {
            $query->limit($limit, $offset);
        }

        return $query->findAll();
    }

    /**
     * Verificar si hay stock suficiente
     */
    public function verificarStock($productoId, $cantidadSolicitada)
    {
        $producto = $this->find($productoId);

        if (!$producto || !$producto['disponible']) {
            return false;
        }

        return $producto['cantidad_stock'] >= $cantidadSolicitada;
    }

    /**
     * Reducir stock de producto
     */
    public function reducirStock($productoId, $cantidad)
    {
        $producto = $this->find($productoId);

        if (!$producto || $producto['cantidad_stock'] < $cantidad) {
            return false;
        }

        $nuevoStock = $producto['cantidad_stock'] - $cantidad;

        return $this->update($productoId, [
            'cantidad_stock' => $nuevoStock
        ]);
    }

    /**
     * Aumentar stock de producto (para devoluciones/cancelaciones)
     */
    public function aumentarStock($productoId, $cantidad)
    {
        $producto = $this->find($productoId);

        if (!$producto) {
            return false;
        }

        $nuevoStock = $producto['cantidad_stock'] + $cantidad;

        return $this->update($productoId, [
            'cantidad_stock' => $nuevoStock
        ]);
    }

    /**
     * Obtener productos con stock bajo
     */
    public function obtenerProductosStockBajo($limite = 10)
    {
        return $this->select('productos.*, categorias.nombre as categoria_nombre')
            ->join('categorias', 'categorias.id = productos.categoria_id')
            ->where('productos.disponible', true)
            ->where('productos.cantidad_stock <=', 10) // Stock crítico
            ->orderBy('productos.cantidad_stock', 'ASC')
            ->limit($limite)
            ->findAll();
    }

    /*
    * Subir imagen de producto
     */
    public function subirImagen($id, $imagen)
    {
        $producto = $this->find($id);
        if (!$producto) {
            return false;
        }

        // Crear carpeta si no existe
        $carpetaDestino = FCPATH  . 'uploads/productos/' . $id;
        if (!is_dir($carpetaDestino)) {
            mkdir($carpetaDestino, 0777, true);
        }

        // Obtener nombre aleatorio
        $nombreArchivo = $imagen->getRandomName();

        // Mover imagen
        if (!$imagen->move($carpetaDestino, $nombreArchivo)) {
            return false;
        }

        // Guardar ruta relativa
        $rutaRelativa = 'uploads/productos/' . $id . '/' . $nombreArchivo;
        $this->update($id, ['imagen_url' => $rutaRelativa]);

        return $rutaRelativa;
    }
}
