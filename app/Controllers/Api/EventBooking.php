<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventTicketModel;
use CodeIgniter\HTTP\ResponseInterface;

class EventBooking extends BaseController
{
    protected $bookingModel;
    protected $inviteModel;
    protected $ticketModel;

    public function __construct()
    {
        $this->bookingModel = new EventBookingModel();
        $this->inviteModel = new EventInviteModel();
        $this->ticketModel = new EventTicketModel();
    }

    // ✅ Create Booking
    public function createBooking()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['user_id']) || empty($data['event_id']) || empty($data['ticket_id']) || empty($data['invite_id'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON([
                    'status' => false,
                    'message' => 'user_id, event_id, ticket_id, and invite_id are required.'
                ]);
        }

        // 1️⃣ Verify Invite
        $invite = $this->inviteModel
            ->where('invite_id', $data['invite_id'])
            ->where('status', 1) // only approved invites
            ->first();

        if (!$invite) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invite not approved or not found.'
            ]);
        }

        // 2️⃣ Check if user already booked
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

        // 3️⃣ Check Ticket Availability
        $ticket = $this->ticketModel->find($data['ticket_id']);
        if (!$ticket || $ticket['status'] != 1) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid or inactive ticket.'
            ]);
        }

        if ($ticket['balance_seats'] <= 0) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'No seats available for this ticket category.'
            ]);
        }

        // 4️⃣ Calculate Total & Create Booking
        $quantity = 1; // only 1 booking per user
        $total_price = $ticket['ticket_price'] * $quantity;

        $insertData = [
            'user_id' => $data['user_id'],
            'event_id' => $data['event_id'],
            'ticket_id' => $data['ticket_id'],
            'invite_id' => $data['invite_id'],
            'booking_type' => $data['booking_type'] ?? 'NORMAL',
            'total_price' => $total_price,
            'quantity' => $quantity,
            'status' => 1, // booked
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->bookingModel->insert($insertData);

        // 5️⃣ Update Ticket availability
        $this->ticketModel->update($data['ticket_id'], [
            'actual_booked_seats' => $ticket['actual_booked_seats'] + 1,
            'balance_seats' => $ticket['balance_seats'] - 1,
        ]);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Booking successful.',
            'data' => $insertData
        ]);
    }

    // ✅ Get all bookings by Event
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

    // ✅ Get all bookings by User
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

    // ✅ Cancel Booking
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
        $ticket = $this->ticketModel->find($booking['ticket_id']);
        if ($ticket) {
            $this->ticketModel->update($ticket['ticket_id'], [
                'actual_booked_seats' => max(0, $ticket['actual_booked_seats'] - 1),
                'balance_seats' => $ticket['balance_seats'] + 1,
            ]);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Booking cancelled successfully.'
        ]);
    }
}
