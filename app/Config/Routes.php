<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api/v1', function ($routes) {

    $routes->post('auth/login', 'Api\V1\AuthController::login');
    $routes->post('auth/registro', 'Api\V1\AuthController::registro');
    $routes->post('auth/logout', 'Api\V1\AuthController::logout');
    $routes->get('auth/perfil', 'Api\V1\AuthController::perfil');
    $routes->put('auth/perfil', 'Api\V1\AuthController::actualizarPerfil');
    $routes->post('auth/recuperar-contrasena', 'Api\V1\AuthController::recuperarContrasena');
    $routes->post('auth/cambiar-contrasena', 'Api\V1\AuthController::cambiarContrasena');

    $routes->get('productos', 'Api\V1\ProductosController::index');
    $routes->get('productos/(:num)', 'Api\V1\ProductosController::show/$1');
    $routes->get('productos/buscar', 'Api\V1\ProductosController::buscar');
    $routes->post('productos', 'Api\V1\ProductosController::create', ['filter' => 'jwt_auth']);
    $routes->put('productos/(:num)', 'Api\V1\ProductosController::update/$1', ['filter' => 'jwt_auth']);
    $routes->delete('productos/(:num)', 'Api\V1\ProductosController::delete/$1', ['filter' => 'jwt_auth']);
    $routes->post('productos/(:num)/imagen', 'Api\V1\ProductosController::subirImagen/$1', ['filter' => 'jwt_auth']);

    // === CARRITO ===
    $routes->get('carrito', 'Api\V1\CarritoController::index', ['filter' => 'jwt_auth']);
    $routes->post('carrito/agregar', 'Api\V1\CarritoController::agregar', ['filter' => 'jwt_auth']);
    $routes->put('carrito/item/(:num)', 'Api\V1\CarritoController::actualizar/$1', ['filter' => 'jwt_auth']);
    $routes->delete('carrito/item/(:num)', 'Api\V1\CarritoController::remover/$1', ['filter' => 'jwt_auth']);
    $routes->delete('carrito/vaciar', 'Api\V1\CarritoController::vaciar', ['filter' => 'jwt_auth']);

    $routes->get('categorias', 'Api\V1\CategoriasController::index');
    $routes->get('categorias/(:num)', 'Api\V1\CategoriasController::show/$1');
    $routes->get('categorias/(:num)/productos', 'Api\V1\CategoriasController::productos/$1');
    $routes->post('categorias', 'Api\V1\CategoriasController::create', ['filter' => 'jwt_auth']);
    $routes->put('categorias/(:num)', 'Api\V1\CategoriasController::update/$1', ['filter' => 'jwt_auth']);
    $routes->delete('categorias/(:num)', 'Api\V1\CategoriasController::delete/$1', ['filter' => 'jwt_auth']);


    $routes->get('pedidos', 'Api\V1\PedidosController::index', ['filter' => 'jwt_auth']);
    $routes->get('pedidos/(:num)', 'Api\V1\PedidosController::show/$1', ['filter' => 'jwt_auth']);
    $routes->post('pedidos', 'Api\V1\PedidosController::create', ['filter' => 'jwt_auth']);
    $routes->put('pedidos/(:num)/estado', 'Api\V1\PedidosController::actualizarEstado/$1', ['filter' => 'jwt_auth']);
    $routes->get('pedidos/(:num)/detalle', 'Api\V1\PedidosController::detalle/$1', ['filter' => 'jwt_auth']);

    // === CONFIGURACIÓN ===
    $routes->get('configuracion/tienda', 'Api\V1\ConfiguracionController::tienda');
    $routes->put('configuracion/tienda', 'Api\V1\ConfiguracionController::actualizarTienda', ['filter' => 'jwt_auth']);

    // === USUARIOS (Admin) ===
    $routes->get('usuarios', 'Api\V1\UsuariosController::index', ['filter' => 'admin']);
    $routes->get('usuarios/(:num)', 'Api\V1\UsuariosController::show/$1', ['filter' => 'admin']);
    $routes->put('usuarios/(:num)/estado', 'Api\V1\UsuariosController::cambiarEstado/$1', ['filter' => 'admin']);

    // === REPORTES (Admin) ===
    $routes->get('reportes/ventas', 'Api\V1\ReportesController::ventas', ['filter' => 'admin']);
    $routes->get('reportes/productos-populares', 'Api\V1\ReportesController::productosPopulares', ['filter' => 'admin']);
    $routes->get('reportes/usuarios-activos', 'Api\V1\ReportesController::usuariosActivos', ['filter' => 'admin']);
});


service('auth')->routes($routes);
