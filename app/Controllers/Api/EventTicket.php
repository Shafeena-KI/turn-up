<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventTicketModel;
use CodeIgniter\HTTP\ResponseInterface;

class EventTicket extends BaseController
{
    protected $ticketModel;

    public function __construct()
    {
        $this->ticketModel = new EventTicketModel();
    }

    /**
     * Create Ticket
     */
    public function createTicket()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['event_id']) || empty($data['ticket_category']) || empty($data['total_seats']) || empty($data['ticket_price'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON([
                    'status' => false,
                    'message' => 'event_id, ticket_category, total_seats and ticket_price are required.'
                ]);
        }

        $actual_booked_seats = isset($data['actual_booked_seats']) ? (int) $data['actual_booked_seats'] : 0;
        $dummy_booked_seats = isset($data['dummy_booked_seats']) ? (int) $data['dummy_booked_seats'] : 0;
        $total_seats = (int) $data['total_seats'];

        // ✅ Calculate balance seats
        $balance_seats = $total_seats - ($actual_booked_seats + $dummy_booked_seats);
        if ($balance_seats < 0)
            $balance_seats = 0; // prevent negative

        $insertData = [
            'event_id' => $data['event_id'],
            'ticket_category' => strtoupper($data['ticket_category']),
            'total_seats' => $total_seats,
            'actual_booked_seats' => $actual_booked_seats,
            'dummy_booked_seats' => $dummy_booked_seats,
            'balance_seats' => $balance_seats,
            'ticket_price' => $data['ticket_price'],
            'status' => 1, // active by default
        ];

        $ticket_id = $this->ticketModel->insert($insertData);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Ticket created successfully.',
            'data' => array_merge(['ticket_id' => $ticket_id], $insertData)
        ]);
    }


    /**
     * Get Tickets by Event ID
     */
    public function getTicketsByEvent()
    {
        $data = $this->request->getJSON(true);
        $event_id = $data['event_id'] ?? null;

        if (empty($event_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required.'
            ]);
        }

        $tickets = $this->ticketModel->where('event_id', $event_id)->findAll();

        if (empty($tickets)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'No tickets found for this event.'
            ]);
        }

        return $this->response->setJSON([
            'status' => true,
            'data' => $tickets
        ]);
    }


    /**
     * Update Ticket
     */
    public function updateTicket()
    {
        $data = $this->request->getJSON(true);
        $ticket_id = $data['ticket_id'] ?? null;

        if (empty($ticket_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'ticket_id is required.'
            ]);
        }

        $ticket = $this->ticketModel->find($ticket_id);
        if (!$ticket) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Ticket not found.'
            ]);
        }

        $updateData = [];

        // ✅ Allow updates for all fields
        foreach (['ticket_category', 'total_seats', 'ticket_price', 'status', 'actual_booked_seats', 'dummy_booked_seats'] as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        // ✅ Calculate balance seats accurately
        $total_seats = $updateData['total_seats'] ?? $ticket['total_seats'];
        $actual_booked_seats = $updateData['actual_booked_seats'] ?? $ticket['actual_booked_seats'];
        $dummy_booked_seats = $updateData['dummy_booked_seats'] ?? $ticket['dummy_booked_seats'];

        $balance_seats = $total_seats - ($actual_booked_seats + $dummy_booked_seats);
        if ($balance_seats < 0)
            $balance_seats = 0;

        $updateData['balance_seats'] = $balance_seats;

        $this->ticketModel->update($ticket_id, $updateData);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Ticket updated successfully.',
            'data' => array_merge(['ticket_id' => $ticket_id], $updateData)
        ]);
    }


    /**
     * Delete Ticket
     */
    public function deleteTicket()
    {
        $data = $this->request->getJSON(true);
        $ticket_id = $data['ticket_id'] ?? null;

        if (empty($ticket_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'ticket_id is required.'
            ]);
        }

        $ticket = $this->ticketModel->find($ticket_id);
        if (!$ticket) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Ticket not found.'
            ]);
        }

       $this->ticketModel->update($ticket_id, ['status' => 4]);
        return $this->response->setJSON([
            'status' => true,
            'message' => 'Ticket deleted successfully.'
        ]);
    }

}
