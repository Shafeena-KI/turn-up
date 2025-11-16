<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventCategoryModel;
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
    }

    /**
     * Create Category
     */
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

        foreach ($categories as $cat) {

            // Validate each category
            if (empty($cat['category_name']) || empty($cat['total_seats']) || empty($cat['price'])) {
                continue; // skip invalid
            }

            $actual_booked = isset($cat['actual_booked_seats']) ? (int) $cat['actual_booked_seats'] : 0;
            $dummy_booked = isset($cat['dummy_booked_seats']) ? (int) $cat['dummy_booked_seats'] : 0;
            $dummy_invites = isset($cat['dummy_invites']) ? (int) $cat['dummy_invites'] : 0;

            $total_seats = (int) $cat['total_seats'];

            // Calculate balance seats
            $balance = $total_seats - ($actual_booked + $dummy_booked);
            if ($balance < 0)
                $balance = 0;

            // Prepare insert data
            $insertData = [
                'event_id' => $event_id,
                'category_name' => strtoupper($cat['category_name']),
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

            // Add to output
            $savedCategories[] = array_merge(
                ['category_id' => $category_id],
                $insertData
            );
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Categories created successfully.',
            'data' => $savedCategories
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

        return $this->response->setJSON([
            'status' => true,
            'data' => $category
        ]);
    }


    /**
     * Update Category
     */
    public function updateCategory()
    {
        $data = $this->request->getJSON(true);

        // Must receive categories array
        if (empty($data['categories']) || !is_array($data['categories'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'categories array is required.'
            ]);
        }

        $updatedList = [];

        foreach ($data['categories'] as $cat) {

            // category_id must exist
            if (empty($cat['category_id'])) {
                continue;
            }

            $category_id = $cat['category_id'];

            $category = $this->categoryModel->find($category_id);
            if (!$category) {
                continue;
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
                    $updateData[$field] = $cat[$field];
                }
            }

            // Calculate seat values
            $total_seats = isset($updateData['total_seats']) ? (int) $updateData['total_seats'] : (int) $category['total_seats'];
            $actual_booked = isset($updateData['actual_booked_seats']) ? (int) $updateData['actual_booked_seats'] : (int) $category['actual_booked_seats'];
            $dummy_booked = isset($updateData['dummy_booked_seats']) ? (int) $updateData['dummy_booked_seats'] : (int) $category['dummy_booked_seats'];

            // Recalculate balance seats
            $balance_seats = $total_seats - ($actual_booked + $dummy_booked);
            if ($balance_seats < 0) {
                $balance_seats = 0;
            }

            $updateData['balance_seats'] = $balance_seats;

            // UPDATE row
            $this->categoryModel->update($category_id, $updateData);

            // Prepare return data
            $updatedList[] = array_merge(['category_id' => $category_id], $updateData);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Categories updated successfully.',
            'data' => $updatedList
        ]);
    }

    /**
     * Delete Category
     */
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
