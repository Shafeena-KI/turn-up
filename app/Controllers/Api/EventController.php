<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventModel;

class EventController extends BaseController
{
    protected $eventModel;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
		header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->eventModel = new EventModel();
        helper(['form', 'url']);
    }
    //  Get All Events
    public function index()
    {
        $events = $this->eventModel->findAll();
        return $this->response->setJSON(['status' => true, 'data' => $events]);
    }
    //  Get Single Event by ID
    public function show($id)
    {
        $event = $this->eventModel->find($id);
        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        return $this->response->setJSON([
            'status' => true,
            'data' => $event
        ]);
    }
    // Create New Event
    public function create()
    {
       
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        function respond($success, $message, $data = null, $code = 200)
        {
            http_response_code($code);
            echo json_encode([
                'status' => $success,
                'message' => $message,
                'data' => $data
            ]);
            exit;
        }

        if (empty($_POST) && empty($_FILES)) {
            respond(false, 'Invalid or empty request. Use multipart/form-data.');
        }

        // --- Collect event inputs ---
        $event = [
            'event_name' => $_POST['event_name'] ?? '',
            'event_description' => $_POST['event_description'] ?? '',
            'event_location' => $_POST['event_location'] ?? '',
            'event_map' => $_POST['event_map'] ?? '',
            'event_date_start' => $_POST['event_date_start'] ?? '',
            'event_date_end' => $_POST['event_date_end'] ?? '',
            'dress_code' => $_POST['dress_code'] ?? '',
            'age_limit' => $_POST['age_limit'] ?? '',
            'event_type' => $_POST['event_type'] ?? '',
            'created_by' => $_POST['created_by'] ?? '',
            'status' => $_POST['status'] ?? '',
            'event_time_start' => $_POST['event_time_start'] ?? '',
            'event_time_end' => $_POST['event_time_end'] ?? '',
            'event_city' => $_POST['event_city'] ?? '',
            'total_seats' => $_POST['total_seats'] ?? '',
        ];

        // ----------- HOST ID ARRAY ------------
        $hostData = $this->request->getPost('host_id');

        if (empty($hostData)) {

            $hostIDs = [];

        }
        // If already array → use directly
        elseif (is_array($hostData)) {

            $hostIDs = $hostData;

        }
        // If JSON string → decode
        elseif (is_string($hostData) && json_decode($hostData, true) !== null) {

            $hostIDs = json_decode($hostData, true);

        }
        // If comma separated string → split
        elseif (is_string($hostData)) {

            $hostIDs = explode(',', $hostData);

        } else {

            $hostIDs = [];
        }

        // Convert all IDs to integer before saving
        $event['host_id'] = json_encode(array_map('intval', $hostIDs));

        // ----------- TAG ID ARRAY ------------
        $tagData = $this->request->getPost('tag_id');

        if (empty($tagData)) {

            $tagIDs = [];

        }

        // If already array → use directly
        elseif (is_array($tagData)) {

            $tagIDs = $tagData;

        }

        // If JSON string → decode
        elseif (is_string($tagData) && json_decode($tagData, true) !== null) {

            $tagIDs = json_decode($tagData, true);

        }

        // If comma separated value → split manually
        elseif (is_string($tagData)) {

            $tagIDs = explode(',', $tagData);

        } else {

            $tagIDs = [];

        }

        // Convert back to JSON to store in DB

        $event['tag_id'] = json_encode(array_map('intval', $tagIDs));




        // ---------------- FILE UPLOADS ----------------
        $uploadDirPoster = FCPATH . 'public/uploads/events/poster_images/';
        $uploadDirGallery = FCPATH . 'public/uploads/events/gallery_images/';

        if (!is_dir($uploadDirPoster))
            mkdir($uploadDirPoster, 0755, true);
        if (!is_dir($uploadDirGallery))
            mkdir($uploadDirGallery, 0755, true);

        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 10 * 1024 * 1024;

        $posterFilePath = null;
        $galleryFilePaths = [];

        // Poster upload
        if (isset($_FILES['poster_image']) && $_FILES['poster_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['poster_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt))
                respond(false, "Poster image type not allowed.");
            if ($_FILES['poster_image']['size'] > $maxSize)
                respond(false, "Poster image too large.");

            $newName = uniqid('poster_', true) . '.' . $ext;
            $targetPath = $uploadDirPoster . $newName;

            if (move_uploaded_file($_FILES['poster_image']['tmp_name'], $targetPath)) {
                // Store ONLY file name
                $posterFilePath = $newName;
            }
        }

        // Gallery upload
        if (!empty($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['name'])) {
            for ($i = 0; $i < count($_FILES['gallery_images']['name']); $i++) {
                if ($_FILES['gallery_images']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['gallery_images']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt))
                        continue;
                    if ($_FILES['gallery_images']['size'][$i] > $maxSize)
                        continue;

                    $newName = uniqid('gallery_', true) . '.' . $ext;
                    $targetPath = $uploadDirGallery . $newName;

                    if (move_uploaded_file($_FILES['gallery_images']['tmp_name'][$i], $targetPath)) {
                        // Store ONLY file name
                        $galleryFilePaths[] = $newName;
                    }
                }
            }
        }

        // Store only names
        $event['poster_image'] = $posterFilePath;
        $event['gallery_images'] = json_encode($galleryFilePaths);


        // ---------------- SAVE EVENT ----------------
        $db = \Config\Database::connect();

        $db->table('events')->insert($event);
        $eventId = $db->insertID();  // newly created event_id

        if (!$eventId) {
            respond(false, "Failed to create event.");
        }
        unset($event['host_id']);
        unset($event['tag_id']);
        // ---------------- RESPONSE ----------------
        respond(true, 'Event created successfully.', [
            'event_id' => $eventId,
            'event' => $event,
            'hosts' => $hostData,
            'tags' => $tagData,
        ]);
    }


    public function listEvents($search = '')
    {
        $page = (int) $this->request->getGet('current_page') ?: 1;
        $limit = (int) $this->request->getGet('per_page') ?: 10;

        $search = $search ?: ($this->request->getGet('keyword') ?? $this->request->getGet('search'));

        $offset = ($page - 1) * $limit;

        // Base query
        $builder = $this->eventModel->where('status !=', 4);

        // Search filter
        if (!empty($search)) {
            $builder->groupStart()
                ->like('event_name', $search)
                ->orLike('event_city', $search)
                ->orLike('event_location', $search)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);

        $events = $builder
            ->orderBy('event_id', 'DESC')
            ->findAll($limit, $offset);

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
                'events' => $events
            ]
        ]);
    }

    // Update Event
    public function update()
    {
        // Detect request type
        $contentType = $this->request->getHeaderLine('Content-Type');
        $isJson = strpos($contentType, 'application/json') !== false;

        $json = [];
        if ($isJson) {
            $json = $this->request->getJSON(true);
        }

        // Get event ID
        $id = $isJson
            ? ($json['id'] ?? $json['event_id'] ?? null)
            : ($this->request->getPost('id') ?? $this->request->getPost('event_id'));

        if (empty($id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event ID is required'
            ]);
        }

        // Fetch existing event
        $event = $this->eventModel->find($id);
        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        // Collect fields
        $fields = [
            'event_name',
            'event_description',
            'event_location',
            'event_city',
            'event_map',
            'event_date_start',
            'event_date_end',
            'event_time_start',
            'event_time_end',
            'dress_code',
            'age_limit',
            'host_id',
            'tag_id',
            'total_seats',
            'status',
            'event_type'
        ];

        $data = [];
        foreach ($fields as $field) {
            $value = $isJson ? ($json[$field] ?? null) : $this->request->getPost($field);
            if ($value !== null && $value !== '') {
                // Convert dates/times properly
                if (in_array($field, ['event_date_start', 'event_date_end'])) {
                    $value = date('Y-m-d', strtotime($value));
                } elseif (in_array($field, ['event_time_start', 'event_time_end'])) {
                    $value = date('H:i:s', strtotime($value));
                }
                $data[$field] = $value;
            }
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        // Only handle file uploads if form-data
        if (!$isJson) {
            // Handle poster image
            $poster = $this->request->getFile('poster_image');
            if ($poster && $poster->isValid() && !$poster->hasMoved()) {
                $uploadPath = FCPATH . 'public/uploads/events/poster_images/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0777, true);
                }

                $newName = 'poster_' . time() . '_' . $poster->getRandomName();
                $poster->move($uploadPath, $newName);
                $data['poster_image'] = $newName;
            }

            // Handle gallery images
            $galleryImages = $this->request->getFiles()['gallery_images'] ?? [];
            $galleryNames = [];

            if (!empty($galleryImages)) {
                $uploadPath = FCPATH . 'public/uploads/events/gallery_images/';
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0777, true);
                }

                foreach ($galleryImages as $file) {
                    if ($file->isValid() && !$file->hasMoved()) {
                        $random = substr(md5(uniqid()), 0, 6);
                        $ext = $file->getExtension();
                        $newName = 'gallery_' . time() . '_' . $random . '.' . $ext;
                        $file->move($uploadPath, $newName);
                        $galleryNames[] = $newName;
                    }
                }

                if (!empty($galleryNames)) {
                    $data['gallery_images'] = json_encode($galleryNames);
                }
            }
        }

        // Update event in DB
        $updated = $this->eventModel->update($id, $data);

        if ($updated) {
            $updatedEvent = $this->eventModel->find($id);
            return $this->response->setJSON([
                'status' => true,
                'message' => 'Event updated successfully',
                'data' => $updatedEvent
            ]);
        }

        return $this->response->setJSON([
            'status' => false,
            'message' => 'No valid data to update or update failed'
        ]);
    }
    // Delete Event
    public function delete()
    {
        $input = $this->request->getJSON(true);

        $id = $input['id']
            ?? $input['event_id']
            ?? $this->request->getPost('id')
            ?? $this->request->getPost('event_id');

        if (empty($id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event ID is required'
            ]);
        }

        if ($this->eventModel->delete($id)) {
            return $this->response->setJSON([
                'status' => true,
                'message' => 'Event deleted successfully'
            ]);
        }

        return $this->response->setJSON([
            'status' => false,
            'message' => 'Failed to delete event'
        ]);
    }
}
