<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\NotificationLibrary;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventModel;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\AppUserModel;
use App\Models\Api\EventBookingModel;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\API\ResponseTrait;
use Config\Database;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use CodeIgniter\I18n\Time;
class EventInvite extends BaseController
{
    protected $db;
    protected $userModel;
    protected $eventModel;
    protected $inviteModel;
    protected $bookingModel;
    protected $categoryModel;
    protected $notificationLibrary;
    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        
        $this->db                   = Database::connect();
        $this->eventModel           = new EventModel();
        $this->userModel            = new AppUserModel();
        $this->inviteModel          = new EventInviteModel();
        $this->bookingModel         = new EventBookingModel();
        $this->categoryModel        = new EventCategoryModel();
        $this->notificationLibrary  = new NotificationLibrary();
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

            // REQUIRED SEATS
            $requiredSeats = ($entryTypeValue == 4) ? 2 : 1;

            // TOTAL SEATS FROM CATEGORY TABLE
            $totalSeatsAllowed = (int) $category->total_seats;
            // START TRANSACTION
            $db->transStart();
            // FETCH USED SEATS
            $eventCounts = $db->query("
                SELECT *
                FROM event_counts
                WHERE event_id = ? AND category_id = ?
                FOR UPDATE
            ", [$event_id, $category_id])->getRow();

            $usedSeats = 0;

            if ($eventCounts) {

                // APPROVED seats always block
                $usedSeats += (int) $eventCounts->total_approved;

                // NORMAL category → pending blocks seats
                if ($categoryType === 2) {

                    $pendingCouples =
                        (int) $eventCounts->total_couple_invites -
                        (int) $eventCounts->total_couple_approved;

                    $pendingSingles =
                        ((int) $eventCounts->total_male_invites - (int) $eventCounts->total_male_approved) +
                        ((int) $eventCounts->total_female_invites - (int) $eventCounts->total_female_approved) +
                        ((int) $eventCounts->total_other_invites - (int) $eventCounts->total_other_approved);

                    $usedSeats += ($pendingCouples * 2) + $pendingSingles;
                }

                // VIP → pending does NOT block seats
            }


            $availableSeats = $totalSeatsAllowed - $usedSeats;


            // DEFAULT
            $inviteStatus = 0;
           
            // SEAT CHECK FIRST (MOST IMPORTANT)
            if ($availableSeats >= $requiredSeats) {

                // VERIFIED USERS → AUTO APPROVE
                if ($user->profile_status == 2) {
                    $inviteStatus = 1;
                }

                // VIP CATEGORY
                elseif ($categoryType === 1) {
                    $inviteStatus = 1; // approve if seats available
                }

                // NORMAL → pending
                else {
                    $inviteStatus = 0;
                }
            }

            // print_r($inviteStatus); 
            // print_r($availableSeats); 
            // print_r($requiredSeats); exit;
            // --- event_counters (INVITE COUNTER ONLY) ---
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

            // FINAL INVITE CODE
            $invite_code = 'IN' . $event_code . str_pad($new_invite_no, 3, '0', STR_PAD_LEFT);
            // APPROVAL TYPE
            $approvalType = ($inviteStatus === 1) ? 1 : 0;
            // SAVE INVITE
            $insertData = [
                'event_id' => $event_id,
                'user_id' => $data['user_id'],
                'category_id' => $category_id,
                'entry_type' => $entryTypeValue,
                'invite_code' => $invite_code,
                'status' => $inviteStatus,
                'approval_type' => (int) $approvalType,
                'requested_at' => date('Y-m-d H:i:s'),
            ];

            // ONLY for couple
            if ($entryTypeValue === 4) {
                $insertData['partner'] = $data['partner'];
            }

            // ONLY if approved
            if ($inviteStatus === 1) {
                $insertData['approved_at'] = date('Y-m-d H:i:s');
            }

            $invite_id = $this->inviteModel->insert($insertData);

            if ($invite_id === false) {

                log_message('error', 'Invite Insert Error');
                log_message('error', json_encode($this->inviteModel->errors()));
                log_message('error', json_encode($insertData));

                $db->transRollback();

                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Failed to create invite.',
                    'errors' => $this->inviteModel->errors() // TEMP: show in API
                ]);
            }


            // UPDATE event_counts (INVITES ONLY + APPROVED INVITES)
            $countsTable = $db->table('event_counts');

            $eventCount = $countsTable
                ->where('event_id', $event_id)
                ->where('category_id', $category_id)
                ->get()
                ->getRow();

            // For approved invites
            $approved_male = 0;
            $approved_female = 0;
            $approved_other = 0;
            $approved_couple = 0;

            if ($inviteStatus === 1) {

                if ($entryTypeValue == 1)
                    $approved_male = 1;

                if ($entryTypeValue == 2)
                    $approved_female = 1;

                if ($entryTypeValue == 3)
                    $approved_other = 1;

                if ($entryTypeValue == 4)
                    $approved_couple = 1;   // COUPLE = 2 PERSONS
            }


            // TOTAL APPROVED = SUM OF ALL APPROVED TYPES
            $approved_total = $approved_male + $approved_female + $approved_other + ($approved_couple * 2);


            if ($eventCount) {

                $countsTable->where('id', $eventCount->id)->update([

                    // INVITE COUNTS
                    'total_invites' => $eventCount->total_invites + $invite_total,
                    'total_male_invites' => $eventCount->total_male_invites + $male_total,
                    'total_female_invites' => $eventCount->total_female_invites + $female_total,
                    'total_other_invites' => $eventCount->total_other_invites + $other_total,
                    'total_couple_invites' => $eventCount->total_couple_invites + $couple_total,

                    // APPROVED COUNTS
                    'total_approved' => $eventCount->total_approved + $approved_total,
                    'total_male_approved' => $eventCount->total_male_approved + $approved_male,
                    'total_female_approved' => $eventCount->total_female_approved + $approved_female,
                    'total_other_approved' => $eventCount->total_other_approved + $approved_other,
                    'total_couple_approved' => $eventCount->total_couple_approved + $approved_couple,

                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            } else {

                $countsTable->insert([
                    'event_id' => $event_id,
                    'category_id' => $category_id,

                    // INVITES
                    'total_invites' => $invite_total,
                    'total_male_invites' => $male_total,
                    'total_female_invites' => $female_total,
                    'total_other_invites' => $other_total,
                    'total_couple_invites' => $couple_total,

                    // APPROVED
                    'total_approved' => $approved_total,
                    'total_male_approved' => $approved_male,
                    'total_female_approved' => $approved_female,
                    'total_other_approved' => $approved_other,
                    'total_couple_approved' => $approved_couple,

                    'total_booking' => 0,
                    'total_checkin' => 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }


            // COMPLETE TRANSACTION
            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Database error while processing invite.'
                ]);
            }

            // AFTER TRANSACTION – WHATSAPP ONLY

            $whatsappResponse = null;

            try {
                if ($inviteStatus === 1) {
                    // VIP Approved
                    $whatsappResponse = $this->notificationLibrary->sendEventConfirmation(
                        $user->phone,
                        $user->name,
                        $event->event_name,
                    );
                } else {
                    // Normal Pending
                    $whatsappResponse = $this->notificationLibrary->sendInviteConfirmation(
                        $user->phone,
                        $user->name,
                        $event->event_name
                    );
                }
            } catch (\Throwable $e) {
                log_message('error', 'Whatsapp error: ' . $e->getMessage());
            }

            // FINAL RESPONSE
            $insertData['entry_type'] = $entryTypeText;
            $insertData['category_name'] = $categoryText;

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Invite created successfully.',
                'invite_code' => $invite_code,
                'invite_status' => ($inviteStatus === 1 ? 'Approved' : 'Pending'),
                'data' => $insertData,
                'whatsapp_response' => $whatsappResponse
            ]);

        } catch (\Throwable $e) {

            log_message('error', 'CreateInvite Error: ' . $e->getMessage());

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'status' => false,
                    'message' => 'Internal server error',
                    'error' => ENVIRONMENT !== 'production' ? $e->getMessage() : null
                ]);
        }
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
        events.total_seats AS event_total_seats,
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
        ec.total_couple_invites,
        ec.total_approved,
        ec.total_male_approved,
        ec.total_female_approved,
        ec.total_other_approved,
        ec.total_couple_approved
    ")
            ->join('events', 'events.event_id = event_invites.event_id', 'left')
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_invites.category_id', 'left')
            ->join('app_users', 'app_users.user_id = event_invites.user_id', 'left')
            ->join(
                'event_counts ec',
                'ec.event_id = event_invites.event_id 
                    AND ec.id = (SELECT MAX(id) FROM event_counts WHERE event_id = event_invites.event_id)',
                'left'
            );

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
                3 => 'Expired',
                4 => 'Payment Pending',
                5 => 'Paid'
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
                'total_approved' => (int) $invite['total_approved'],
                'total_male_approved' => (int) $invite['total_male_approved'],
                'total_female_approved' => (int) $invite['total_female_approved'],
                'total_other_approved' => (int) $invite['total_other_approved'],
                'total_couple_approved' => (int) $invite['total_couple_approved'],
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

        // Already approved? Don't double-count
        if ($invite['status'] == 1 && $status == 1) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invite already approved.'
            ]);
        }
        $oldStatus = (int) $invite['status'];
        $newStatus = (int) $status;


        // Update invite status
        $this->inviteModel->update($invite_id, [
            'status' => $newStatus,
            'approved_at' => ($newStatus == 1)
                ? Time::now('Asia/Kolkata')->toDateTimeString()
                : null
        ]);
        // INCREASE COUNT ONLY FOR PENDING → APPROVED
        if ($oldStatus === 0 && $newStatus === 1) {

            $db = $this->db;

            $entry_type = (int) $invite['entry_type']; // 1=M,2=F,3=O,4=C
            $event_id = $invite['event_id'];
            $category_id = $invite['category_id'];

            $m = $f = $o = $c = 0;

            if ($entry_type == 1)
                $m = 1;
            if ($entry_type == 2)
                $f = 1;
            if ($entry_type == 3)
                $o = 1;
            if ($entry_type == 4)
                $c = 1;

            // Couple counts as 2 persons
            $t = $m + $f + $o + ($c * 2);

            $countsTable = $db->table('event_counts');

            $row = $countsTable
                ->where('event_id', $event_id)
                ->where('category_id', $category_id)
                ->get()
                ->getRow();

            if ($row) {
                $countsTable->where('id', $row->id)->update([
                    'total_approved' => $row->total_approved + $t,
                    'total_male_approved' => $row->total_male_approved + $m,
                    'total_female_approved' => $row->total_female_approved + $f,
                    'total_other_approved' => $row->total_other_approved + $o,
                    'total_couple_approved' => $row->total_couple_approved + $c,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
        }


        // SEND WHATSAPP CONFIRMATION ONLY IF APPROVED

        if ($status == 1) {
            $user = $this->userModel->find($invite['user_id']);
            $event = $this->eventModel->find($invite['event_id']);

            if ($user && $event) {
                $this->notificationLibrary->sendEventConfirmation(
                    $user['phone'],
                    $user['name'],
                    $event['event_name']
                );
            }
        }
        // SEND WHATSAPP REJECTION ONLY IF REJECTED (status=2)
        if ($status == 2) {
            $user = $this->userModel->find($invite['user_id']);
            $event = $this->eventModel->find($invite['event_id']);

            if ($user && $event) {
                // Call your rejection message function
                $this->notificationLibrary->sendEventRejection(
                    $user['phone'],
                    $user['name'],
                    $event['event_name']
                );
            }
        }
        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite status updated successfully.'
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
            SUM(ec.total_couple_invites) AS total_couple,

            SUM(ec.total_approved) AS total_approved,
            SUM(ec.total_male_approved) AS total_male_approved,
            SUM(ec.total_female_approved) AS total_female_approved,
            SUM(ec.total_other_approved) AS total_other_approved,
            SUM(ec.total_couple_approved) AS total_couple_approved
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
            $categoryName = $row['category_name'] ?? 'Unknown';
            $categoryKey = strtolower($categoryName);
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
                    'event_total_seats' => $row['event_total_seats'],
                    'categories' => [],
                    'overall_total' => [
                        'total_seats' => (int) $row['event_total_seats'],
                        'total_invites' => 0,
                        'total_male' => 0,
                        'total_female' => 0,
                        'total_other' => 0,
                        'total_couple' => 0,
                        'total_approved' => 0,
                        'total_male_approved' => 0,
                        'total_female_approved' => 0,
                        'total_other_approved' => 0,
                        'total_couple_approved' => 0,
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
                'total_approved' => (int) $row['total_approved'],
                'total_male_approved' => (int) $row['total_male_approved'],
                'total_female_approved' => (int) $row['total_female_approved'],
                'total_other_approved' => (int) $row['total_other_approved'],
                'total_couple_approved' => (int) $row['total_couple_approved'],

            ];

            // OVERALL TOTALS
            $finalData[$eventId]['overall_total']['total_invites'] += (int) $row['total_invites'];
            $finalData[$eventId]['overall_total']['total_male'] += (int) $row['total_male'];
            $finalData[$eventId]['overall_total']['total_female'] += (int) $row['total_female'];
            $finalData[$eventId]['overall_total']['total_other'] += (int) $row['total_other'];
            $finalData[$eventId]['overall_total']['total_couple'] += (int) $row['total_couple'];

            $finalData[$eventId]['overall_total']['total_approved'] += (int) $row['total_approved'];
            $finalData[$eventId]['overall_total']['total_male_approved'] += (int) $row['total_male_approved'];
            $finalData[$eventId]['overall_total']['total_female_approved'] += (int) $row['total_female_approved'];
            $finalData[$eventId]['overall_total']['total_other_approved'] += (int) $row['total_other_approved'];
            $finalData[$eventId]['overall_total']['total_couple_approved'] += (int) $row['total_couple_approved'];

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
}
