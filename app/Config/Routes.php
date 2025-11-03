<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// admin login
$routes->post('api/admin/login', 'Api\Login::adminLogin');

// registration api
$routes->post('api/user/register', 'Api\AppUser::register');


