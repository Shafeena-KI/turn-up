<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

//admin login
$routes->post('api/admin/login', 'Api\Login::adminLogin');

//user login
$routes->post('api/user/login', 'Api\AppUser::UserLogin');

//registration/user management
$routes->post('api/user/register', 'Api\AppUser::register');
$routes->post('api/user/getuser', 'Api\AppUser::getUserById');
$routes->post('api/user/update', 'Api\AppUser::updateUser');
$routes->post('api/user/delete', 'Api\AppUser::deleteUser');


//Event Api 
$routes->get('api/events', 'Api\EventController::index');
$routes->get('api/events/(:num)', 'Api\EventController::show/$1');
$routes->post('api/events/create', 'Api\EventController::create');
$routes->post('api/events/update', 'Api\EventController::update');
$routes->delete('api/events/delete', 'Api\EventController::delete');


//Roles and Permissions 
$routes->get('api/roles', 'Api\RoleController::index');
$routes->get('api/roles/(:num)', 'Api\RoleController::show/$1');
$routes->post('api/roles/create', 'Api\RoleController::create');
$routes->post('api/roles/update', 'Api\RoleController::update');
$routes->delete('api/roles/delete', 'Api\RoleController::delete');
