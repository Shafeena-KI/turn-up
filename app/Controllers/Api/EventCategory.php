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

        // Check event_id
        if (empty($data['event_id'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON([
                    'status' => false,
                    'message' => 'event_id is required.'
                ]);
        }

        // Check categories array
        if (empty($data['categories']) || !is_array($data['categories'])) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON([
                    'status' => false,
                    'message' => 'categories array is required.'
                ]);
        }

        $event_id = $data['event_id'];
        $categories = $data['categories'];
        $savedCategories = [];

        $totalSeatsSum = 0; // <-- NEW

        foreach ($categories as $cat) {

            // Validate each category
            if (empty($cat['category_name']) || empty($cat['total_seats']) || empty($cat['price'])) {
                continue; // skip invalid
            }
            // Convert category text → number
            $categoryName = strtolower($cat['category_name']);
            $categoryType = null;

            if ($categoryName === 'vip') {
                $categoryType = 1;
            } elseif ($categoryName === 'normal') {
                $categoryType = 2;
            } else {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Invalid category_name. Allowed: VIP, Normal'
                ]);
            }
            $actual_booked = isset($cat['actual_booked_seats']) ? (int) $cat['actual_booked_seats'] : 0;
            $dummy_booked = isset($cat['dummy_booked_seats']) ? (int) $cat['dummy_booked_seats'] : 0;
            $dummy_invites = isset($cat['dummy_invites']) ? (int) $cat['dummy_invites'] : 0;

            $total_seats = (int) $cat['total_seats'];

            // add to total event seats
            $totalSeatsSum += $total_seats;   // <-- NEW

            // Calculate balance seats
            $balance = $total_seats - ($actual_booked + $dummy_booked);
            if ($balance < 0)
                $balance = 0;

            // Prepare insert data
            $insertData = [
                'event_id' => $event_id,
                'category_name' => $categoryType,
                'total_seats' => $total_seats,
                'actual_booked_seats' => $actual_booked,
                'dummy_booked_seats' => $dummy_booked,
                'dummy_invites' => $dummy_invites,
                'balance_seats' => $balance,
                'price' => $cat['price'],
                'status' => $cat['status'] ?? 1, // Default active
            ];

            // Insert into DB
            $category_id = $this->categoryModel->insert($insertData);

            // Show clean response with label text
            $insertData['category_name'] = ucfirst($categoryName);

            $savedCategories[] = array_merge(
                ['category_id' => $category_id],
                $insertData
            );
        }

        // UPDATE EVENT TOTAL SEATS
        $this->eventModel
            ->where('event_id', $event_id)
            ->set(['total_seats' => $totalSeatsSum])
            ->update();

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Categories created successfully.',
            'data' => $savedCategories,
            'event_total_seats' => $totalSeatsSum // optional return
        ]);
    }
    public function getCategoryByEvent()
    {
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
