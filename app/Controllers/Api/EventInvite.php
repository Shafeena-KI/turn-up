<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventModel;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\AppUserModel;
use App\Models\Api\EventBookingModel;
use CodeIgniter\HTTP\ResponseInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
class EventInvite extends BaseController
{
    protected $inviteModel;
    protected $eventModel;
    protected $userModel;
    protected $bookingModel;
    protected $categoryModel;
    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->inviteModel = new EventInviteModel();
        $this->eventModel = new EventModel();
        $this->userModel = new AppUserModel();
        $this->bookingModel = new EventBookingModel();
        $this->categoryModel = new EventCategoryModel();
        $this->db = \Config\Database::connect();
    }
    // Create an invite
    public function createInvite()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['event_id']) || empty($data['user_id'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id and user_id are required.'
            ]);
        }

        // PROFILE STATUS VALIDATION

        $user = $this->db->table('app_users')
            ->select('profile_status')
            ->where('user_id', $data['user_id'])
            ->get()
            ->getRow();

        if (!$user || $user->profile_status == 0) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Please complete your profile before requesting an invite.'
            ]);
        }

        // FETCH EVENT

        $event = $this->db->table('events')
            ->where('event_id', $data['event_id'])
            ->get()
            ->getRow();

        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not available.'
            ]);
        }

        $event_code = $event->event_code;
        $event_id = $event->event_id;

        // CATEGORY VALIDATION

        $category = $this->db->table('event_ticket_category')
            ->where('event_id', $event_id)
            ->where('category_name', $data['category_name'])
            ->get()
            ->getRow();

        if (!$category) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Category not available for this event.'
            ]);
        }

        $category_id = $category->category_id;

        // DUPLICATE INVITE CHECK

        $exists = $this->inviteModel
            ->where(['event_id' => $event_id, 'user_id' => $data['user_id']])
            ->countAllResults();

        if ($exists > 0) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'You already placed an invite request.'
            ]);
        }

        // ENTRY TYPE CALCULATIONS AND NUMERIC MAPPING

        $entryType = strtolower($data['entry_type']);

        $invite_total = 0;
        $male_total = 0;
        $female_total = 0;
        $other_total = 0;
        $couple_total = 0;
        $entryTypeValue = null;
        $entryTypeText = null;

        switch ($entryType) {
            case 'male':
                $entryTypeValue = 1;
                $entryTypeText = 'Male';
                $invite_total = 1;
                $male_total = 1;
                break;

            case 'female':
                $entryTypeValue = 2;
                $entryTypeText = 'Female';
                $invite_total = 1;
                $female_total = 1;
                break;

            case 'other':
                $entryTypeValue = 3;
                $entryTypeText = 'Other';
                $invite_total = 1;
                $other_total = 1;
                break;

            case 'couple':
                if (empty($data['partner']) || trim($data['partner']) == '') {
                    return $this->response->setJSON([
                        'status' => false,
                        'message' => 'Partner Insta ID is required for Couple entry.'
                    ]);
                }

                $entryTypeValue = 4;
                $entryTypeText = 'Couple';
                $invite_total = 2;
                $couple_total = 1;
                break;


            default:
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Invalid entry_type. Allowed: Male, Female, Other, Couple'

                ]);
        }
        $entryLabels = [1 => 'Male', 2 => 'Female', 3 => 'Other', 4 => 'Couple'];
        $entryTypeText = $entryLabels[$entryTypeValue];

        // Convert payload category_name (1 = VIP, 2 = Normal)
        $inputCategory = (int) $data['category_name'];

        $category_id = null;
        $categoryText = null;

        if ($inputCategory === 1) {
            $category_id = 1;
            $categoryText = 'VIP';
        } elseif ($inputCategory === 2) {
            $category_id = 2;
            $categoryText = 'Normal';
        } else {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid category_name. Use 1 for VIP, 2 for Normal.'
            ]);
        }

        // VIP AUTO APPROVE

        $inviteStatus = ($category_id == 1) ? 1 : 0;

        // event_counters (ONE GLOBAL COUNTER)

        $counterTable = $this->db->table('event_counters');
        $counter = $counterTable->get()->getRow();

        if ($counter) {
            $new_invite_no = $counter->last_invite_no + 1;
            $counterTable->where('id', $counter->id)->update([
                'last_invite_no' => $new_invite_no,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            $new_invite_no = 1;
            $counterTable->insert([
                'last_invite_no' => 1,
                'last_booking_no' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // FINAL INVITE CODE (EVENTCODE + IN + 001)

        $invite_code = 'IN' . $event_code . str_pad($new_invite_no, 3, '0', STR_PAD_LEFT);

        // SAVE INVITE
        $insertData = [
            'event_id' => $event_id,
            'user_id' => $data['user_id'],
            'category_id' => $category_id,
            'entry_type' => $entryTypeValue, // <-- Save numeric value
            'partner' => $data['partner'] ?? null,
            'invite_code' => $invite_code,
            'status' => $inviteStatus,
            'requested_at' => date('Y-m-d H:i:s'),
        ];

        $invite_id = $this->inviteModel->insert($insertData);

        // Response should show text name instead of number
        $insertData['entry_type'] = $entryTypeText;

        $insertData['category_name'] = $categoryText;
        // UPDATE event_counts INVITES

        $countsTable = $this->db->table('event_counts');

        $eventCount = $countsTable
            ->where('event_id', $event_id)
            ->where('category_id', $category_id)
            ->get()
            ->getRow();

        if ($eventCount) {
            $countsTable->where('id', $eventCount->id)->update([
                'total_invites' => $eventCount->total_invites + $invite_total,
                'total_male_invites' => $eventCount->total_male_invites + $male_total,
                'total_female_invites' => $eventCount->total_female_invites + $female_total,
                'total_other_invites' => $eventCount->total_other_invites + $other_total,
                'total_couple_invites' => $eventCount->total_couple_invites + $couple_total,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            $countsTable->insert([
                'event_id' => $event_id,
                'category_id' => $category_id,
                'total_invites' => $invite_total,
                'total_male_invites' => $male_total,
                'total_female_invites' => $female_total,
                'total_other_invites' => $other_total,
                'total_couple_invites' => $couple_total,
                'total_booking' => 0,
                'total_male_booking' => 0,
                'total_female_booking' => 0,
                'total_other_booking' => 0,
                'total_couple_booking' => 0,
                'total_checkin' => 0,
                'total_male_checkin' => 0,
                'total_female_checkin' => 0,
                'total_other_checkin' => 0,
                'total_couple_checkin' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
            $qr_url = null;
        // AUTO BOOKING FOR VIP
        if ($inviteStatus == 1) {

            // GENERATE BOOKING CODE (EVENTCODE + B1001)

            $counter = $counterTable->get()->getRow();

            $new_booking_no = $counter->last_booking_no + 1;

            // update counter
            $counterTable->where('id', $counter->id)->update([
                'last_booking_no' => $new_booking_no,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            $booking_code = $event_code . 'B' . str_pad($new_booking_no, 3, '0', STR_PAD_LEFT);

            // SAVE BOOKING
            $bookingData = [
                'invite_id' => $invite_id,
                'event_id' => $event_id,
                'user_id' => $data['user_id'],
                'category_id' => $category_id,
                'booking_code' => $booking_code,
                'total_price' => $category->price,
                'quantity' => $invite_total,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $this->db->table('event_booking')->insert($bookingData);

            // UPDATE BOOKING COUNTS
            $eventCount = $countsTable
                ->where('event_id', $event_id)
                ->where('category_id', $category_id)
                ->get()
                ->getRow();

            $countsTable->where('id', $eventCount->id)->update([
                'total_booking' => $eventCount->total_booking + $invite_total,
                'total_male_booking' => $eventCount->total_male_booking + $male_total,
                'total_female_booking' => $eventCount->total_female_booking + $female_total,
                'total_other_booking' => $eventCount->total_other_booking + $other_total,
                'total_couple_booking' => $eventCount->total_couple_booking + $couple_total,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
             // ========= AUTO GENERATE QR CODE 
            $qr_url = $this->createQrForBooking($booking_code);

            // GET USER DETAILS
            $user = $this->db->table('app_users')->where('user_id', $data['user_id'])->get()->getRow();

            // CALL WHATSAPP SEND FUNCTION
            $this->sendEventConfirmation(
                $user->phone,
                $user->name,
                $event->event_name
            );
        }
        // SEND WHATSAPP INVITE CONFIRMATION MESSAGE ONLY FOR NORMAL INVITES
        $whatsappResponse = null;

        if ($inviteStatus == 0) {
            $user = $this->db->table('app_users')
                ->where('user_id', $data['user_id'])
                ->get()
                ->getRow();

            $whatsappResponse = $this->sendInviteConfirmation(
                $user->phone,
                $user->name,
                $event->event_name
            );
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite created successfully.',
            'invite_code' => $invite_code,
            'data' => $insertData,
            'qr_code' => $qr_url,
            'whatsapp_response' => $whatsappResponse,
            'vip_auto_approved' => ($inviteStatus == 1)
        ]);
    }
    private function sendInviteConfirmation($phone, $name, $event_name)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/690e02d83dcbb55508455c59/webhook/execute";

        if (strpos($phone, '+91') !== 0) {
            $phone = '+91' . ltrim($phone, '0');
        }
        // SIMPLE PAYLOAD SAME AS OTP 
        $payload = [
            "phone" => $phone,
            "name" => "Test",
            "username" => $name,
            "event_name" => $event_name
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
    private function sendEventConfirmation($phone, $name, $event_name)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/690e02d83dcbb55508455c59/webhook/execute";

        if (strpos($phone, '+91') !== 0) {
            $phone = '+91' . ltrim($phone, '0');
        }

        $payload = [
            "phone" => $phone,
            "username" => $name,
            "event_name" => $event_name,
            "template" => "event_request_approval_v2" // IMPORTANT!
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
    public function listInvites($event_id = null, $search = null)
    {
        $page = (int) $this->request->getGet('current_page') ?: 1;
        $limit = (int) $this->request->getGet('per_page') ?: 10;
        $search = $search ?: ($this->request->getGet('keyword') ?? $this->request->getGet('search'));
        $offset = ($page - 1) * $limit;

        // Join with events, categories, users and event_counts
        $builder = $this->inviteModel
            ->select("
        event_invites.*,
        events.event_name,
        events.event_city,
        event_ticket_category.category_name,
        app_users.name,
        app_users.phone,
        app_users.email,
        app_users.insta_id,
        app_users.profile_image,
        ec.total_invites,
        ec.total_male_invites,
        ec.total_female_invites,
        ec.total_other_invites,
        ec.total_other_invites,
        ec.total_couple_invites
    ")
            ->join('events', 'events.event_id = event_invites.event_id', 'left')
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_invites.category_id', 'left')
            ->join('app_users', 'app_users.user_id = event_invites.user_id', 'left')
            ->join(
                'event_counts ec',
                'ec.event_id = event_invites.event_id 
         AND ec.id = (SELECT MAX(id) FROM event_counts WHERE event_id = event_invites.event_id)',
                'left'
            )
            ->where('event_invites.status !=', 4);
        if (!empty($event_id)) {
            $builder->where('event_invites.event_id', $event_id);
        }
        // Search filter
        if (!empty($search)) {
            $builder->groupStart()
                ->like('events.event_name', $search)
                ->orLike('events.event_city', $search)
                ->orLike('app_users.name', $search)
                ->orLike('app_users.phone', $search)
                ->orLike('app_users.email', $search)
                ->orLike('event_ticket_category.category_name', $search)
                ->groupEnd();
        }

        // Total count
        $total = $builder->countAllResults(false);

        // Fetch paginated results
        $invites = $builder
            ->orderBy('event_invites.invite_id', 'DESC')
            ->findAll($limit, $offset);

        foreach ($invites as &$invite) {

            // Category text
            $invite['category_text'] = $invite['category_name'] ?? 'No Category';

            // Status text
            $statusMap = [
                0 => 'Pending',
                1 => 'Approved',
                2 => 'Rejected',
                3 => 'Expired'
            ];
            $invite['status_text'] = $statusMap[$invite['status']] ?? 'Unknown';

            // Entry type mapping
            $entryTypeMap = [
                1 => 'Male',
                2 => 'Female',
                3 => 'Other',
                4 => 'Couple'
            ];

            $invite['entry_type_text'] = $entryTypeMap[$invite['entry_type']] ?? 'N/A';

            $invite['partner'] = $invite['partner'] ?? null; // Show only Insta ID
            unset($invite['partner_details']); // Ensure partner_details is removed

            $imageBaseUrl = base_url('uploads/profile_images/');
            // Full Profile Image URL for main user
            $invite['profile_image'] = !empty($invite['profile_image'])
                ? $imageBaseUrl . $invite['profile_image']
                : null;

            // NEW: Event total counts from event_counts table 
            $invite['event_counts'] = [
                'total_invites' => (int) $invite['total_invites'],
                'total_male_invites' => (int) $invite['total_male_invites'],
                'total_female_invites' => (int) $invite['total_female_invites'],
                'total_other_invites' => (int) $invite['total_other_invites'],
                'total_couple_invites' => (int) $invite['total_couple_invites'],
            ];
        }

        $totalPages = ceil($total / $limit);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $limit,
                'keyword' => $search,
                'total_records' => $total,
                'total_pages' => $totalPages,
                'invites' => $invites
            ]
        ]);
    }
    // Approve or Reject Invite (manual)

    public function updateInviteStatus()
    {
        $data = $this->request->getJSON(true);
        $invite_id = $data['invite_id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$invite_id || !in_array($status, [1, 2])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'invite_id and valid status (1=approved, 2=rejected) are required.'
            ]);
        }

        // Fetch invite
        $invite = $this->inviteModel->find($invite_id);
        if (!$invite) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invite not found.'
            ]);
        }

        // Update status
        $this->inviteModel->update($invite_id, [
            'status' => $status,
            'approved_at' => ($status == 1) ? date('Y-m-d H:i:s') : null
        ]);

        $booking_code = null;

        // === ONLY WHEN APPROVED === //
        if ($status == 1) {

            // Fetch user data for WhatsApp
            $user = $this->userModel->find($invite['user_id']);
            $phone = $user['phone'];
            $username = $user['name'];

            $event = $this->eventModel->find($invite['event_id']);
            $event_name = $event['event_name'];

            // ALREADY BOOKED?
            $existing = $this->bookingModel
                ->where('invite_id', $invite_id)
                ->first();

            if ($existing) {
                $booking_code = $existing['booking_code'];

            } else {
                // Create booking (same logic as before)
                $category_id = $invite['category_id'];
                $category = $this->categoryModel->find($category_id);
                $price = $category['price'] ?? 0;

                $male_total = $female_total = $other_total = $couple_total = 0;

                switch ((int) $invite['entry_type']) {
                    case 1: // Male
                        $male_total = 1;
                        break;

                    case 2: // Female 
                        $female_total = 1;
                        break;
                    case 3:  // other
                        $other_total = 1;
                        break;
                    case 4: // Couple
                        $couple_total = 2;
                        break;

                    default:
                        return $this->response->setJSON([
                            'status' => false,
                            'message' => 'Invalid entry type.'
                        ]);
                }

                $invite_total = $male_total + $female_total + $other_total + ($couple_total * 2);


                // COUNTER GENERATION
                $counterTable = $this->db->table('event_counters');
                $counter = $counterTable->get()->getRow();

                if (!$counter) {
                    $counterTable->insert([
                        'last_booking_no' => 0,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $counter = $counterTable->get()->getRow();
                }

                $new_booking_no = $counter->last_booking_no + 1;

                $counterTable->where('id', $counter->id)->update([
                    'last_booking_no' => $new_booking_no,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $event_code = $event['event_code'] ?? 'EVT';
                $booking_code = 'BK' . $event_code . str_pad($new_booking_no, 3, '0', STR_PAD_LEFT);

                // INSERT BOOKING
                $this->bookingModel->insert([
                    'invite_id' => $invite_id,
                    'event_id' => $invite['event_id'],
                    'user_id' => $invite['user_id'],
                    'category_id' => $category_id,
                    'booking_code' => $booking_code,
                    'total_price' => $price,
                    'quantity' => $invite_total,
                    'status' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
            // ========= AUTO GENERATE QR CODE 
            $qr_url = $this->createQrForBooking($booking_code);

            // === SEND WHATSAPP === //
            $this->sendEventConfirmation($phone, $username, $event_name);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite updated successfully.',
            'booking_code' => $booking_code,
            'qr_code' => $qr_url
        ]);
    }

    private function createQrForBooking($booking_code)
    {
        // Fetch booking
        $booking = $this->bookingModel->where('booking_code', $booking_code)->first();
        if (!$booking) {
            return null;
        }

        $secretKey = getenv('EVENT_QR_SECRET');
        $token = hash_hmac('sha256', $booking_code, $secretKey);

        $payload = json_encode([
            'booking_code' => $booking_code,
            'token' => $token
        ]);

        // PUBLIC FOLDER
        $qrFolder = FCPATH . 'public/uploads/qr_codes/';

        if (!is_dir($qrFolder)) {
            mkdir($qrFolder, 0777, true);
        }

        $fileName = $booking_code . '.png';
        $filePath = $qrFolder . $fileName;
        $qrUrl = base_url('public/uploads/qr_codes/' . $fileName);

        // Generate QR
        $qrCode = new \Endroid\QrCode\QrCode($payload);
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $writer->write($qrCode)->saveToFile($filePath);

        // Save in database
        $this->bookingModel->update($booking['booking_id'], [
            'qr_code' => $qrUrl
        ]);

        return $qrUrl;
    }

    public function getInvitesByEvent()
    {
        $json = $this->request->getJSON(true);
        $event_id = $json['event_id'] ?? null;

        if (!$event_id) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required.'
            ]);
        }

        $invites = $this->inviteModel->getInvitesByEvent($event_id);
        return $this->response->setJSON([
            'status' => true,
            'data' => $invites
        ]);
    }
    public function getInvitesByUser()
    {
        $json = $this->request->getJSON(true);
        $user_id = $json['user_id'] ?? null;
        if (!$user_id) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'user_id is required.'
            ]);
        }

        $invites = $this->inviteModel->getInvitesByUser($user_id);
        return $this->response->setJSON([
            'status' => true,
            'data' => $invites
        ]);
    }
    public function getAllEventInviteCounts()
    {
        $builder = $this->db->table('event_counts ec');
        $builder->select("
            ec.event_id,
            e.event_name,
            e.event_code,
            e.event_location,
            e.event_city,
            e.event_date_start,
            e.event_time_start,
            e.event_date_end,
            e.event_time_end,
            c.category_id,
            c.category_name,
            c.total_seats,
            SUM(ec.total_invites) AS total_invites,
            SUM(ec.total_male_invites) AS total_male,
            SUM(ec.total_female_invites) AS total_female,
            SUM(ec.total_other_invites) AS total_other,
            SUM(ec.total_couple_invites) AS total_couple
        ");
        $builder->join('events e', 'e.event_id = ec.event_id', 'left');
        $builder->join('event_ticket_category c', 'c.category_id = ec.category_id', 'left');
        $builder->groupBy('ec.event_id, ec.category_id');

        $rows = $builder->get()->getResultArray();
        $finalData = [];

        foreach ($rows as $row) {

            $eventId = $row['event_id'];
            $categoryKey = strtolower($row['category_name']);

            if (!isset($finalData[$eventId])) {
                $finalData[$eventId] = [
                    'event_id' => $eventId,
                    'event_name' => $row['event_name'],
                    'event_code' => $row['event_code'],
                    'event_location' => $row['event_location'],
                    'event_city' => $row['event_city'],
                    'event_date_start' => $row['event_date_start'],
                    'event_time_start' => $row['event_time_start'],
                    'event_date_end' => $row['event_date_end'],
                    'event_time_end' => $row['event_time_end'],

                    'categories' => [],
                    'overall_total' => [
                        'total_seats' => 0,
                        'total_invites' => 0,
                        'total_male' => 0,
                        'total_female' => 0,
                        'total_other' => 0,
                        'total_couple' => 0,
                    ]
                ];
            }

            // CATEGORY WISE DATA
            $finalData[$eventId]['categories'][$categoryKey] = [
                'seats' => (int) $row['total_seats'],
                'total_invites' => (int) $row['total_invites'],
                'total_male' => (int) $row['total_male'],
                'total_female' => (int) $row['total_female'],
                'total_other' => (int) $row['total_other'],
                'total_couple' => (int) $row['total_couple'],
            ];

            // OVERALL TOTAL CALCULATION
            $finalData[$eventId]['overall_total']['total_seats'] += (int) $row['total_seats'];
            $finalData[$eventId]['overall_total']['total_invites'] += (int) $row['total_invites'];
            $finalData[$eventId]['overall_total']['total_male'] += (int) $row['total_male'];
            $finalData[$eventId]['overall_total']['total_female'] += (int) $row['total_female'];
            $finalData[$eventId]['overall_total']['total_other'] += (int) $row['total_other'];
            $finalData[$eventId]['overall_total']['total_couple'] += (int) $row['total_couple'];
        }

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'All event invite counts fetched successfully',
            'data' => array_values($finalData)
        ]);
    }
    // Expire old invites automatically (example endpoint)
    public function expireOldInvites()
    {
        // Define the conditions
        $conditions = [
            'status' => 0,
            'requested_at <' => date('Y-m-d H:i:s', strtotime('-7 days'))
        ];

        // Count matching invites first
        $count = $this->inviteModel->where($conditions)->countAllResults();

        // Then update them
        if ($count > 0) {
            $this->inviteModel->where($conditions)->set(['status' => 3])->update();
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => $count . ' pending invite(s) expired successfully.'
        ]);
    }
}
