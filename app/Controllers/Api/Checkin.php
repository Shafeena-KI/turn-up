<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventModel;
use App\Models\Api\EventCountsModel;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\AppUserModel;

use CodeIgniter\HTTP\ResponseInterface;

class Checkin extends BaseController
{
    protected $inviteModel;
    protected $bookingModel;
    protected $eventModel;
    protected $categoryModel;
    protected $userModel;
    

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");

        $this->inviteModel   = new EventInviteModel();
        $this->bookingModel  = new EventBookingModel();
        $this->eventModel    = new EventModel();
        $this->categoryModel = new EventCategoryModel();
        $this->userModel     = new AppUserModel();
        $this->eventCountsModel  = new EventCountsModel();

    }

    // -------------------------------------------------------------------
    //  GET DETAILS USING BOOKING CODE
    // -------------------------------------------------------------------
    public function getCheckinDetails()
    {
        $data = $this->request->getJSON(true);
        $booking_code = $data['booking_code'] ?? null;

        if (!$booking_code) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_code is required'
            ]);
        }

        // Find booking
        $booking = $this->bookingModel->where('booking_code', $booking_code)->first();
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid booking code'
            ]);
        }

        // Find invite (important)
        $invite = $this->inviteModel->find($booking['invite_id']);

        // Event
        $event = $this->eventModel->find($booking['event_id']);

        // Category
        $category = $this->categoryModel->find($booking['category_id']);

        // User
        $user = $this->userModel->find($booking['user_id']);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Details found',
            'data' => [
                'booking_id'   => $booking['booking_id'],
                'booking_code' => $booking['booking_code'],
                'event_name'   => $event['event_name'] ?? '',
                'ticket_type'  => $category['category_name'] ?? '',
                'entry_type'   => $invite['entry_type'] ?? '',
                'user_name'    => $user['name'] ?? '',
                'profile_image'  => $user['profile_image'] ?? '', 
                'invite_id'    => $invite['invite_id'] ?? null,
                'partner'      => $invite['partner'] ?? null,
            ]
        ]);
    }

    // -------------------------------------------------------------------
    // MARK AS IN
    // -------------------------------------------------------------------

    public function markAsIn()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['booking_code'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_code is required'
            ]);
        }

        // Get Booking
        $booking = $this->bookingModel->where('booking_code', $data['booking_code'])->first();
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid booking code'
            ]);
        }

        // Get Invite
        $invite = $this->inviteModel->find($booking['invite_id']);

        $entry_type  = $invite['entry_type'];     // Male / Female / Couple
        $partner_id  = $invite['partner'];        // Partner ID
        $partner_in  = $data['partner'] ?? 0;     // Who came (0-no partner, 1-male, 2-female)

        //----------------------------------------------
        //  PARTNER COMMENT LOGIC
        //----------------------------------------------
        $entry_comment = "";

        if ($entry_type == "Male" && $partner_id > 0) {
            if ($partner_in == 0) {
                $entry_comment = "Female partner didn't come";
            } elseif ($partner_in == 2) {
                $entry_comment = "Female partner came, but booked male partner";
            }
        }

        if ($entry_type == "Female" && $partner_id > 0) {
            if ($partner_in == 0) {
                $entry_comment = "Male partner didn't come";
            } elseif ($partner_in == 1) {
                $entry_comment = "Male partner came, but booked female partner";
            }
        }

        //----------------------------------------------
        //  SAVE CHECK-IN
        //----------------------------------------------
        $checkinData = [
            'user_id'       => $booking['user_id'],
            'event_id'      => $booking['event_id'],
            'booking_code'  => $booking['booking_code'],
            'partner'       => $partner_in,
            'category_id'   => $booking['category_id'],
            'invite_id'     => $booking['invite_id'],
            'entry_status'  => 1,
            'checkin_time'  => date('Y-m-d H:i:s'),
            'checkedin_by'  => $data['checkedin_by'] ?? 'security_1',
            'entry_type'    => $entry_type,
            'entry_comment' => $entry_comment,
            'booking_id'    => $booking['booking_id']
        ];

        $db = db_connect();
        $db->table("checkin")->insert($checkinData);

        //----------------------------------------------
        //  EVENT COUNTS UPDATE
        //----------------------------------------------
        $counts = $this->eventCountsModel
            ->where('event_id', $booking['event_id'])
            ->where('category_id', $booking['category_id'])
            ->first();

        if ($counts) {

            $update = [];

            // Default total_checkin
            $update['total_checkin'] = $counts['total_checkin'];

            if ($entry_type == "Male") {
                $update['total_male_checkin'] = $counts['total_male_checkin'] + 1;
                $update['total_checkin'] += 1;
            }

            if ($entry_type == "Female") {
                $update['total_female_checkin'] = $counts['total_female_checkin'] + 1;
                $update['total_checkin'] += 1;
            }

            if ($entry_type == "Couple") {
                $update['total_couple_checkin'] = $counts['total_couple_checkin'] + 1;
                $update['total_checkin'] += 2;   // Couple = 2 persons
            }

            $this->eventCountsModel->update($counts['id'], $update);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Marked as IN successfully'
        ]);
    }


}
