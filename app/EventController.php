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
        $this->db = \Config\Database::connect();
        helper(['form', 'url']);
    }
    //  Get All Events
    public function index()
    {
        $events = $this->eventModel->findAll();

        // Base URL for images
        $baseUrl = base_url('public/uploads/events/');

        foreach ($events as &$event) {

            // --- POSTER IMAGE FULL URL ---
            $event['poster_image'] = !empty($event['poster_image'])
                ? $baseUrl . 'poster_images/' . $event['poster_image']
                : null;

            // --- GALLERY IMAGES FULL URLs ---
            $gallery = json_decode($event['gallery_images'], true);

            if (is_array($gallery)) {
                $event['gallery_images'] = array_map(function ($img) use ($baseUrl) {
                    return $baseUrl . 'gallery_images/' . $img;
                }, $gallery);
            } else {
                $event['gallery_images'] = [];
            }
            // --- HOST ID CLEAN ARRAY ---
            $hostIDs = json_decode($event['host_id'], true);
            $event['host_id'] = is_array($hostIDs)
                ? array_map('intval', $hostIDs)
                : [];

            // --- TAG ID CLEAN ARRAY ---
            $tagIDs = json_decode($event['tag_id'], true);
            $event['tag_id'] = is_array($tagIDs)
                ? array_map('intval', $tagIDs)
                : [];
            // --- TICKET CATEGORIES (MULTIPLE) ---
            $ticketCategories = $this->db->table('event_ticket_category')
                ->select('category_name, price')
                ->where('event_id', $event['event_id'])
                ->where('status', 1)
                ->get()
                ->getResultArray();

            // Add categories to event response
            $event['ticket_categories'] = $ticketCategories;
            // --- TOTAL BOOKINGS FROM event_counts TABLE ---
            $eventCounts = $this->db->table('event_counts')
                ->select('total_booking')
                ->where('event_id', $event['event_id'])
                ->get()
                ->getRowArray();

            $event['total_booking'] = $eventCounts['total_booking'] ?? 0;
        }

        return $this->response->setJSON([
            'status' => true,
            'data' => $events
        ], JSON_UNESCAPED_SLASHES);
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

        // Base URL for images
        $baseUrl = base_url('public/uploads/events/');

        // --- POSTER IMAGE FULL URL ---
        $event['poster_image'] = !empty($event['poster_image'])
            ? $baseUrl . 'poster_images/' . $event['poster_image']
            : null;

        // --- GALLERY IMAGES FULL URLs ---
        $gallery = json_decode($event['gallery_images'], true);

        $event['gallery_images'] = is_array($gallery)
            ? array_map(fn($img) => $baseUrl . 'gallery_images/' . $img, $gallery)
            : [];

        // --- HOST DETAILS ---
        $hostIDs = json_decode($event['host_id'], true);
        $hostIDs = is_array($hostIDs) ? $hostIDs : [];

        $hosts = [];
        if (!empty($hostIDs)) {
            $hosts = $this->db->table('hosts')
                ->whereIn('host_id', $hostIDs)
                ->get()
                ->getResultArray();

            // Add full image URL for host_image
            foreach ($hosts as &$host) {
                $host['host_image'] = !empty($host['host_image'])
                    ? base_url('public/uploads/host_images/') . $host['host_image']
                    : null;
            }
        }

        // --- TAG DETAILS ---
        $tagIDs = json_decode($event['tag_id'], true);
        $tagIDs = is_array($tagIDs) ? $tagIDs : [];

        $tags = [];
        if (!empty($tagIDs)) {
            $tags = $this->db->table('event_tags')
                ->whereIn('tag_id', $tagIDs)
                ->get()
                ->getResultArray();
        }
        // --- TICKET CATEGORIES (MULTIPLE) ---
        $ticketCategories = $this->db->table('event_ticket_category')
            ->select('category_name, price')
            ->where('event_id', $id)
            ->where('status', 1)
            ->get()
            ->getResultArray();

        // Add categories to event response
        $event['ticket_categories'] = $ticketCategories;

        // --- TOTAL BOOKINGS FROM event_counts TABLE ---
        $eventCounts = $this->db->table('event_counts')
            ->select('total_booking')
            ->where('event_id', $id)
            ->get()
            ->getRowArray();

        $event['total_booking'] = $eventCounts['total_booking'] ?? 0; // Default 0 if no record found

        unset(
            $event['host_id'],
            $event['tag_id'],
            $event['hosts'],
            $event['tags']
        );
        // Add host and tag data into event response
        $event['hosts'] = $hosts;
        $event['tags'] = $tags;

        return $this->response->setJSON([
            'status' => true,
            'data' => $event
        ], JSON_UNESCAPED_SLASHES);
    }

    // Create New Event
    public function create()
    {
        // ---------------- PRE-FLIGHT CORS ----------------

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Headers: Content-Type, Authorization");
            header("Access-Control-Allow-Methods: POST, OPTIONS");
            http_response_code(200);
            exit();
        }

        // ---------------- JSON RESPONSE FUNCTION ----------------

        function respond($success, $message, $data = null, $code = 200)
        {
            header('Content-Type: application/json');
            http_response_code($code);
            echo json_encode([
                'status' => $success,
                'message' => $message,
                'data' => $data
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (empty($_POST) && empty($_FILES)) {
            respond(false, "Invalid request. Use multipart/form-data.");
        }

        $event = [
            'event_name' => $_POST['event_name'] ?? '',
            'event_description' => $_POST['event_description'] ?? '',
            'event_location' => $_POST['event_location'] ?? '',
            'event_map' => $_POST['event_map'] ?? '',
            'event_date_start' => $_POST['event_date_start'] ?? '',
            'event_date_end' => $_POST['event_date_end'] ?? '',
            'dress_code' => $_POST['dress_code'] ?? '',
            'event_code' => $_POST['event_code'] ?? '',
            'whatsappmessage_code' => $_POST['whatsappmessage_code'] ?? '',
            'age_limit' => $_POST['age_limit'] ?? '',
            'event_type' => $_POST['event_type'] ?? '',
            'created_by' => $_POST['created_by'] ?? '',
            'event_time_start' => $_POST['event_time_start'] ?? '',
            'event_time_end' => $_POST['event_time_end'] ?? '',
            'event_city' => $_POST['event_city'] ?? '',
            'total_seats' => $_POST['total_seats'] ?? '',
        ];
        // Auto-set status based on event_date_start
        $startDate = strtotime($_POST['event_date_start'] ?? '');
        $today = strtotime(date("Y-m-d"));

        if ($startDate > $today) {
            $event['status'] = 1;  // Upcoming
        } elseif ($startDate == $today) {
            $event['status'] = 1;  // Today = Upcoming
        } elseif ($startDate < $today) {
            $event['status'] = 2;  // Completed
        }

        // ---------------- HOST ID ARRAY ----------------

        $hostRaw = $_POST['host_id'] ?? null;
        if (empty($hostRaw)) {
            $hostIDs = [];
        } elseif (is_array($hostRaw)) {
            $hostIDs = $hostRaw;
        } elseif (json_decode($hostRaw, true)) {
            $hostIDs = json_decode($hostRaw, true);
        } else {
            $hostIDs = explode(',', $hostRaw);
        }
        $hostIDs = array_map('intval', $hostIDs);
        $event['host_id'] = json_encode($hostIDs);

        // ---------------- TAG ID ARRAY ----------------
        $tagRaw = $_POST['tag_id'] ?? null;
        if (empty($tagRaw)) {
            $tagIDs = [];
        } elseif (is_array($tagRaw)) {
            // Case: tag_id[] = 1, tag_id[] = 2
            $tagIDs = $tagRaw;
        } elseif (json_decode($tagRaw, true) !== null) {
            // Case: tag_id = [1,2,3]
            $tagIDs = json_decode($tagRaw, true);
        } else {
            // Case: tag_id = "1,2,3"
            $tagIDs = explode(',', $tagRaw);
        }
        // Convert all to integers
        $tagIDs = array_map('intval', $tagIDs);
        // Save to DB
        $event['tag_id'] = json_encode($tagIDs);

        // ---------------- FILE UPLOAD SETTINGS ----------------

        $uploadDirPoster = FCPATH . 'public/uploads/events/poster_images/';

        $uploadDirGallery = FCPATH . 'public/uploads/events/gallery_images/';

        if (!is_dir($uploadDirPoster))
            mkdir($uploadDirPoster, 0755, true);

        if (!is_dir($uploadDirGallery))
            mkdir($uploadDirGallery, 0755, true);
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 10 * 1024 * 1024;
        $posterFile = null;
        $galleryFiles = [];

        // ---------------- POSTER UPLOAD ----------------

        if (!empty($_FILES['poster_image']) && $_FILES['poster_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['poster_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt))
                respond(false, "Invalid poster image type.");
            if ($_FILES['poster_image']['size'] > $maxSize)
                respond(false, "Poster too large.");
            $newName = uniqid('poster_', true) . '.' . $ext;
            $target = $uploadDirPoster . $newName;
            if (move_uploaded_file($_FILES['poster_image']['tmp_name'], $target)) {
                $posterFile = $newName;
            }
        }
        // ---------------- GALLERY UPLOAD ----------------

        if (!empty($_FILES['gallery_images']['name']) && is_array($_FILES['gallery_images']['name'])) {
            for ($i = 0; $i < count($_FILES['gallery_images']['name']); $i++) {
                if ($_FILES['gallery_images']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['gallery_images']['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt))
                        continue;
                    if ($_FILES['gallery_images']['size'][$i] > $maxSize)
                        continue;
                    $newName = uniqid('gallery_', true) . '.' . $ext;
                    $target = $uploadDirGallery . $newName;
                    if (move_uploaded_file($_FILES['gallery_images']['tmp_name'][$i], $target)) {
                        $galleryFiles[] = $newName;
                    }
                }
            }
        }
        // Store as raw array (NOT JSON string)
        $event['poster_image'] = $posterFile;
        $event['gallery_images'] = json_encode($galleryFiles);
        // ---------------- SAVE EVENT ----------------

        $db = \Config\Database::connect();
        $db->table('events')->insert($event);
        $eventId = $db->insertID();
        if (!$eventId)
            respond(false, "Failed to create event.");

        // ---------------- FINAL RESPONSE ----------------

        $baseUrl = base_url('public/uploads/events/');
        respond(true, "Event created successfully.", [
            "event_id" => $eventId,
            // FULL URL RETURN
            "poster_image" => $posterFile
                ? $baseUrl . "poster_images/" . $posterFile
                : null,
            "gallery_images" => array_map(function ($img) use ($baseUrl) {
                return $baseUrl . "gallery_images/" . $img;
            }, $galleryFiles),

            // EVENT FIELDS
            "event_name" => $event['event_name'],
            "event_description" => $event['event_description'],
            "event_location" => $event['event_location'],
            "event_map" => $event['event_map'],
            "event_date_start" => $event['event_date_start'],
            "event_date_end" => $event['event_date_end'],
            "dress_code" => $event['dress_code'],
            "event_code" => $event['event_code'],
            "whatsappmessage_code" => $event['whatsappmessage_code'],
            "age_limit" => $event['age_limit'],
            "event_type" => $event['event_type'],
            "created_by" => $event['created_by'],
            "status" => $event['status'],
            "event_time_start" => $event['event_time_start'],
            "event_time_end" => $event['event_time_end'],
            "event_city" => $event['event_city'],
            "total_seats" => $event['total_seats'],
            // JSON STRING FORM
            "host_id" => $hostIDs,
            "tag_id" => $tagIDs,
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

        // Base URL
        $baseUrl = base_url('public/uploads/events/');
        // Add Full Image URLs
        foreach ($events as &$event) {
            // Poster image full URL
            if (!empty($event['poster_image'])) {
                $event['poster_image'] = $baseUrl . 'poster_images/' . $event['poster_image'];
            } else {
                $event['poster_image'] = null;
            }
            // Gallery images full URLs
            $gallery = json_decode($event['gallery_images'], true);
            if (is_array($gallery)) {
                $event['gallery_images'] = array_map(function ($img) use ($baseUrl) {
                    return $baseUrl . 'gallery_images/' . $img;
                }, $gallery);
            } else {
                $event['gallery_images'] = [];
            }
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
                'events' => $events
            ]
        ], JSON_UNESCAPED_SLASHES);
    }
    // Update Event
    public function update()
    {
        $contentType = $this->request->getHeaderLine('Content-Type');
        $isJson = strpos($contentType, 'application/json') !== false;

        $json = $isJson ? $this->request->getJSON(true) : [];

        $id = $isJson
            ? ($json['id'] ?? $json['event_id'] ?? null)
            : ($this->request->getPost('id') ?? $this->request->getPost('event_id'));

        if (empty($id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event ID is required'
            ]);
        }

        $event = $this->eventModel->find($id);
        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        // Fields
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
            'event_code',
            'whatsappmessage_code',
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
                if (in_array($field, ['event_date_start', 'event_date_end'])) {
                    $value = date('Y-m-d', strtotime($value));
                } elseif (in_array($field, ['event_time_start', 'event_time_end'])) {
                    $value = date('H:i:s', strtotime($value));
                }
                $data[$field] = $value;
            }
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        // Handle uploads only for form-data
        if (!$isJson) {

            // Poster
            $poster = $this->request->getFile('poster_image');
            if ($poster && $poster->isValid() && !$poster->hasMoved()) {
                $uploadPath = FCPATH . 'public/uploads/events/poster_images/';
                if (!is_dir($uploadPath))
                    mkdir($uploadPath, 0777, true);

                $newName = 'poster_' . time() . '_' . $poster->getRandomName();
                $poster->move($uploadPath, $newName);
                $data['poster_image'] = $newName;
            }

            // Gallery
            $galleryImages = $this->request->getFiles()['gallery_images'] ?? [];
            $galleryNames = [];

            if (!empty($galleryImages)) {
                $uploadPath = FCPATH . 'public/uploads/events/gallery_images/';
                if (!is_dir($uploadPath))
                    mkdir($uploadPath, 0777, true);

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

        // Update event
        $updated = $this->eventModel->update($id, $data);

        if ($updated) {
            $updatedEvent = $this->eventModel->find($id);

            // ADD FULL PATHS HERE
            $baseUrl = base_url('public/uploads/events/');

            // Full poster image path
            $updatedEvent['poster_image'] = $updatedEvent['poster_image']
                ? $baseUrl . 'poster_images/' . $updatedEvent['poster_image']
                : null;

            // Full gallery images path
            $gallery = json_decode($updatedEvent['gallery_images'], true);
            $fullGallery = [];

            if (!empty($gallery)) {
                foreach ($gallery as $img) {
                    $fullGallery[] = $baseUrl . 'gallery_images/' . $img;
                }
            }

            $updatedEvent['gallery_images'] = $fullGallery;

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Event updated successfully',
                'data' => $updatedEvent
            ]);
        }

        return $this->response->setJSON([
            'status' => false,
            'message' => 'Update failed'
        ]);
    }
    // Delete Event
    public function delete($id = null)
    {
        if (empty($id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event ID is required'
            ]);
        }

        $updated = $this->eventModel->update($id, ['status' => 4]);

        return $this->response->setJSON([
            'status' => (bool) $updated,
            'message' => $updated ? 'Event deleted successfully' : 'Failed to delete event'
        ]);
    }


}
