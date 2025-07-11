<?php

namespace App\Models;

use CodeIgniter\Model;

class DetallePedidoModel extends Model
{
    protected $table            = 'detalle_pedidos';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields = [
        'pedido_id',
        'producto_id',
        'cantidad',
        'precio_unitario',
        'subtotal',
        'nombre_producto',
        'descripcion_producto'
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
        'pedido_id' => 'required|integer',
        'producto_id' => 'required|integer',
        'cantidad' => 'required|integer|greater_than[0]',
        'precio_unitario' => 'required|decimal|greater_than[0]',
        'subtotal' => 'required|decimal|greater_than[0]',
        'nombre_producto' => 'required|min_length[2]'
    ];
    protected $validationMessages = [
        'pedido_id' => [
            'required' => 'El ID del pedido es obligatorio'
        ],
        'cantidad' => [
            'greater_than' => 'La cantidad debe ser mayor a 0'
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
     * Obtener detalles de un pedido específico
     */
    public function obtenerDetallesPedido($pedidoId)
    {
        return $this->select('detalle_pedidos.*, productos.imagen_url, productos.disponible, productos.cantidad_stock')
            ->join('productos', 'productos.id = detalle_pedidos.producto_id', 'left')
            ->where('detalle_pedidos.pedido_id', $pedidoId)
            ->orderBy('detalle_pedidos.id', 'ASC')
            ->findAll();
    }

    /**
     * Crear múltiples detalles de pedido
     */
    public function crearDetallesPedido($pedidoId, $items)
    {
        $datosDetalles = [];

        foreach ($items as $item) {
            $datosDetalles[] = [
                'pedido_id' => $pedidoId,
                'producto_id' => $item['producto_id'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio_unitario'],
                'subtotal' => $item['cantidad'] * $item['precio_unitario'],
                'nombre_producto' => $item['nombre'],
                'descripcion_producto' => $item['descripcion'] ?? null
            ];
        }

        return $this->insertBatch($datosDetalles);
    }

    /**
     * Obtener productos más vendidos
     */
    public function obtenerProductosMasVendidos($limite = 10, $fechaInicio = null, $fechaFin = null)
    {
        $query = $this->select('
            detalle_pedidos.producto_id,
            detalle_pedidos.nombre_producto,
            SUM(detalle_pedidos.cantidad) as total_vendido,
            SUM(detalle_pedidos.subtotal) as total_ingresos,
            productos.imagen_url,
            productos.precio as precio_actual
        ')
            ->join('productos', 'productos.id = detalle_pedidos.producto_id', 'left')
            ->join('pedidos', 'pedidos.id = detalle_pedidos.pedido_id')
            ->where('pedidos.estado !=', 'cancelado');

        if ($fechaInicio && $fechaFin) {
            $query->where('pedidos.fecha_pedido >=', $fechaInicio)
                ->where('pedidos.fecha_pedido <=', $fechaFin);
        }

        return $query->groupBy('detalle_pedidos.producto_id')
            ->orderBy('total_vendido', 'DESC')
            ->limit($limite)
            ->findAll();
    }
}
