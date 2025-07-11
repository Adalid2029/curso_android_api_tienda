<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\PedidoModel;
use App\Models\DetallePedidoModel;
use App\Models\CarritoModel;
use App\Models\ProductoModel;

class PedidosController extends BaseController
{
    protected $pedidoModel;
    protected $detallePedidoModel;
    protected $carritoModel;
    protected $productoModel;

    public function __construct()
    {
        $this->pedidoModel = new PedidoModel();
        $this->detallePedidoModel = new DetallePedidoModel();
        $this->carritoModel = new CarritoModel();
        $this->productoModel = new ProductoModel();
    }

    /**
     * GET /api/v1/pedidos
     * Obtener historial de pedidos del usuario autenticado
     */
    public function index()
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        try {
            $limite = $this->request->getGet('limite') ?? 20;
            $pagina = $this->request->getGet('pagina') ?? 1;
            $estado = $this->request->getGet('estado'); // Filtrar por estado si se proporciona

            $offset = ($pagina - 1) * $limite;

            // Obtener pedidos del usuario
            $pedidos = $this->pedidoModel->obtenerPedidosUsuario($user->id, $limite, $offset, $estado);
            $totalPedidos = $this->pedidoModel->contarPedidosUsuario($user->id, $estado);

            return $this->respuestaExitosa([
                'pedidos' => $pedidos,
                'paginacion' => [
                    'pagina_actual' => (int)$pagina,
                    'limite' => (int)$limite,
                    'total_pedidos' => $totalPedidos,
                    'total_paginas' => ceil($totalPedidos / $limite)
                ],
                'filtros' => [
                    'estado' => $estado
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al obtener pedidos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/pedidos/{id}
     * Obtener pedido específico del usuario
     */
    public function show($pedidoId = null)
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        try {
            $pedido = $this->pedidoModel->obtenerPedidoCompleto($pedidoId, $user->id);

            if (!$pedido) {
                return $this->respuestaError('Pedido no encontrado', 404);
            }

            return $this->respuestaExitosa($pedido);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al obtener pedido: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/pedidos
     * Crear nuevo pedido desde el carrito del usuario
     */
    public function create()
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        $rules = [
            'metodo_pago' => 'required|in_list[efectivo,tarjeta,transferencia,qr]',
            'telefono_contacto' => 'required|min_length[7]'
        ];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
        }

        $datos = $this->request->getJSON(true);

        try {
            // Verificar que el carrito no esté vacío
            $itemsCarrito = $this->carritoModel->obtenerCarritoUsuario($user->id);

            if (empty($itemsCarrito)) {
                return $this->respuestaError('El carrito está vacío. Agrega productos antes de realizar el pedido.', 400);
            }

            // Verificar disponibilidad y stock de todos los productos
            $itemsNoDisponibles = $this->carritoModel->verificarDisponibilidadCarrito($user->id);
            if (!empty($itemsNoDisponibles)) {
                return $this->respuestaError(
                    'Algunos productos ya no están disponibles o no tienen stock suficiente',
                    400,
                    ['items_no_disponibles' => $itemsNoDisponibles]
                );
            }

            // Calcular totales del carrito
            $totalesCarrito = $this->carritoModel->calcularTotalCarrito($user->id);

            $subtotal = $totalesCarrito['subtotal'];
            $impuestos = $datos['impuestos'] ?? 0;
            $descuento = $datos['descuento'] ?? 0;
            $costoEnvio = $datos['costo_envio'] ?? 0;
            $total = $subtotal + $impuestos + $costoEnvio - $descuento;

            // Crear el pedido
            $datosPedido = [
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'impuestos' => $impuestos,
                'descuento' => $descuento,
                'costo_envio' => $costoEnvio,
                'total' => $total,
                'metodo_pago' => $datos['metodo_pago'],
                'telefono_contacto' => $datos['telefono_contacto'],
                'notas' => $datos['notas'] ?? null,
                'estado' => 'pendiente'
            ];

            $pedidoId = $this->pedidoModel->crearPedido($datosPedido);

            if (!$pedidoId) {
                return $this->respuestaError('Error al crear el pedido', 500);
            }

            // Crear detalles del pedido y reducir stock
            foreach ($itemsCarrito as $item) {
                // Insertar detalle del pedido
                $this->detallePedidoModel->insert([
                    'pedido_id' => $pedidoId,
                    'producto_id' => $item['producto_id'],
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'subtotal' => $item['cantidad'] * $item['precio_unitario'],
                    'nombre_producto' => $item['nombre'],
                    'descripcion_producto' => $item['descripcion'] ?? null
                ]);

                // Reducir stock del producto
                $this->productoModel->reducirStock($item['producto_id'], $item['cantidad']);
            }

            // Vaciar el carrito después de crear el pedido
            $this->carritoModel->vaciarCarrito($user->id);

            // Obtener el pedido completo creado
            $pedidoCompleto = $this->pedidoModel->obtenerPedidoCompleto($pedidoId, $user->id);

            // TODO: Enviar notificación por email
            // $this->enviarNotificacionPedido($pedidoCompleto, $user);

            return $this->respuestaExitosa([
                'pedido' => $pedidoCompleto,
                'mensaje_usuario' => 'Tu pedido ha sido creado exitosamente. Te notificaremos sobre el estado de tu pedido.'
            ], 'Pedido creado exitosamente', 201);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al crear pedido: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/pedidos/{id}/estado
     * Actualizar estado del pedido (solo admin/vendedor)
     */
    public function actualizarEstado($pedidoId = null)
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        // Verificar permisos (solo admin o vendedor pueden cambiar estados)
        // if (!in_array($user->role, ['admin', 'vendedor'])) {
        //     return $this->respuestaError('No tienes permisos para actualizar el estado de pedidos', 403);
        // }

        $rules = [
            'estado' => 'required|in_list[pendiente,confirmado,preparando,enviado,entregado,cancelado]'
        ];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Estado inválido', 400, $this->validator->getErrors());
        }

        $datos = $this->request->getJSON(true);

        try {
            // Verificar que el pedido existe
            $pedido = $this->pedidoModel->find($pedidoId);
            if (!$pedido) {
                return $this->respuestaError('Pedido no encontrado', 404);
            }

            // Verificar transiciones de estado válidas
            $transicionValida = $this->validarTransicionEstado($pedido['estado'], $datos['estado']);
            if (!$transicionValida) {
                return $this->respuestaError(
                    "No se puede cambiar de '{$pedido['estado']}' a '{$datos['estado']}'",
                    400
                );
            }

            // Actualizar estado del pedido
            $resultado = $this->pedidoModel->actualizarEstado($pedidoId, $datos['estado'], $datos['notas_estado'] ?? null);

            if (!$resultado) {
                return $this->respuestaError('Error al actualizar estado del pedido', 500);
            }

            // Si se cancela el pedido, devolver stock
            if ($datos['estado'] === 'cancelado') {
                $this->devolverStockPedido($pedidoId);
            }

            // Obtener pedido actualizado
            $pedidoActualizado = $this->pedidoModel->find($pedidoId);

            // TODO: Enviar notificación al cliente
            // $this->enviarNotificacionCambioEstado($pedidoActualizado);

            return $this->respuestaExitosa([
                'pedido' => $pedidoActualizado
            ], 'Estado del pedido actualizado exitosamente');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al actualizar estado: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/pedidos/{id}/detalle
     * Obtener detalle completo del pedido con todos los productos
     */
    public function detalle($pedidoId = null)
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        try {
            // Verificar que el pedido pertenece al usuario (a menos que sea admin)
            $pedido = $this->pedidoModel->find($pedidoId);

            if (!$pedido) {
                return $this->respuestaError('Pedido no encontrado', 404);
            }

            // Solo el propietario del pedido o admin/vendedor pueden ver detalles
            // if ($pedido['user_id'] !== $user->id && !in_array($user->role, ['admin', 'vendedor'])) {
            //     return $this->respuestaError('No tienes permisos para ver este pedido', 403);
            // }

            // Obtener detalle completo del pedido
            $detallePedido = $this->pedidoModel->obtenerDetalleCompleto($pedidoId);

            if (!$detallePedido) {
                return $this->respuestaError('No se pudo obtener el detalle del pedido', 500);
            }

            return $this->respuestaExitosa($detallePedido);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al obtener detalle del pedido: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Validar si la transición de estado es válida
     */
    private function validarTransicionEstado($estadoActual, $nuevoEstado)
    {
        $transicionesValidas = [
            'pendiente' => ['confirmado', 'cancelado'],
            'confirmado' => ['preparando', 'cancelado'],
            'preparando' => ['enviado', 'cancelado'],
            'enviado' => ['entregado'],
            'entregado' => [], // Estado final
            'cancelado' => [] // Estado final
        ];

        return in_array($nuevoEstado, $transicionesValidas[$estadoActual] ?? []);
    }

    /**
     * Devolver stock cuando se cancela un pedido
     */
    private function devolverStockPedido($pedidoId)
    {
        try {
            $detalles = $this->detallePedidoModel->where('pedido_id', $pedidoId)->findAll();

            foreach ($detalles as $detalle) {
                $this->productoModel->aumentarStock($detalle['producto_id'], $detalle['cantidad']);
            }
        } catch (\Exception $e) {
            log_message('error', 'Error al devolver stock del pedido ' . $pedidoId . ': ' . $e->getMessage());
        }
    }

    /**
     * GET /api/v1/pedidos/estados
     * Obtener lista de estados disponibles (método adicional útil)
     */
    public function estados()
    {
        $estados = [
            'pendiente' => 'Pendiente de confirmación',
            'confirmado' => 'Confirmado',
            'preparando' => 'Preparando pedido',
            'enviado' => 'Enviado',
            'entregado' => 'Entregado',
            'cancelado' => 'Cancelado'
        ];

        return $this->respuestaExitosa($estados);
    }
}
