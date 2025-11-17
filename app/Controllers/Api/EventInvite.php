<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventModel;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\AppUserModel;
use App\Models\Api\EventBookingModel;
use CodeIgniter\HTTP\ResponseInterface;

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
            ])->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST);
        }
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
        if (empty($data['category_id'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'category_id is required.'
            ]);
        }

        // Validate category
        $category = $this->db->table('event_ticket_category')
            ->where('category_id', $data['category_id'])
            ->where('event_id', $data['event_id'])
            ->get()
            ->getRow();

        if (!$category) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Category not available for this event.'
            ]);
        }
        // Check duplicate invite
        $exists = $this->inviteModel
            ->where(['event_id' => $data['event_id'], 'user_id' => $data['user_id']])
            ->countAllResults();

        if ($exists > 0) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'User already invited for this event.'
            ]);
        }

        // -------------------------------------
        // AUTO CALCULATE TOTALS BASED ON entry_type
        // -------------------------------------
        $entryType = $data['entry_type'] ?? null;

        $invite_total = 0;
        $male_total = 0;
        $female_total = 0;
        $couple_total = 0;

        switch (strtolower($entryType)) {

            case 'male':
                $invite_total = 1;
                $male_total = 1;
                break;

            case 'female':
                $invite_total = 1;
                $female_total = 1;
                break;

            case 'couple':
                $invite_total = 4;
                $male_total = 1;
                $female_total = 1;
                $couple_total = 2;
                break;

            default:
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Invalid entry_type. Allowed: Male, Female, Couple'
                ]);
        }

        // -------------------------------------
        // FINAL INSERT DATA
        // -------------------------------------
        $insertData = [
            'event_id' => $data['event_id'],
            'user_id' => $data['user_id'],
            'category_id' => $data['category_id'],
            'entry_type' => ucfirst($entryType),              // Male/Female/Couple
            'accompanied_by' => $data['accompanied_by'] ?? null,

            'invite_total' => $invite_total,
            'invite_male_total' => $male_total,
            'invite_female_total' => $female_total,
            'invite_couple_total' => $couple_total,

            // 'invite_type' => $data['invite_type'], 
            'status' => 0,
            'requested_at' => date('Y-m-d H:i:s'),
        ];

        // Save to DB
        $this->inviteModel->insert($insertData);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite created successfully.',
            'data' => $insertData
        ]);
    }

    public function listInvites($search = '')
    {
        $page = (int) $this->request->getGet('current_page') ?: 1;
        $limit = (int) $this->request->getGet('per_page') ?: 10;

        $search = $search ?: ($this->request->getGet('keyword') ?? $this->request->getGet('search'));

        $offset = ($page - 1) * $limit;

        // Join with users, events & categories
        $builder = $this->inviteModel
            ->select("
            event_invites.*,
            events.event_name,
            events.event_city,
            event_ticket_category.category_name,
            app_users.name,
            app_users.phone,
            app_users.email
        ")
            ->join('events', 'events.event_id = event_invites.event_id', 'left')
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_invites.category_id', 'left')
            ->join('app_users', 'app_users.user_id = event_invites.user_id', 'left')
            ->where('event_invites.status !=', 4); // not deleted

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

            // Category name (replaces invite_type_text)
            $invite['category_text'] = $invite['category_name'] ?? 'No Category';

            // Status Text
            $statusMap = [
                0 => 'Pending',
                1 => 'Approved',
                2 => 'Rejected',
                3 => 'Expired'
            ];
            $invite['status_text'] = $statusMap[$invite['status']] ?? 'Unknown';

            // Entry Type Text
            $invite['entry_type_text'] = $invite['entry_type'] ?? 'N/A';

            // Accompanied by user
            if (!empty($invite['accompanied_by'])) {
                $accUser = $this->db->table('app_users')
                    ->select('name')
                    ->where('user_id', $invite['accompanied_by'])
                    ->get()
                    ->getRow();

                $invite['accompanied_by_name'] = $accUser->name ?? null;
            } else {
                $invite['accompanied_by_name'] = null;
            }

            // Totals
            $invite['totals'] = [
                'total' => (int) $invite['invite_total'],
                'male' => (int) $invite['invite_male_total'],
                'female' => (int) $invite['invite_female_total'],
                'couple' => (int) $invite['invite_couple_total'],
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

        // Update invite status
        $updateData = [
            'status' => $status,
            'approved_at' => ($status == 1) ? date('Y-m-d H:i:s') : null
        ];
        $this->inviteModel->update($invite_id, $updateData);


        // ------------------------------
        // AUTO BOOKING GENERATION
        // ------------------------------
        if ($status == 1) {

            // Check if booking already exists
            $existing = $this->bookingModel
                ->where('invite_id', $invite_id)
                ->first();

            if (!$existing) {

                // Fetch category details
                $category = $this->categoryModel->find($invite['category_id']);
                $price = $category['price'] ?? 0;

                // Initialize totals
                $male_total = 0;
                $female_total = 0;
                $couple_total = 0;

                // Determine totals based on invite entry_type
                $entry_type = strtolower(trim($invite['entry_type'] ?? ''));

                if ($entry_type === 'male') {
                    $male_total = 1;
                } elseif ($entry_type === 'female') {
                    $female_total = 1;
                } elseif ($entry_type === 'couple') {
                    $couple_total = 2;
                }

                // Final booking total formula
                $booking_total = $male_total + $female_total + ($couple_total * 2);

                // Insert booking
                $bookingData = [
                    'event_id' => $invite['event_id'],
                    'user_id' => $invite['user_id'],
                    'category_id' => $invite['category_id'],
                    'total_price' => $price,
                    'invite_id' => $invite_id,
                    'status' => 1,
                    'booking_male_total' => $male_total,
                    'booking_female_total' => $female_total,
                    'booking_couple_total' => $couple_total,
                    'booking_total' => $booking_total,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                $this->bookingModel->insert($bookingData);
            }
        }


        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite updated successfully.'
        ]);
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
