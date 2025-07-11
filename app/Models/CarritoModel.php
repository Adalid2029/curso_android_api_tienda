<?php

namespace App\Models;

use CodeIgniter\Model;

class CarritoModel extends Model
{
    protected $table            = 'carrito';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields = [
        'user_id',
        'producto_id',
        'cantidad',
        'precio_unitario'
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
        'producto_id' => 'required|integer',
        'cantidad' => 'required|integer|greater_than[0]',
        'precio_unitario' => 'required|decimal|greater_than[0]'
    ];
    protected $validationMessages   = [];
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
     * Obtiene carrito completo de un usuario con información de productos
     */
    public function obtenerCarritoUsuario($userId)
    {
        return $this->select('carrito.*, productos.nombre, productos.imagen_url, productos.disponible, productos.cantidad_stock, categorias.nombre as categoria_nombre')
            ->join('productos', 'productos.id = carrito.producto_id')
            ->join('categorias', 'categorias.id = productos.categoria_id', 'left')
            ->where('carrito.user_id', $userId)
            ->where('productos.disponible', true)
            ->orderBy('carrito.created_at', 'ASC')
            ->findAll();
    }

    /**
     * Agrega o actualiza producto en carrito
     */
    public function agregarOActualizarProducto($userId, $productoId, $cantidad, $precioUnitario)
    {
        $itemExistente = $this->where('user_id', $userId)
            ->where('producto_id', $productoId)
            ->first();


        if ($itemExistente) {
            // Actualizar cantidad existente
            $nuevaCantidad = $itemExistente['cantidad'] + $cantidad;
            return $this->update($itemExistente['id'], [
                'cantidad' => $nuevaCantidad,
                'precio_unitario' => $precioUnitario
            ]);
        } else {
            // Agregar nuevo item
            return $this->insert([
                'user_id' => $userId,
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'precio_unitario' => $precioUnitario
            ]);
        }
    }

    /**
     * Actualiza cantidad de un item específico
     */
    public function actualizarCantidad($itemId, $userId, $nuevaCantidad)
    {
        return $this->where('id', $itemId)
            ->where('user_id', $userId)
            ->set(['cantidad' => $nuevaCantidad])
            ->update();
    }

    /**
     * Remover item específico del carrito
     */
    public function removerItem($itemId, $userId)
    {
        $item = $this->where('id', $itemId)
            ->where('user_id', $userId)
            ->first();

        if ($item) {
            return $this->delete($itemId);
        }

        return false;
    }

    /**
     * Vacía carrito de usuario
     */
    public function vaciarCarrito($userId)
    {
        return $this->where('user_id', $userId)->delete();
    }

    /**
     * Obtiene productos más agregados al carrito (tendencias)
     */
    public function obtenerProductosTendencia($limite = 10)
    {
        return $this->select('productos.nombre, productos.imagen_url, COUNT(carrito.producto_id) as veces_agregado, AVG(carrito.precio_unitario) as precio_promedio')
            ->join('productos', 'productos.id = carrito.producto_id')
            ->where('carrito.created_at >=', date('Y-m-d', strtotime('-30 days')))
            ->groupBy('carrito.producto_id')
            ->orderBy('veces_agregado', 'DESC')
            ->limit($limite)
            ->findAll();
    }

    /**
     * Verificar disponibilidad de productos en el carrito
     */
    public function verificarDisponibilidadCarrito($userId)
    {
        $itemsCarrito = $this->obtenerCarritoUsuario($userId);
        $itemsNoDisponibles = [];

        foreach ($itemsCarrito as $item) {
            $producto = model('ProductoModel')->find($item['producto_id']);

            if (!$producto || !$producto['disponible']) {
                $itemsNoDisponibles[] = [
                    'item_id' => $item['id'],
                    'producto_id' => $item['producto_id'],
                    'nombre' => $item['nombre'],
                    'razon' => 'Producto ya no disponible'
                ];
            } elseif ($producto['cantidad_stock'] < $item['cantidad']) {
                $itemsNoDisponibles[] = [
                    'item_id' => $item['id'],
                    'producto_id' => $item['producto_id'],
                    'nombre' => $item['nombre'],
                    'cantidad_solicitada' => $item['cantidad'],
                    'stock_disponible' => $producto['cantidad_stock'],
                    'razon' => 'Stock insuficiente'
                ];
            }
        }

        return $itemsNoDisponibles;
    }

    /**
     * Calcular totales del carrito
     */
    public function calcularTotalCarrito($userId)
    {
        $items = $this->obtenerCarritoUsuario($userId);
        $subtotal = 0;
        $totalItems = 0;

        foreach ($items as $item) {
            $subtotalItem = $item['cantidad'] * $item['precio_unitario'];
            $subtotal += $subtotalItem;
            $totalItems += $item['cantidad'];
        }

        return [
            'subtotal' => $subtotal,
            'total_items' => $totalItems,
            'items' => count($items),
            'productos' => $items
        ];
    }

    /**
     * Contar items en el carrito
     */
    public function contarItemsCarrito($userId)
    {
        return $this->where('user_id', $userId)->countAllResults();
    }
}
