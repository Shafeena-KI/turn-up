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

    protected function getAdminIdFromToken()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (!$authHeader) return null;

        $token = str_replace('Bearer ', '', $authHeader);
        if (!$token) return null;

        try {
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($key, 'HS256'));
            return $decoded->data->admin_id ?? null;
        } catch (\Throwable $e) {
            return null;
        }
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

    $booking = $this->bookingModel->where('booking_code', $booking_code)->first();
    if (!$booking) {
        return $this->response->setJSON([
            'status' => false,
            'message' => 'Invalid booking code'
        ]);
    }

    $invite = $this->inviteModel->find($booking['invite_id']);
    $event = $this->eventModel->find($booking['event_id']);
    $category = $this->categoryModel->find($booking['category_id']);
    $user = $this->userModel->find($booking['user_id']);

    // Prepare full URL for profile image
    $profileImage = '';
    if (!empty($user['profile_image'])) {
        if (!preg_match('/^https?:\/\//', $user['profile_image'])) {
            $profileImage = base_url('uploads/profile_images/' . $user['profile_image']);
        } else {
            $profileImage = $user['profile_image'];
        }
    }

    // -------------------------
    //  VALIDATIONS
    // -------------------------
    $today = date('Y-m-d');
    $eventDate = date('Y-m-d', strtotime($event['event_date'] ?? $today)); // assuming event_date exists

    // 1. Future event
    if ($today < $eventDate) {
        return $this->response->setJSON([
            'status' => false,
            'message' => 'Ticket is not valid for today\'s event'
        ]);
    }

    // 2. Past event
    if ($today > $eventDate) {
        // Check if user already checked in
        $db = db_connect();
        $checkin = $db->table('checkin')
            ->where('booking_code', $booking['booking_code'])
            ->get()
            ->getRowArray();

        if (!$checkin) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Ticket is expired'
            ]);
        }
    }

    // 3. Already checked in
    $db = db_connect();
    $checkin = $db->table('checkin')
        ->where('booking_code', $booking['booking_code'])
        ->where('entry_status', 1) // already checked in
        ->get()
        ->getRowArray();

    if ($checkin) {
        return $this->response->setJSON([
            'status' => false,
            'message' => "User already checked in. Checkin by {$checkin['checkedin_by']} at {$checkin['checkin_time']}"
        ]);
    }

    // -------------------------
    //  RETURN DETAILS
    // -------------------------
    return $this->response->setJSON([
        'status' => true,
        'message' => 'Details found',
        'data' => [
            'booking_id'    => $booking['booking_id'],
            'booking_code'  => $booking['booking_code'],
            'event_name'    => $event['event_name'] ?? '',
            'ticket_type'   => $category['category_name'] ?? '',
            'entry_type'    => $invite['entry_type'] ?? '',
            'user_name'     => $user['name'] ?? '',
            'profile_image' => $profileImage,
            'invite_id'     => $invite['invite_id'] ?? null,
            'partner'       => $invite['partner'] ?? null,
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

    $invite = $this->inviteModel->find($booking['invite_id']);

    // FIX HERE — Create DB connection
    $db = db_connect();
    $event = $db->table('events')->where('event_id', $booking['event_id'])->get()->getRowArray();

    $today = date('Y-m-d');
    $eventDate = date('Y-m-d', strtotime($event['event_date'] ?? $today));

    // ------------------------- VALIDATIONS -------------------------
    if ($today < $eventDate) {
        return $this->response->setJSON([
            'status' => false,
            'message' => "Ticket is not valid for today's event"
        ]);
    }

    if ($today > $eventDate) {
        $checkin = $db->table('checkin')
            ->where('booking_code', $booking['booking_code'])
            ->get()
            ->getRowArray();

        if (!$checkin) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Ticket is expired'
            ]);
        }
    }

    $checkin = $db->table('checkin')
        ->where('booking_code', $booking['booking_code'])
        ->where('entry_status', 1)
        ->get()
        ->getRowArray();

    if ($checkin) {
        return $this->response->setJSON([
            'status' => false,
            'message' => "User already checked in. Checkin by {$checkin['checkedin_by']} at {$checkin['checkin_time']}"
        ]);
    }

    // Partner comment logic
    $entry_type  = $invite['entry_type'];
    $partner_id  = $invite['partner'];
    $partner_in  = $data['partner'] ?? 0;
    $entry_comment = "";

    if ($entry_type == "Male" && $partner_id > 0) {
        if ($partner_in == 0) $entry_comment = "Female partner didn't come";
        elseif ($partner_in == 2) $entry_comment = "Female partner came, but booked male partner";
    }

    if ($entry_type == "Female" && $partner_id > 0) {
        if ($partner_in == 0) $entry_comment = "Male partner didn't come";
        elseif ($partner_in == 1) $entry_comment = "Male partner came, but booked female partner";
    }

    // $admin_id = $data['admin_id'] ?? 'admin_1';

    $admin_id = $this->getAdminIdFromToken();

if (!$admin_id) {
    return $this->response->setJSON([
        'status' => false,
        'message' => 'Unauthorized - Admin ID missing in token'
    ]);
}


    // Save check-in
    $checkinData = [
        'user_id'       => $booking['user_id'],
        'event_id'      => $booking['event_id'],
        'booking_code'  => $booking['booking_code'],
        'partner'       => $partner_in,
        'category_id'   => $booking['category_id'],
        'invite_id'     => $booking['invite_id'],
        'entry_status'  => 1,
        'checkin_time'  => date('Y-m-d H:i:s'),
        'checkedin_by'  => $admin_id,
        'entry_type'    => $entry_type,
        'entry_comment' => $entry_comment,
        'booking_id'    => $booking['booking_id']
    ];

    $db->table("checkin")->insert($checkinData);

    // Update booking status
    $this->bookingModel->update($booking['booking_id'], [
        'status' => 3,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    // Update event counts
    $counts = $this->eventCountsModel
        ->where('event_id', $booking['event_id'])
        ->where('category_id', $booking['category_id'])
        ->first();

    if ($counts) {
        $update = ['total_checkin' => $counts['total_checkin']];

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
            $update['total_checkin'] += 2;
        }

        $this->eventCountsModel->update($counts['id'], $update);
    }

    return $this->response->setJSON([
        'status' => true,
        'message' => 'Marked as IN successfully and booking status updated'
    ]);
}


    // -------------------------------------------------------------------
    // MARK AS OUT / CHECKOUT
    // -------------------------------------------------------------------
//    public function markAsOut()
// {
//     $data = $this->request->getJSON(true);

//     if (empty($data['booking_code'])) {
//         return $this->response->setJSON([
//             'status' => false,
//             'message' => 'booking_code is required'
//         ]);
//     }

//     // Verify booking
//     $booking = $this->bookingModel->where('booking_code', $data['booking_code'])->first();
//     if (!$booking) {
//         return $this->response->setJSON([
//             'status' => false,
//             'message' => 'Invalid booking code'
//         ]);
//     }

//     // Validate Admin Token
//     $admin_id = $this->getAdminIdFromToken();
//     if (!$admin_id) {
//         return $this->response->setJSON([
//             'status' => false,
//             'message' => 'Unauthorized - Admin ID missing in token'
//         ]);
//     }

//     // Get existing check-in
//     $db = db_connect();
//     $checkin = $db->table('checkin')
//         ->where('booking_code', $booking['booking_code'])
//         ->where('event_id', $booking['event_id'])
//         ->where('entry_status', 1)
//         ->get()
//         ->getRowArray();

//     if (!$checkin) {
//         return $this->response->setJSON([
//             'status' => false,
//             'message' => 'User has not checked in yet'
//         ]);
//     }

//     // Update checkout
//     $updateData = [
//         'checkout_time' => date('Y-m-d H:i:s'),
//         'checkout_by'   => $admin_id,   // FIXED HERE
//         'entry_status'  => 2
//     ];

//     $db->table('checkin')->where('checkin_id', $checkin['checkin_id'])->update($updateData);

//     return $this->response->setJSON([
//         'status' => true,
//         'message' => 'Checked out successfully'
//     ]);
// }




}
