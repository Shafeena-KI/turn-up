<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

//admin login
$routes->post('api/admin/login', 'Api\Login::adminLogin');
$routes->post('api/admin/logout', 'Api\Login::adminLogout');

$routes->post('api/admin/create', 'Api\Login::createAdmin');
$routes->get('api/admin/list', 'Api\Login::listAdmins');
$routes->get('api/admin/view/(:num)', 'Api\Login::getAdmin/$1');
$routes->post('api/admin/update', 'Api\Login::updateAdmin');
$routes->post('api/admin/delete', 'Api\Login::deleteAdmin');
$routes->post('api/admin/account-status', 'Api\Login::updateAdminUserStatus');


//user login
$routes->post('api/user/login', 'Api\AppUser::UserLogin');
$routes->post('api/user/verifyotp', 'Api\AppUser::verifyOtp');
$routes->post('api/admin/verifysocial', 'Api\AppUser::verifySocial');

//registration/user management
$routes->post('api/user/register', 'Api\AppUser::register');
$routes->post('api/user/getuser', 'Api\AppUser::getUserById');
$routes->post('api/user/getuser/admin', 'Api\AppUser::AdmingetUserById');
$routes->post('api/user/completeprofile', 'Api\AppUser::completeProfile');
$routes->post('api/update-user', 'Api\AppUser::updateUser');
$routes->post('api/user/delete', 'Api\AppUser::deleteUser');
$routes->get('api/user/list', 'Api\AppUser::listUsers'); // default (no search)
$routes->get('api/user/list/(:any)', 'Api\AppUser::listUsers/$1'); // with search term
$routes->post('api/user/profile-status', 'Api\AppUser::updateProfileStatus');
$routes->post('api/user/account-status', 'Api\AppUser::updateAccountStatus');



//Event Api 
$routes->get('api/events', 'Api\EventController::index');
$routes->get('api/events/admin/(:num)', 'Api\EventController::Adminshow/$1');
$routes->get('api/events/(:num)', 'Api\EventController::show/$1');
$routes->post('api/events/create', 'Api\EventController::create');
$routes->post('api/events/update', 'Api\EventController::update');
$routes->get('api/event/list', 'Api\EventController::listEvents');
$routes->get('api/event/list/(:any)', 'Api\EventController::listEvents/$1');
$routes->post('api/events/delete/(:num)', 'Api\EventController::delete/$1');


//Roles and Permissions 
$routes->get('api/roles', 'Api\RoleController::index');
$routes->get('api/roles/(:num)', 'Api\RoleController::show/$1');
$routes->post('api/roles/create', 'Api\RoleController::create');
$routes->post('api/roles/update', 'Api\RoleController::update');
$routes->delete('api/roles/delete', 'Api\RoleController::delete');


// Event Invites
$routes->get('api/event-invites/download','Api\ExcelDownload::downloadInvites');
$routes->post('api/event/invite/create', 'Api\EventInvite::createInvite');
$routes->post('api/event/invite/update-status', 'Api\EventInvite::updateInviteStatus');
$routes->post('api/event/invite/by-event', 'Api\EventInvite::getInvitesByEvent');
$routes->post('api/event/invite/by-user', 'Api\EventInvite::getInvitesByUser');
$routes->post('api/event/invite/expire-old', 'Api\EventInvite::expireOldInvites');
$routes->get('api/event/invites', 'Api\EventInvite::listInvites'); 
$routes->get('api/event/invites/(:any)', 'Api\EventInvite::listInvites/$1'); 
$routes->get('api/event/totalinvitescount', 'Api\EventInvite::getAllEventInviteCounts'); 





// Event Category
$routes->post('api/category/create', 'Api\EventCategory::createCategory');
$routes->post('api/category/event', 'Api\EventCategory::getCategoryByEvent');
$routes->post('api/category/update', 'Api\EventCategory::updatecategory');


// Event Bookings
$routes->get('api/event-bookings/download','Api\ExcelDownload::downloadBookings');
$routes->get('api/event/booking/list', 'Api\EventBooking::listBookings');
$routes->get('api/event/booking/list/(:any)', 'Api\EventBooking::listBookings/$1'); 
$routes->get('api/event/totalbookingscount', 'Api\EventBooking::getAllEventBookingCounts');
$routes->get('api/total-booking-counts/(:num)', 'Api\EventBooking::getTotalBookingCounts/$1');
$routes->post('api/booking/event', 'Api\EventBooking::getBookingsByEvent');
$routes->post('api/booking/user', 'Api\EventBooking::getBookingsByUser');
$routes->post('api/booking/cancel', 'Api\EventBooking::cancelBooking');


//checkin 
$routes->post('api/checkin/details', 'Api\Checkin::getCheckinDetails');
$routes->post('api/checkin/mark-in', 'Api\Checkin::markAsIn');
$routes->get('api/checkin/markin-list', 'Api\Checkin::listCheckins');
$routes->get('api/checkin/markin-list/(:num)', 'Api\Checkin::listCheckins/$1');
$routes->post('/api/remarks', 'Api\Checkin::getRemarks');



//Qr code generation and scanning 
$routes->post('api/generate-qr', 'Api\EventBooking::generateQrCode');
$routes->post('api/scan-qr', 'Api\EventBooking::scanQr');


// Get All Hosts
$routes->get('api/hosts', 'Api\Host::getAllHosts');
// Get host by ID
$routes->get('api/hosts/(:num)', 'Api\Host::getHostById/$1');
// Get all tags
$routes->get('api/tags', 'Api\EventTag::getAllTags');
// Get single tag by ID
$routes->get('api/tags/(:num)', 'Api\EventTag::getTagById/$1');
 
 
// Dashboard users count
$routes->get('api/dashboard/total-users', 'Api\Dashboard::getTotalUsers');
// Dashboard: Total Events Count
$routes->get('api/dashboard/total-events', 'Api\Dashboard::getTotalEvents');
// Dashboard - Upcoming Events Details
$routes->get('api/dashboard/upcoming-events', 'Api\Dashboard::getUpcomingEventsDetails');


// Payment Gateway Routes
#################################################################################################################################################
$routes->post('api/payment/create-order', 'Api\PaymentGateway\PaymentController::createOrder', ['filter' => 'PaymentFilter']);
$routes->get('api/payment/verify/(:any)', 'Api\PaymentGateway\PaymentController::verifyPayment/$1');
$routes->get('api/payment/status/(:any)', 'Api\PaymentGateway\PaymentController::getStatus/$1');
$routes->get('api/payment/failure/(:any)', 'Api\PaymentGateway\PaymentController::getFailureDetails/$1');
$routes->get('api/payment/callback', 'Api\PaymentGateway\PaymentController::callback');
$routes->post('api/payment/webhook', 'Api\PaymentGateway\PaymentController::webhook');

$routes->get('api/payment/failed', 'Api\PaymentGateway\PaymentController::failed');
$routes->get('api/payment/success', 'Api\PaymentGateway\PaymentController::success');

$routes->get('api/payment/manual-verify/(:any)', 'Api\PaymentGateway\PaymentController::manualVerify/$1');
$routes->get('api/payment/reconcile', 'Api\PaymentGateway\PaymentController::reconcilePayments');
$routes->post('api/payment/cleanup-abandoned', 'Api\PaymentGateway\PaymentController::cleanupAbandonedPayments');
$routes->get('api/payment/test-method/(:any)', 'Api\PaymentGateway\PaymentController::testPaymentMethod/$1');

// Transaction APIs
$routes->get('api/transactions', 'Api\PaymentGateway\PaymentReportController::getAllTransactions');
$routes->get('api/event-transactions', 'Api\PaymentGateway\PaymentReportController::getAllEventTransaction');
$routes->get('api/transactions/user/(:num)', 'Api\PaymentGateway\PaymentReportController::getUserTransactions/$1');
$routes->get('api/transactions/event/(:num)', 'Api\PaymentGateway\PaymentReportController::getEventTransactions/$1');

