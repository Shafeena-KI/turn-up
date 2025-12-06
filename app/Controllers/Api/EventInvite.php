<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventModel;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\AppUserModel;
use App\Models\Api\EventBookingModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\API\ResponseTrait;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
    private function getAuthenticatedUser()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return ['error' => 'Authorization token missing'];
        }

        $token = $matches[1];
        $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

        try {
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($key, 'HS256'));

            return [
                'user_id' => $decoded->data->user_id,
                'phone' => $decoded->data->phone
            ];

        } catch (\Throwable $e) {
            return ['error' => 'Invalid or expired token: ' . $e->getMessage()];
        }
    }
    // Create an invite
    public function createInvite()
    {
        $db = $this->db;

        try {
            $auth = $this->getAuthenticatedUser();

            if (isset($auth['error'])) {
                return $this->response
                    ->setStatusCode(401)
                    ->setJSON([
                        'status' => 401,
                        'success' => false,
                        'message' => 'Invalid or expired token.'
                    ]);
            }

            $tokenUserId = $auth['user_id']; // token user (unused in original logic but kept)

            $data = $this->request->getJSON(true);

            if (empty($data['event_id']) || empty($data['user_id'])) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'event_id and user_id are required.'
                ]);
            }

            // PROFILE STATUS VALIDATION
            $user = $db->table('app_users')
                ->select('profile_status, phone, name')
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
            $event = $db->table('events')
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

            // INPUT: category_name must be provided in payload (1 = VIP, 2 = Normal)
            if (!isset($data['category_name'])) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'category_name is required (1 = VIP, 2 = Normal).'
                ]);
            }

            $inputCategory = (int) $data['category_name'];

            // FETCH CATEGORY BASED ON event_id + category_name
            $category = $db->table('event_ticket_category')
                ->where('event_id', $event_id)
                ->where('category_name', $inputCategory)
                ->get()
                ->getRow();

            if (!$category) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Category not available for this event.'
                ]);
            }

            // Use the real FK id and readable text
            $category_id = $category->category_id;               // PK used as FK
            $categoryType = (int) $category->category_name;      // 1 = VIP, 2 = Normal
            $categoryText = ($categoryType === 1) ? 'VIP' : 'Normal';

            // DUPLICATE INVITE CHECK (same event + user)
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
            $entryType = strtolower($data['entry_type'] ?? '');

            $invite_total = 0;
            $male_total = 0;
            $female_total = 0;
            $other_total = 0;
            $couple_total = 0;
            $entryTypeValue = null;

            switch ($entryType) {
                case 'male':
                    $entryTypeValue = 1;
                    $invite_total = 1;
                    $male_total = 1;
                    break;

                case 'female':
                    $entryTypeValue = 2;
                    $invite_total = 1;
                    $female_total = 1;
                    break;

                case 'other':
                    $entryTypeValue = 3;
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
            $entryTypeText = $entryLabels[$entryTypeValue] ?? '';

            // ----------------------------
            // START TRANSACTION - only DB operations
            // ----------------------------
            $db->transStart();

            // --- event_counters (ONE GLOBAL COUNTER) ---
            $counterTable = $db->table('event_counters');
            $counter = $counterTable->get()->getRow();

            if ($counter) {
                $new_invite_no = (int) $counter->last_invite_no + 1;
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

            // FINAL INVITE CODE (IN + EVENTCODE + padded number)
            $invite_code = 'IN' . $event_code . str_pad($new_invite_no, 3, '0', STR_PAD_LEFT);

            $inviteStatus = 0; // default pending

            if ($categoryType === 1) { // VIP - check seats before auto-approve

                $vip_total_seats = (int) $category->total_seats;

                $countsTable = $db->table('event_counts');
                $vipCounts = $countsTable
                    ->where('event_id', $event_id)
                    ->where('category_id', $category_id)
                    ->get()
                    ->getRow();

                $vip_used_seats = $vipCounts ? (int) $vipCounts->total_booking : 0;
                $requiredSeats = $invite_total; // seats needed for this invite

                if (($vip_used_seats + $requiredSeats) <= $vip_total_seats) {
                    // seats available -> approve
                    $inviteStatus = 1;
                } else {
                    // seats not available -> pending
                    $inviteStatus = 0;
                }
            } else {
                // Normal category - keep pending
                $inviteStatus = 0;
            }

            // SAVE INVITE (use real FK category_id)
            $insertData = [
                'event_id' => $event_id,
                'user_id' => $data['user_id'],
                'category_id' => $category_id,
                'entry_type' => $entryTypeValue,
                'partner' => $data['partner'] ?? null,
                'invite_code' => $invite_code,
                'status' => $inviteStatus,
                'requested_at' => date('Y-m-d H:i:s'),
            ];

            $this->inviteModel->insert($insertData);
            $invite_id = $db->insertID();

            if (!$invite_id) {
                $db->transRollback();
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Failed to create invite.'
                ]);
            }

            // UPDATE event_counts INVITES
            $countsTable = $db->table('event_counts');

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

            // Variables that will be used after transaction
            $qr_url = null;
            $whatsappResponse = null;
            $booking_code = null;

            // If auto-approved -> create booking and update booking counts (still inside transaction)
            if ((int) $inviteStatus === 1) {

                // refresh counter for booking (get latest)
                $counter = $counterTable->get()->getRow();
                if (!$counter) {
                    $db->transRollback();
                    return $this->response->setJSON([
                        'status' => 500,
                        'success' => false,
                        'message' => 'Counter record missing.'
                    ]);
                }

                $new_booking_no = (int) $counter->last_booking_no + 1;
                $counterTable->where('id', $counter->id)->update([
                    'last_booking_no' => $new_booking_no,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $booking_code = 'BK' . $event_code . str_pad($new_booking_no, 3, '0', STR_PAD_LEFT);

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
                    'created_at' => date('Y-m-d H:i:s')
                ];
                $db->table('event_booking')->insert($bookingData);

                // Update booking counts
                $eventCount = $countsTable
                    ->where('event_id', $event_id)
                    ->where('category_id', $category_id)
                    ->get()
                    ->getRow();

                if ($eventCount) {
                    $countsTable->where('id', $eventCount->id)->update([
                        'total_booking' => $eventCount->total_booking + $invite_total,
                        'total_male_booking' => $eventCount->total_male_booking + $male_total,
                        'total_female_booking' => $eventCount->total_female_booking + $female_total,
                        'total_other_booking' => $eventCount->total_other_booking + $other_total,
                        'total_couple_booking' => $eventCount->total_couple_booking + $couple_total,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    // If counts row was missing (unlikely here) insert with booking counts
                    $countsTable->insert([
                        'event_id' => $event_id,
                        'category_id' => $category_id,
                        'total_invites' => 0,
                        'total_male_invites' => 0,
                        'total_female_invites' => 0,
                        'total_other_invites' => 0,
                        'total_couple_invites' => 0,
                        'total_booking' => $invite_total,
                        'total_male_booking' => $male_total,
                        'total_female_booking' => $female_total,
                        'total_other_booking' => $other_total,
                        'total_couple_booking' => $couple_total,
                        'total_checkin' => 0,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }

                // UPDATE SEATS (your existing helper) - keep inside transaction as it updates DB
                $this->updateCategorySeatsFromEventCounts($event_id);

                // *** DO NOT call createQrForBooking or sendEventConfirmation here ***
                // Save $booking_code for use after transaction
            } else {
                // Pending invite: nothing DB-wise to do here beyond the invite/counts changes.
                // sendInviteConfirmation will be called AFTER transComplete to avoid crashes.
            }

            // Complete transaction
            $db->transComplete();

            // Check transaction status
            if ($db->transStatus() === false) {
                // Something failed within transaction
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Database error while processing invite.'
                ]);
            }

            // ----------------------------
            // AFTER TRANSACTION - external operations (safe)
            // ----------------------------
            try {
                // Generate QR only after transaction (safer if it uses filesystem or external lib)
                if ($booking_code) {
                    // createQrForBooking should ideally be safe/local. If it calls external APIs, keep it in try/catch.
                    $qr_url = $this->createQrForBooking($booking_code);
                }

                // Send notifications (WhatsApp) outside transaction so failures don't rollback DB
                if ($user) {
                    if ((int) $inviteStatus === 1) {
                        // auto-approved - send event confirmation
                        try {
                            $this->sendEventConfirmation(
                                $user->phone,
                                $user->name,
                                $event->event_name
                            );
                        } catch (\Throwable $e) {
                            // Log but don't fail the request
                            log_message('error', 'sendEventConfirmation error: ' . $e->getMessage());
                        }
                    } else {
                        // pending invite - send invite confirmation and capture response
                        try {
                            $whatsappResponse = $this->sendInviteConfirmation(
                                $user->phone,
                                $user->name,
                                $event->event_name
                            );
                        } catch (\Throwable $e) {
                            log_message('error', 'sendInviteConfirmation error: ' . $e->getMessage());
                            // keep $whatsappResponse null or set an error object if you prefer
                            $whatsappResponse = null;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Any unexpected error in post-transaction actions should be logged but not returned as 500
                log_message('error', 'Post-transaction processing error: ' . $e->getMessage());
            }

            // Prepare response data (human readable)
            $insertData['entry_type'] = $entryTypeText;
            $insertData['category_name'] = $categoryText;

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Invite created successfully.',
                'invite_code' => $invite_code,
                'data' => $insertData,
                'qr_code' => $qr_url,
                'whatsapp_response' => $whatsappResponse,
                'vip_auto_approved' => ($inviteStatus == 1)
            ]);
        } catch (\Throwable $e) {
            // Catch any top-level error, log and return a safe JSON error
            log_message('error', 'createInvite fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->response->setStatusCode(500)->setJSON([
                'status' => false,
                'message' => 'Internal server error.'
            ]);
        }
    }
    private function sendInviteConfirmation($phone, $name, $event_name)
    {
        $url = [
            "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/690e02d83dcbb55508455c59/webhook/execute",
            "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/6932bced35cc1fd9bcef7ebc/webhook/execute"
        ];
        if (strpos($phone, '+91') !== 0) {
            $phone = '+91' . ltrim($phone, '0');
        }
        // PAYLOAD  
        $payload = [
            "phone" => $phone,
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
        $url = [
            "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/690e02d83dcbb55508455c59/webhook/execute",
            "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/6932bd0735cc1fd9bcef7ef4/webhook/execute"
        ];

        if (strpos($phone, '+91') !== 0) {
            $phone = '+91' . ltrim($phone, '0');
        }

        $payload = [
            "phone" => $phone,
            "username" => $name,
            "event_name" => $event_name,
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
                event_ticket_category.total_seats,
                event_ticket_category.actual_booked_seats,
                event_ticket_category.dummy_booked_seats,
                event_ticket_category.dummy_invites,
                event_ticket_category.balance_seats,
                app_users.name,
                app_users.phone,
                app_users.email,
                app_users.insta_id,
                app_users.profile_image,
                app_users.profile_status,
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
            $invite['event_ticket_category'] = [
                'total_seats' => (int) $invite['total_seats'],
                'actual_booked_seats' => (int) $invite['actual_booked_seats'],
                'dummy_booked_seats' => (int) $invite['dummy_booked_seats'],
                'dummy_invites' => (int) $invite['dummy_invites'],
                'balance_seats' => (int) $invite['balance_seats']
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
                        $couple_total = 1;
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
                $countsTable = $this->db->table('event_counts');

                $eventCount = $countsTable
                    ->where('event_id', $invite['event_id'])
                    ->where('category_id', $invite['category_id'])
                    ->get()
                    ->getRow();

                if ($eventCount) {
                    $countsTable->where('id', $eventCount->id)->update([
                        'total_booking' => $eventCount->total_booking + $invite_total,
                        'total_male_booking' => $eventCount->total_male_booking + $male_total,
                        'total_female_booking' => $eventCount->total_female_booking + $female_total,
                        'total_other_booking' => $eventCount->total_other_booking + $other_total,
                        'total_couple_booking' => $eventCount->total_couple_booking + $couple_total,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                $this->updateCategorySeatsFromEventCounts($invite['event_id']);
            }
            // AUTO GENERATE QR CODE 
            $qr_url = $this->createQrForBooking($booking_code);

            // SEND WHATSAPP
            $this->sendEventConfirmation($phone, $username, $event_name);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite updated successfully.',
            'booking_code' => $booking_code,
            'qr_code' => $qr_url
        ]);
    }
    public function updateCategorySeatsFromEventCounts($event_id)
    {
        // Get all categories for this event
        $categories = $this->categoryModel
            ->where('event_id', $event_id)
            ->findAll();

        foreach ($categories as $cat) {

            $catRowId = $cat['category_id'];                  // <-- REAL category row ID
            $categoryType = $cat['category_name'];   // <-- 1 (VIP) or 2 (Normal)
            $totalSeats = $cat['total_seats'];

            // Fetch category-wise total booking from event_counts
            $countData = $this->db->table('event_counts')
                ->select('total_booking')
                ->where('event_id', $event_id)
                ->where('category_id', $categoryType)   // match type
                ->get()
                ->getRowArray();

            // If no rows → no booking for this category
            $totalBooking = $countData['total_booking'] ?? 0;

            // Calculate balance
            $balance = $totalSeats - $totalBooking;
            if ($balance < 0)
                $balance = 0;

            // Update category table
            $this->categoryModel->update($catRowId, [
                'actual_booked_seats' => $totalBooking,
                'balance_seats' => $balance
            ]);
        }
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
        $auth = $this->getAuthenticatedUser();

        if (isset($auth['error'])) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => 'Invalid or expired token.'
                ]);
        }

        $user_id = $auth['user_id']; // ← TOKEN USER
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
        $page = (int) $this->request->getGet('current_page') ?: 1;
        $limit = (int) $this->request->getGet('per_page') ?: 10;
        $keyword = $this->request->getGet('keyword');
        $offset = ($page - 1) * $limit;

        // ----- MAIN QUERY -----
        $builder = $this->db->table('event_counts ec')
            ->select("
            ec.event_id,
            e.event_name,
            e.event_code,
            e.event_location,
            e.event_city,
            e.event_date_start,
            e.event_time_start,
            e.event_date_end,
            e.event_time_end,
            e.total_seats AS event_total_seats, 
            c.category_id,
            c.category_name,
            c.total_seats,
            SUM(ec.total_invites) AS total_invites,
            SUM(ec.total_male_invites) AS total_male,
            SUM(ec.total_female_invites) AS total_female,
            SUM(ec.total_other_invites) AS total_other,
            SUM(ec.total_couple_invites) AS total_couple
        ")
            ->join('events e', 'e.event_id = ec.event_id', 'left')
            ->join('event_ticket_category c', 'c.category_id = ec.category_id', 'left')
            ->groupBy('ec.event_id, ec.category_id');

        // SEARCH FILTER
        if (!empty($keyword)) {
            $builder->groupStart()
                ->like('e.event_name', $keyword)
                ->orLike('e.event_city', $keyword)
                ->orLike('e.event_code', $keyword)
                ->groupEnd();
        }

        // ----- FETCH DATA -----
        $rows = $builder->get()->getResultArray();

        // ----- FORMAT RESPONSE BY EVENT -----
        $finalData = [];
        foreach ($rows as $row) {
            $eventId = $row['event_id'];
            $categoryKey = strtolower($row['category_name']);
            $categoryId = (int) $row['category_id'];

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
                        'total_seats' => (int) $row['event_total_seats'],
                        'total_invites' => 0,
                        'total_male' => 0,
                        'total_female' => 0,
                        'total_other' => 0,
                        'total_couple' => 0,
                    ]
                ];
            }

            // CATEGORY DATA
            $finalData[$eventId]['categories'][$categoryKey] = [
                'category_id' => $categoryId,
                'seats' => (int) $row['total_seats'],
                'total_invites' => (int) $row['total_invites'],
                'total_male' => (int) $row['total_male'],
                'total_female' => (int) $row['total_female'],
                'total_other' => (int) $row['total_other'],
                'total_couple' => (int) $row['total_couple'],
            ];

            // OVERALL TOTALS
            $finalData[$eventId]['overall_total']['total_invites'] += (int) $row['total_invites'];
            $finalData[$eventId]['overall_total']['total_male'] += (int) $row['total_male'];
            $finalData[$eventId]['overall_total']['total_female'] += (int) $row['total_female'];
            $finalData[$eventId]['overall_total']['total_other'] += (int) $row['total_other'];
            $finalData[$eventId]['overall_total']['total_couple'] += (int) $row['total_couple'];
        }

        // ----- PAGINATION (AFTER GROUPING BY EVENT) -----
        $allEvents = array_values($finalData);
        $totalEvents = count($allEvents);
        $paginatedEvents = array_slice($allEvents, $offset, $limit);

        $totalPages = ceil($totalEvents / $limit);

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Event invite counts fetched successfully',
            'data' => [
                'current_page' => $page,
                'per_page' => $limit,
                'keyword' => $keyword,
                'total_records' => $totalEvents,
                'total_pages' => $totalPages,
                'events' => $paginatedEvents
            ]
        ]);
    }
    public function downloadEventInviteExcel()
    {
        $eventId = $this->request->getGet('event_id');

        if (!$eventId) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => false,
                'message' => 'event_id is required'
            ]);
        }

        // ---- FETCH INVITES ----
        $rows = $this->db->table('event_invites i')
            ->select('i.*, e.event_name, e.event_code, c.category_name, u.name AS user_name, u.phone')
            ->join('events e', 'e.event_id = i.event_id', 'left')
            ->join('event_ticket_category c', 'c.category_id = i.category_id', 'left')
            ->join('app_users u', 'u.user_id = i.user_id', 'left')
            ->where('i.event_id', $eventId)
            ->orderBy('i.requested_at', 'DESC')
            ->get()
            ->getResultArray();

        // ---- HANDLE EMPTY DATA ----
        if (empty($rows)) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => false,
                'message' => 'No invites found for this event.'
            ]);
        }

        // ---- CREATE EXCEL ----
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'A1' => 'Event Code',
            'B1' => 'Event Name',
            'C1' => 'Category',
            'D1' => 'Invite Code',
            'E1' => 'Entry Type',
            'F1' => 'Male',
            'G1' => 'Female',
            'H1' => 'Other',
            'I1' => 'Couple',
            'J1' => 'User Name',
            'K1' => 'Phone',
            'L1' => 'Status',
            'M1' => 'Requested At'
        ];

        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }

        $entryTypeMap = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple'
        ];

        $rowNo = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue('A' . $rowNo, $row['event_code'] ?? '');
            $sheet->setCellValue('B' . $rowNo, $row['event_name'] ?? '');
            $sheet->setCellValue('C' . $rowNo, $row['category_name'] ?? '');
            $sheet->setCellValue('D' . $rowNo, $row['invite_code'] ?? '');
            $sheet->setCellValue('E' . $rowNo, $entryTypeMap[$row['entry_type']] ?? '');
            $sheet->setCellValue('F' . $rowNo, $row['entry_type'] == 1 ? 1 : 0);
            $sheet->setCellValue('G' . $rowNo, $row['entry_type'] == 2 ? 1 : 0);
            $sheet->setCellValue('H' . $rowNo, $row['entry_type'] == 3 ? 1 : 0);
            $sheet->setCellValue('I' . $rowNo, $row['entry_type'] == 4 ? 1 : 0);
            $sheet->setCellValue('J' . $rowNo, $row['user_name'] ?? '');
            $sheet->setCellValue('K' . $rowNo, $row['phone'] ?? '');
            $sheet->setCellValue('L' . $rowNo, $row['status'] == 1 ? 'Approved' : 'Pending');
            $sheet->setCellValue(
                'M' . $rowNo,
                $row['requested_at'] ? date('d-m-Y H:i', strtotime($row['requested_at'])) : ''
            );

            $rowNo++;
        }

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ---- DOWNLOAD EXCEL ----
        $fileName = 'event_invites_' . $eventId . '.xlsx';

        // Proper headers for Excel download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

}
