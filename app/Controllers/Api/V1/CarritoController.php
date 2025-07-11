<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\ProductoModel;
use App\Models\CarritoModel;


class CarritoController extends BaseController
{
    protected $carritoModel;
    protected $productoModel;

    public function __construct()
    {
        $this->carritoModel = new CarritoModel();
        $this->productoModel = new ProductoModel();
    }

    /**
     * GET /api/v1/carrito
     * Obtener carrito del usuario autenticado
     */
    public function index()
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        try {
            $carrito = $this->carritoModel->obtenerCarritoUsuario($user->id);
            $totales = $this->carritoModel->calcularTotalCarrito($user->id);

            // Verificar disponibilidad de productos
            $itemsNoDisponibles = $this->carritoModel->verificarDisponibilidadCarrito($user->id);

            return $this->respuestaExitosa([
                'items' => $carrito,
                'totales' => $totales,
                'items_no_disponibles' => $itemsNoDisponibles,
                'tiene_items' => count($carrito) > 0,
                'requiere_actualizacion' => count($itemsNoDisponibles) > 0
            ]);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al obtener carrito: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/carrito/agregar
     * Agregar producto al carrito
     */
    public function agregar()
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        $rules = [
            'producto_id' => 'required|integer',
            'cantidad' => 'required|integer|greater_than[0]'
        ];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Datos inválidos', 400, $this->validator->getErrors());
        }

        $datos = $this->request->getJSON(true);

        try {
            // Verificar que el producto existe y está disponible
            $producto = $this->productoModel->find($datos['producto_id']);

            if (!$producto) {
                return $this->respuestaError('Producto no encontrado', 404);
            }

            if (!$producto['disponible']) {
                return $this->respuestaError('Producto no disponible', 400);
            }

            // Verificar stock disponible
            if (!$this->productoModel->verificarStock($datos['producto_id'], $datos['cantidad'])) {
                return $this->respuestaError('Stock insuficiente. Disponible: ' . $producto['cantidad_stock'], 400);
            }

            // Agregar al carrito
            $resultado = $this->carritoModel->agregarOActualizarProducto(
                $user->id,
                $datos['producto_id'],
                $datos['cantidad'],
                $producto['precio']
            );

            if (!$resultado) {
                return $this->respuestaError('Error al agregar al carrito', 500);
            }

            // Obtener totales actualizados
            $totales = $this->carritoModel->calcularTotalCarrito($user->id);

            return $this->respuestaExitosa([
                'totales' => $totales,
                'producto_agregado' => [
                    'nombre' => $producto['nombre'],
                    'cantidad' => $datos['cantidad'],
                    'precio_unitario' => $producto['precio']
                ]
            ], 'Producto agregado al carrito exitosamente');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al agregar producto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/carrito/item/{id}
     * Actualizar cantidad de un item del carrito
     */
    public function actualizar($itemId = null)
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        $rules = ['cantidad' => 'required|integer|greater_than[0]'];

        if (!$this->validate($rules)) {
            return $this->respuestaError('Cantidad inválida', 400, $this->validator->getErrors());
        }

        $datos = $this->request->getJSON(true);

        try {
            // Verificar que el item pertenece al usuario
            $item = $this->carritoModel->where('id', $itemId)
                ->where('user_id', $user->id)
                ->first();

            if (!$item) {
                return $this->respuestaError('Item no encontrado en tu carrito', 404);
            }

            // Verificar stock disponible
            if (!$this->productoModel->verificarStock($item['producto_id'], $datos['cantidad'])) {
                $producto = $this->productoModel->find($item['producto_id']);
                return $this->respuestaError('Stock insuficiente. Disponible: ' . $producto['cantidad_stock'], 400);
            }

            // Actualizar cantidad
            $resultado = $this->carritoModel->actualizarCantidad($itemId, $user->id, $datos['cantidad']);

            if (!$resultado) {
                return $this->respuestaError('Error al actualizar item', 500);
            }

            // Obtener totales actualizados
            $totales = $this->carritoModel->calcularTotalCarrito($user->id);

            return $this->respuestaExitosa([
                'totales' => $totales
            ], 'Cantidad actualizada exitosamente');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al actualizar item: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/carrito/item/{id}
     * Remover item del carrito
     */
    public function remover($itemId = null)
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        try {
            $resultado = $this->carritoModel->removerItem($itemId, $user->id);

            if (!$resultado) {
                return $this->respuestaError('Item no encontrado en tu carrito', 404);
            }

            // Obtener totales actualizados
            $totales = $this->carritoModel->calcularTotalCarrito($user->id);

            return $this->respuestaExitosa([
                'totales' => $totales
            ], 'Item removido del carrito');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al remover item: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/carrito/vaciar
     * Vaciar carrito completo
     */
    public function vaciar()
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        try {
            $this->carritoModel->vaciarCarrito($user->id);

            return $this->respuestaExitosa([
                'totales' => [
                    'subtotal' => 0,
                    'total_items' => 0,
                    'items' => 0
                ]
            ], 'Carrito vaciado exitosamente');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al vaciar carrito: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/carrito/verificar
     * Verificar disponibilidad de productos en el carrito
     */
    public function verificar()
    {
        $auth = service('auth');
        $user = $auth->user();

        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        try {
            $itemsNoDisponibles = $this->carritoModel->verificarDisponibilidadCarrito($user->id);
            $totalItems = $this->carritoModel->contarItemsCarrito($user->id);

            return $this->respuestaExitosa([
                'carrito_valido' => count($itemsNoDisponibles) === 0,
                'total_items' => $totalItems,
                'items_no_disponibles' => $itemsNoDisponibles,
                'puede_proceder_compra' => count($itemsNoDisponibles) === 0 && $totalItems > 0
            ]);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al verificar carrito: ' . $e->getMessage(), 500);
        }
    }
}
