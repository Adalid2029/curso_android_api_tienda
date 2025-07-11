<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\ProductoModel;
use App\Models\CategoriaModel;

class ProductosController extends BaseController
{
    protected $productoModel;
    protected $categoriaModel;

    public function __construct()
    {
        $this->productoModel = new ProductoModel();
        $this->categoriaModel = new CategoriaModel();
    }

    /*
        * GET /api/v1/productos
        * Listar productos con paginación y búsqueda
        */
    public function index()
    {
        $limite = $this->request->getGet('limite') ?? 20;
        $pagina = $this->request->getGet('pagina') ?? 1;
        $busqueda = $this->request->getGet('busqueda');
        $categoriaId = $this->request->getGet('categoria_id');

        $offset = ($pagina - 1) * $limite;

        try {
            if ($busqueda || $categoriaId) {
                $productos = $this->productoModel->buscarProductos($busqueda, $categoriaId);
                // Para búsquedas, aplicar paginación manual
                $productos = array_slice($productos, $offset, $limite);
            } else {
                $productos = $this->productoModel->obtenerProductosConCategoria($limite, $offset);
            }

            return $this->respuestaExitosa([
                'productos' => $productos,
                'paginacion' => [
                    'pagina_actual' => (int)$pagina,
                    'limite' => (int)$limite,
                    'total_resultados' => count($productos)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al obtener productos: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/productos
     * Crear nuevo producto (requiere autenticación y permisos)
     */
    public function create()
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        // Verificar permisos
        // if (!$user->can('products.create')) {
        //     return $this->respuestaError('Sin permisos para crear productos', 403);
        // }

        $datos = $this->request->getJSON(true);
        $datos['usuario_creador'] = $user->id;

        if (!$this->productoModel->validate($datos)) {
            return $this->respuestaError('Datos inválidos', 400, $this->productoModel->errors());
        }

        try {
            $productoId = $this->productoModel->insert($datos);

            if (!$productoId) {
                return $this->respuestaError('Error al crear producto', 500);
            }

            $producto = $this->productoModel->find($productoId);
            return $this->respuestaExitosa($producto, 'Producto creado exitosamente', 201);
        } catch (\Exception $e) {
            return $this->respuestaError('Error al crear producto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v1/productos/{id}
     * Actualizar producto
     */
    public function update($id = null)
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        // if (!$user->can('products.edit')) {
        //     return $this->respuestaError('Sin permisos para editar productos', 403);
        // }

        $producto = $this->productoModel->find($id);
        if (!$producto) {
            return $this->respuestaError('Producto no encontrado', 404);
        }

        $datos = $this->request->getJSON(true);

        try {
            if (!$this->productoModel->update($id, $datos)) {
                return $this->respuestaError('Error al actualizar producto', 500);
            }

            $productoActualizado = $this->productoModel->find($id);
            return $this->respuestaExitosa($productoActualizado, 'Producto actualizado exitosamente');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al actualizar producto: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/productos/{id}
     * Eliminar producto
     */
    public function delete($id = null)
    {
        $user = $this->request->user;
        if (!$user) {
            return $this->respuestaError('Usuario no autenticado', 401);
        }

        // if (!$user->can('products.edit')) {
        //     return $this->respuestaError('Sin permisos para editar productos', 403);
        // }

        $producto = $this->productoModel->find($id);
        if (!$producto) {
            return $this->respuestaError('Producto no encontrado', 404);
        }

        try {
            if (!$this->productoModel->delete($id)) {
                return $this->respuestaError('Error al eliminar producto', 500);
            }

            return $this->respuestaExitosa(null, 'Producto eliminado exitosamente');
        } catch (\Exception $e) {
            return $this->respuestaError('Error al eliminar producto: ' . $e->getMessage(), 500);
        }
    }
}
