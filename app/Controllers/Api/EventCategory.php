<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\EventModel;
use CodeIgniter\HTTP\ResponseInterface;

class EventCategory extends BaseController
{
    protected $categoryModel;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->categoryModel = new EventCategoryModel();
        $this->eventModel = new EventModel();
    }
    //  Create Category
    public function createCategory()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['event_id'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON(['status' => false, 'message' => 'event_id is required.']);
        }

        if (empty($data['categories']) || !is_array($data['categories'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON(['status' => false, 'message' => 'categories array is required.']);
        }

        $event_id = $data['event_id'];
        $inputCats = $data['categories'];

        // Default containers for VIP & Normal
        $vipData = null;
        $normalData = null;

        // Separate VIP and Normal from input
        foreach ($inputCats as $cat) {
            if (!isset($cat['category_name']))
                continue;

            $name = strtolower($cat['category_name']);

            if ($name === 'vip') {
                $vipData = $cat;
            }
            if ($name === 'normal') {
                $normalData = $cat;
            }
        }

        // Prepare final 2 category rows ALWAYS
        $finalCategories = [
            [
                'type' => 1,
                'name' => 'VIP',
                'data' => $vipData
            ],
            [
                'type' => 2,
                'name' => 'Normal',
                'data' => $normalData
            ],
        ];

        $savedCategories = [];
        $totalSeatsSum = 0;

        foreach ($finalCategories as $cat) {

            // If admin did NOT enter this category → set default 0 values
            if (empty($cat['data'])) {
                $insertData = [
                    'event_id' => $event_id,
                    'category_name' => $cat['type'],
                    'total_seats' => 0,
                    'actual_booked_seats' => 0,
                    'dummy_booked_seats' => 0,
                    'dummy_invites' => 0,
                    'balance_seats' => 0,
                    'price' => 0,
                    'couple_price' => 0,
                    'status' => 1
                ];
            } else {

                // If admin entered details → use them
                $d = $cat['data'];

                $total_seats = (int) ($d['total_seats'] ?? 0);
                $actual_booked = (int) ($d['actual_booked_seats'] ?? 0);
                $dummy_booked = (int) ($d['dummy_booked_seats'] ?? 0);
                $dummy_invites = (int) ($d['dummy_invites'] ?? 0);
                $price = (int) ($d['price'] ?? 0);
                $couple_price = (int) ($d['couple_price'] ?? 0);

                $balance = $total_seats - $actual_booked;
                if ($balance < 0)
                    $balance = 0;

                $insertData = [
                    'event_id' => $event_id,
                    'category_name' => $cat['type'],
                    'total_seats' => $total_seats,
                    'actual_booked_seats' => $actual_booked,
                    'dummy_booked_seats' => $dummy_booked,
                    'dummy_invites' => $dummy_invites,
                    'balance_seats' => $balance,
                    'price' => $price,
                    'couple_price' => $couple_price,
                    'status' => $d['status'] ?? 1
                ];

                $totalSeatsSum += $total_seats;
            }

            // Save into DB
            $category_id = $this->categoryModel->insert($insertData);

            // Set readable category name for response
            $insertData['category_name'] = $cat['name'];

            $savedCategories[] = array_merge(['category_id' => $category_id], $insertData);
        }

        // Update event total seats
        $this->eventModel
            ->where('event_id', $event_id)
            ->set(['total_seats' => $totalSeatsSum])
            ->update();

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Categories added successfully.',
            'data' => $savedCategories,
            'event_total_seats' => $totalSeatsSum
        ]);
    }
    public function getToken()
    {
        // Try all possible header names
        $authHeader = $this->request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }

        if (empty($authHeader)) {
            $authHeader = $_SERVER['Authorization'] ?? '';
        }

        // Extract token
        if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
    public function getCategoryByEvent()
    {
        $token = $this->getToken();

        // Optional token: validate ONLY if provided
        if ($token) {
            $user = $this->db->table('admin_users')
                ->where('token', trim($token))
                ->get()
                ->getRow();

            // if (!$user) {
            //     return $this->response->setJSON([
            //         'status' => false,
            //         'message' => 'Invalid or expired token.'
            //     ]);
            // }
            if (!$user) {
                return $this->response
                    ->setStatusCode(401)
                    ->setJSON([
                        'status' => 401,
                        'success' => false,
                        'message' => 'Invalid or expired token.'
                    ]);
            }

        }
        $data = $this->request->getJSON(true);
        $event_id = $data['event_id'] ?? null;

        if (empty($event_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required.'
            ]);
        }

        $category = $this->categoryModel->where('event_id', $event_id)->findAll();

        if (empty($category)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'No Categorys found for this event.'
            ]);
        }
        foreach ($category as &$cat) {
            $cat['category_name'] = ($cat['category_name'] == 1) ? 'VIP' : 'Normal';
        }

        return $this->response->setJSON([
            'status' => true,
            'data' => $category
        ]);
    }
    //   Update Category
    public function updateCategory()
    {
        $data = $this->request->getJSON(true);

        // VALIDATION: event_id is required

        if (empty($data['event_id'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required.'
            ]);
        }

        $event_id = (int) $data['event_id'];

        //VALIDATION: categories array required
        if (empty($data['categories']) || !is_array($data['categories'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'categories array is required.'
            ]);
        }

        $updatedList = [];

        foreach ($data['categories'] as $cat) {

            // VALIDATION: category_id required
            if (empty($cat['category_id'])) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'category_id is missing in one of the items.'
                ]);
            }

            $category_id = (int) $cat['category_id'];

            // VALIDATION: check category belongs to event

            $category = $this->categoryModel
                ->where('category_id', $category_id)
                ->where('event_id', $event_id)
                ->first();

            if (!$category) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => "Category ID {$category_id} does NOT belong to event_id {$event_id}."
                ]);
            }
            $updateData = [];

            // Allowed fields
            $fields = [
                'category_name',
                'total_seats',
                'price',
                'status',
                'actual_booked_seats',
                'dummy_booked_seats',
                'dummy_invites'
            ];

            foreach ($fields as $field) {
                if (isset($cat[$field])) {

                    // Convert VIP/Normal text to 1/2 before saving
                    if ($field == 'category_name') {
                        if ($cat[$field] == 'VIP') {
                            $updateData[$field] = 1;
                        } elseif ($cat[$field] == 'Normal') {
                            $updateData[$field] = 2;
                        } else {
                            $updateData[$field] = (int) $cat[$field]; // already a number
                        }
                    } else {
                        $updateData[$field] = $cat[$field];
                    }
                }
            }

            // Seat calculations
            $total_seats = $updateData['total_seats'] ?? $category['total_seats'];
            $actual_booked = $updateData['actual_booked_seats'] ?? $category['actual_booked_seats'];
            $dummy_booked = $updateData['dummy_booked_seats'] ?? $category['dummy_booked_seats'];

            $balance_seats = $total_seats - ($actual_booked + $dummy_booked);
            if ($balance_seats < 0) {
                $balance_seats = 0;
            }

            $updateData['balance_seats'] = $balance_seats;

            // Perform update
            $this->categoryModel->update($category_id, $updateData);

            $updatedList[] = array_merge(['category_id' => $category_id], $updateData);
        }
        foreach ($updatedList as &$cat) {
            if (isset($cat['category_name'])) {
                $cat['category_name'] = ($cat['category_name'] == 1) ? 'VIP' : 'Normal';
            }
        }
        return $this->response->setJSON([
            'status' => true,
            'message' => 'Categories updated successfully.',
            'event_id' => $event_id,
            'data' => $updatedList
        ]);
    }
    // Delete Category
    public function deleteCategory()
    {
        $data = $this->request->getJSON(true);
        $category_id = $data['category_id'] ?? null;

        if (empty($category_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'category_id is required.'
            ]);
        }

        $category = $this->categoryModel->find($category_id);
        if (!$category) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Category not found.'
            ]);
        }

        $this->categoryModel->update($category_id, ['status' => 4]);
        return $this->response->setJSON([
            'status' => true,
            'message' => 'Category deleted successfully.'
        ]);
    }

}
