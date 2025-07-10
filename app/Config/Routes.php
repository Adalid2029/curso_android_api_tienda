<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api/v1', function ($routes) {
    $routes->get('productos', 'Api\V1\ProductosController::index');
    $routes->get('productos/(:num)', 'Api\V1\ProductosController::show/$1');
    $routes->get('productos/buscar', 'Api\V1\ProductosController::buscar');
    $routes->post('productos', 'Api\V1\ProductosController::create');
    $routes->put('productos/(:num)', 'Api\V1\ProductosController::update/$1');
    $routes->delete('productos/(:num)', 'Api\V1\ProductosController::delete/$1');
    $routes->post('productos/(:num)/imagen', 'Api\V1\ProductosController::subirImagen/$1');

    // === CARRITO ===
    $routes->get('carrito', 'Api\V1\CarritoController::index');
    $routes->post('carrito/agregar', 'Api\V1\CarritoController::agregar');
    $routes->put('carrito/item/(:num)', 'Api\V1\CarritoController::actualizar/$1');
    $routes->delete('carrito/item/(:num)', 'Api\V1\CarritoController::remover/$1');
    $routes->delete('carrito/vaciar', 'Api\V1\CarritoController::vaciar');
});


service('auth')->routes($routes);
