<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventCategoryModel;
use CodeIgniter\HTTP\ResponseInterface;

class EventBooking extends BaseController
{
    protected $bookingModel;
    protected $inviteModel;
    protected $categoryModel;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->bookingModel = new EventBookingModel();
        $this->inviteModel = new EventInviteModel();
        $this->categoryModel = new EventCategoryModel();
    }

    // Create Booking
    public function createBooking()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['user_id']) || empty($data['event_id']) || empty($data['category_id']) || empty($data['invite_id'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON([
                    'status' => false,
                    'message' => 'user_id, event_id, category_id, and invite_id are required.'
                ]);
        }

        // Verify Approved Invite
        $invite = $this->inviteModel
            ->where('invite_id', $data['invite_id'])
            ->where('status', 1)
            ->first();

        if (!$invite) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invite not approved or not found.'
            ]);
        }

        // Check duplicate booking
        $existing = $this->bookingModel
            ->where('user_id', $data['user_id'])
            ->where('event_id', $data['event_id'])
            ->where('status !=', 2) // not cancelled
            ->first();

        if ($existing) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'User already booked for this event.'
            ]);
        }

        // Check Ticket Category Availability
        $category = $this->categoryModel->find($data['category_id']);
        if (!$category || $category['status'] != 1) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid or inactive ticket category.'
            ]);
        }

        if ($category['balance_seats'] <= 0) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'No seats available for this ticket category.'
            ]);
        }

        // One booking = 1 quantity
        $quantity = 1;
        $total_price = $category['price'] * $quantity;

        // Automatically set booking_type
        $bookingType = "OFFLINE";  // fixed for now

        // Save Booking
        $insertData = [
            'user_id' => $data['user_id'],
            'event_id' => $data['event_id'],
            'category_id' => $data['category_id'],
            'invite_id' => $data['invite_id'],
            'booking_type' => $bookingType, // no user input allowed
            'total_price' => $total_price,
            'quantity' => $quantity,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->bookingModel->insert($insertData);

        // Reduce Available Seat
        $this->categoryModel->update($data['category_id'], [
            'actual_booked_seats' => $category['actual_booked_seats'] + 1,
            'balance_seats' => $category['balance_seats'] - 1,
        ]);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Booking successful.',
            'data' => $insertData
        ]);
    }


    // Get all bookings by Event
    public function getBookingsByEvent()
    {
        $data = $this->request->getJSON(true);
        $event_id = $data['event_id'] ?? null;

        if (empty($event_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required.'
            ]);
        }

        $bookings = $this->bookingModel->where('event_id', $event_id)->findAll();

        return $this->response->setJSON([
            'status' => true,
            'data' => $bookings
        ]);
    }

    // Get all bookings by User
    public function getBookingsByUser()
    {
        $data = $this->request->getJSON(true);
        $user_id = $data['user_id'] ?? null;

        if (empty($user_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'user_id is required.'
            ]);
        }

        $bookings = $this->bookingModel->where('user_id', $user_id)->findAll();

        return $this->response->setJSON([
            'status' => true,
            'data' => $bookings
        ]);
    }

    // Cancel Booking
    public function cancelBooking()
    {
        $data = $this->request->getJSON(true);
        $booking_id = $data['booking_id'] ?? null;

        if (empty($booking_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_id is required.'
            ]);
        }

        $booking = $this->bookingModel->find($booking_id);
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Booking not found.'
            ]);
        }

        if ($booking['status'] == 2) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Booking already cancelled.'
            ]);
        }

        // Update booking
        $this->bookingModel->update($booking_id, [
            'status' => 2,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Restore seat to ticket
        $category = $this->categoryModel->find($booking['category_id']);
        if ($category) {
            $this->categoryModel->update($category['category_id'], [
                'actual_booked_seats' => max(0, $category['actual_booked_seats'] - 1),
                'balance_seats' => $category['balance_seats'] + 1,
            ]);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Booking cancelled successfully.'
        ]);
    }
}
