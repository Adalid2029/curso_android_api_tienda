<?php

namespace App\Models;

use CodeIgniter\Model;

class PedidoModel extends Model
{
    protected $table            = 'pedidos';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields = [
        'numero_pedido',
        'user_id',
        'estado',
        'total',
        'subtotal',
        'metodo_pago',
        'notas',
        'fecha_confirmacion',
        'fecha_entrega'
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
        'total' => 'required|decimal|greater_than[0]',
        'metodo_pago' => 'required|in_list[efectivo,tarjeta,transferencia,qr]',
        'telefono_contacto' => 'required|min_length[7]'
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert = ['generarNumeroPedido'];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * Genera número único de pedido
     */
    protected function generarNumeroPedido(array $data)
    {
        $data['data']['numero_pedido'] = 'TQ-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        return $data;
    }

    /**
     * Obtiene pedido específico con detalles
     */
    public function obtenerPedidoCompleto($pedidoId, $userId = null)
    {
        $query = $this->select('pedidos.*, users.username, user_profiles.nombre_completo, user_profiles.telefono as telefono_usuario')
            ->join('users', 'users.id = pedidos.user_id')
            ->join('user_profiles', 'user_profiles.user_id = pedidos.user_id', 'left')
            ->where('pedidos.id', $pedidoId);

        if ($userId) {
            $query->where('pedidos.user_id', $userId);
        }

        return $query->first();
    }

    /**
     * Obtiene pedidos por estado
     */
    public function obtenerPedidosPorEstado($estado, $limit = null)
    {
        $query = $this->select('pedidos.*, users.username, user_profiles.nombre_completo, user_profiles.telefono')
            ->join('users', 'users.id = pedidos.user_id')
            ->join('user_profiles', 'user_profiles.user_id = pedidos.user_id', 'left')
            ->where('pedidos.estado', $estado)
            ->orderBy('pedidos.created_at', 'DESC');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->findAll();
    }


    /**
     * Obtiene estadísticas de pedidos
     */
    public function obtenerEstadisticas($fechaInicio = null, $fechaFin = null)
    {
        $query = $this->select('estado, COUNT(*) as cantidad, SUM(total) as total_ventas');

        if ($fechaInicio) {
            $query->where('created_at >=', $fechaInicio);
        }
        if ($fechaFin) {
            $query->where('created_at <=', $fechaFin . ' 23:59:59');
        }

        $estadisticasPorEstado = $query->groupBy('estado')->findAll();

        // Totales generales
        $totalesQuery = $this->select('COUNT(*) as total_pedidos, SUM(total) as ventas_totales, AVG(total) as ticket_promedio');

        if ($fechaInicio) {
            $totalesQuery->where('created_at >=', $fechaInicio);
        }
        if ($fechaFin) {
            $totalesQuery->where('created_at <=', $fechaFin . ' 23:59:59');
        }

        $totales = $totalesQuery->first();

        return [
            'totales' => $totales,
            'por_estado' => $estadisticasPorEstado
        ];
    }

    /**
     * Obtiene ventas por período
     */
    public function obtenerVentasPorPeriodo($periodo = 'mes')
    {
        $formatoFecha = match ($periodo) {
            'dia' => 'DATE(created_at)',
            'semana' => 'YEARWEEK(created_at)',
            'mes' => 'DATE_FORMAT(created_at, "%Y-%m")',
            'año' => 'YEAR(created_at)',
            default => 'DATE(created_at)'
        };

        return $this->select("$formatoFecha as periodo, COUNT(*) as pedidos, SUM(total) as ventas")
            ->where('estado !=', 'cancelado')
            ->groupBy($formatoFecha)
            ->orderBy($formatoFecha, 'DESC')
            ->limit(12)
            ->findAll();
    }

    /**
     * Contar total de pedidos de un usuario
     * Se usa para la paginación en el controlador
     */
    public function contarPedidosUsuario($userId, $estado = null)
    {
        $query = $this->where('user_id', $userId);

        // Si se proporciona un estado específico, filtrar por él
        if ($estado) {
            $query->where('estado', $estado);
        }

        return $query->countAllResults();
    }

    /**
     * Crear nuevo pedido con validaciones
     * Esta función faltaba en tu modelo
     */
    public function crearPedido($datosPedido)
    {
        // Validar los datos antes de insertar
        if (!$this->validate($datosPedido)) {
            return false;
        }

        // Insertar el pedido y retornar el ID
        return $this->insert($datosPedido);
    }

    /**
     * Obtener pedidos de usuario con paginación y filtros
     * Actualización de tu función existente para soportar offset y filtros
     */
    public function obtenerPedidosUsuario($userId, $limite = 20, $offset = 0, $estado = null)
    {
        $query = $this->select('pedidos.*, users.username, user_profiles.nombre_completo')
            ->join('users', 'users.id = pedidos.user_id')
            ->join('user_profiles', 'user_profiles.user_id = pedidos.user_id', 'left')
            ->where('pedidos.user_id', $userId);

        // Filtrar por estado si se proporciona
        if ($estado) {
            $query->where('pedidos.estado', $estado);
        }

        return $query->orderBy('pedidos.created_at', 'DESC')
            ->limit($limite, $offset)
            ->findAll();
    }

    /**
     * Obtener detalle completo del pedido con productos
     * Esta función faltaba en tu modelo
     */
    public function obtenerDetalleCompleto($pedidoId)
    {
        // Obtener información del pedido
        $pedido = $this->select('pedidos.*, users.username, user_profiles.nombre_completo, user_profiles.telefono')
            ->join('users', 'users.id = pedidos.user_id')
            ->join('user_profiles', 'user_profiles.user_id = pedidos.user_id', 'left')
            ->find($pedidoId);

        if (!$pedido) {
            return null;
        }

        // Obtener detalles de productos del pedido
        $detallePedidoModel = new \App\Models\DetallePedidoModel();
        $detalles = $detallePedidoModel->select('detalle_pedidos.*, productos.imagen_url, productos.disponible')
            ->join('productos', 'productos.id = detalle_pedidos.producto_id', 'left')
            ->where('detalle_pedidos.pedido_id', $pedidoId)
            ->findAll();

        return [
            'pedido' => $pedido,
            'detalles' => $detalles,
            'resumen' => [
                'total_productos' => count($detalles),
                'subtotal' => $pedido['subtotal'],
                'impuestos' => $pedido['impuestos'] ?? 0,
                'descuento' => $pedido['descuento'] ?? 0,
                'costo_envio' => $pedido['costo_envio'] ?? 0,
                'total' => $pedido['total']
            ]
        ];
    }

    /**
     * Actualizar estado del pedido con notas adicionales
     * Mejora de tu función existente
     */
    public function actualizarEstado($pedidoId, $nuevoEstado, $notasEstado = null)
    {
        $data = [
            'estado' => $nuevoEstado
        ];

        // Agregar notas si se proporcionan
        if ($notasEstado) {
            $data['notas'] = $notasEstado;
        }

        // Agregar fechas específicas según el estado
        switch ($nuevoEstado) {
            case 'confirmado':
                $data['fecha_confirmacion'] = date('Y-m-d H:i:s');
                break;
            case 'entregado':
                $data['fecha_entrega'] = date('Y-m-d H:i:s');
                break;
        }

        return $this->update($pedidoId, $data);
    }
}
