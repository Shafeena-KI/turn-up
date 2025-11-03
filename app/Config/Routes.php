<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->post('api/admin/login', 'Api\Login::adminLogin');



//Event Api 
$routes->get('api/events', 'Api\EventController::index');
$routes->get('api/events/(:num)', 'Api\EventController::show/$1');
$routes->post('api/events/create', 'Api\EventController::create');
$routes->post('api/events/(:num)', 'Api\EventController::update/$1');
$routes->delete('api/events/(:num)', 'Api\EventController::delete/$1');
