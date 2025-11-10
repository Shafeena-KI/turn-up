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
$routes->post('api/user/verifyotp', 'Api\AppUser::verifyOtp');

//registration/user management
$routes->post('api/user/register', 'Api\AppUser::register');
$routes->post('api/user/getuser', 'Api\AppUser::getUserById');
$routes->post('api/user/update', 'Api\AppUser::updateUser');
$routes->post('api/user/delete', 'Api\AppUser::deleteUser');
$routes->get('api/user/list', 'Api\AppUser::listUsers'); // default (no search)
$routes->get('api/user/list/(:any)', 'Api\AppUser::listUsers/$1'); // with search term
$routes->post('api/user/profile-status', 'Api\AppUser::updateProfileStatus');
$routes->post('api/user/account-status', 'Api\AppUser::updateAccountStatus');



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


// Event Invites
$routes->post('api/event/invite/create', 'Api\EventInvite::createInvite');
$routes->post('api/event/invite/update-status', 'Api\EventInvite::updateInviteStatus');
$routes->post('api/event/invite/by-event', 'Api\EventInvite::getInvitesByEvent');
$routes->post('api/event/invite/by-user', 'Api\EventInvite::getInvitesByUser');
$routes->post('api/event/invite/expire-old', 'Api\EventInvite::expireOldInvites');

// Event Tickets
 $routes->post('api/ticket/create', 'Api\EventTicket::createTicket');
$routes->post('api/ticket/event', 'Api\EventTicket::getTicketsByEvent');
$routes->post('api/ticket/update', 'Api\EventTicket::updateTicket');
$routes->post('api/ticket/delete', 'Api\EventTicket::deleteTicket');

// Event Bookings
$routes->post('api/booking/create', 'Api\EventBooking::createBooking');
$routes->post('api/booking/event', 'Api\EventBooking::getBookingsByEvent');
$routes->post('api/booking/user', 'Api\EventBooking::getBookingsByUser');
$routes->post('api/booking/cancel', 'Api\EventBooking::cancelBooking');     