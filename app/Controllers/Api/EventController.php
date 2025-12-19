<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventModel;
use CodeIgniter\API\ResponseTrait;
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
    //  Get All Events
    public function index()
    {
        $token = $this->getToken();

        if ($token) {

            $user = $this->db->table('app_users')
                ->select('user_id, token')
                ->where('token', trim($token))
                ->get()
                ->getRowArray();

            // If token exists but no matching user → token invalid
            if (!$user || $user['token'] != trim($token)) {
                return $this->response
                    ->setStatusCode(401)
                    ->setJSON([
                        'status' => 401,
                        'success' => false,
                        'message' => 'Invalid or expired token.'
                    ]);
            }

            // Set the valid token user_id
            $loggedUserId = $user['user_id'];
        }


        // No token → skip authentication

        $events = $this->eventModel
            ->whereIn('status', [1, 2, 3]) // upcoming, completed, cancelled
            ->orderBy('status', 'ASC')
            ->orderBy('event_date_start', 'ASC')
            ->findAll();


        $baseUrl = base_url('public/uploads/events/');
        $this->eventModel->updateEventStatuses();
        foreach ($events as &$event) {

            // AUTO UPDATE EVENT STATUS 
            // $startDate = strtotime($event['event_date_start']);
            // $endDate = strtotime($event['event_date_end']);
            // $currentStatus = $event['status'];
            // $newStatus = $currentStatus;

            // if ($endDate < $today) {
            //     $newStatus = 2;
            // } elseif ($startDate > $today) {
            //     $newStatus = 1;
            // } elseif ($startDate <= $today && $endDate >= $today) {
            //     $newStatus = 1;
            // }

            // if ($newStatus != $currentStatus) {
            //     $this->db->table('events')
            //         ->where('event_id', $event['event_id'])
            //         ->update(['status' => $newStatus]);
            //     $event['status'] = $newStatus;
            // }

            $event['poster_image'] = !empty($event['poster_image'])
                ? $baseUrl . 'poster_images/' . $event['poster_image']
                : null;

            $gallery = json_decode($event['gallery_images'], true);
            $event['gallery_images'] = is_array($gallery)
                ? array_map(fn($img) => $baseUrl . 'gallery_images/' . $img, $gallery)
                : [];

            $event['host_id'] = json_decode($event['host_id'], true) ?? [];
            $event['tag_id'] = json_decode($event['tag_id'], true) ?? [];

            $event['ticket_categories'] = $this->db->table('event_ticket_category')
                ->select('category_id, category_name, price, couple_price, dummy_invites, dummy_booked_seats')
                ->where('event_id', $event['event_id'])
                ->where('status', 1)
                ->get()
                ->getResultArray();


            // Fetch total_booking + total_invites
            $eventCounts = $this->db->table('event_counts')
                ->select('total_booking, total_invites')
                ->where('event_id', $event['event_id'])
                ->get()
                ->getRowArray();

            $event['total_booking'] = $eventCounts['total_booking'] ?? 0;
            $event['total_invites'] = $eventCounts['total_invites'] ?? 0;
        }
        return $this->response->setJSON([
            'status' => true,
            'data' => $events
        ], JSON_UNESCAPED_SLASHES);
    }
    //  Get Single Event by ID
    public function show($id)
    {
        $token = $this->getToken();

        if ($token) {

            $user = $this->db->table('app_users')
                ->select('user_id, token')
                ->where('token', trim($token))
                ->get()
                ->getRowArray();

            // If token exists but no matching user → token invalid
            if (!$user || $user['token'] != trim($token)) {
                return $this->response
                    ->setStatusCode(401)
                    ->setJSON([
                        'status' => 401,
                        'success' => false,
                        'message' => 'Invalid or expired token.'
                    ]);
            }

            // Set the valid token user_id
            $loggedUserId = $user['user_id'];
        }


        $event = $this->eventModel->find($id);

        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        $baseUrl = base_url('public/uploads/events/');

        $event['poster_image'] = !empty($event['poster_image'])
            ? $baseUrl . 'poster_images/' . $event['poster_image']
            : null;

        $gallery = json_decode($event['gallery_images'], true);
        $event['gallery_images'] = is_array($gallery)
            ? array_map(fn($img) => $baseUrl . 'gallery_images/' . $img, $gallery)
            : [];

        $hostIDs = json_decode($event['host_id'], true) ?? [];
        $tagIDs = json_decode($event['tag_id'], true) ?? [];

        $hosts = [];
        if (!empty($hostIDs)) {
            $hosts = $this->db->table('hosts')
                ->whereIn('host_id', $hostIDs)
                ->get()
                ->getResultArray();

            foreach ($hosts as &$host) {
                $host['host_image'] = !empty($host['host_image'])
                    ? base_url('public/uploads/host_images/') . $host['host_image']
                    : null;
            }
        }

        $tags = [];
        if (!empty($tagIDs)) {
            $tags = $this->db->table('event_tags')
                ->whereIn('tag_id', $tagIDs)
                ->get()
                ->getResultArray();
        }

        $event['ticket_categories'] = $this->db->table('event_ticket_category')
            ->select('category_id, category_name, price, couple_price, dummy_invites, dummy_booked_seats')
            ->where('event_id', $event['event_id'])
            ->where('status', 1)
            ->get()
            ->getResultArray();


        // Fetch total_booking + total_invites
        $eventCounts = $this->db->table('event_counts')
            ->select('total_booking, total_invites')
            ->where('event_id', $event['event_id'])
            ->get()
            ->getRowArray();

        $event['total_booking'] = $eventCounts['total_booking'] ?? 0;
        $event['total_invites'] = $eventCounts['total_invites'] ?? 0;

        unset($event['host_id'], $event['tag_id']);
        $event['hosts'] = $hosts;
        $event['tags'] = $tags;

        return $this->response->setJSON([
            'status' => true,
            'data' => $event
        ], JSON_UNESCAPED_SLASHES);
    }
    public function Adminshow($id)
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

        $event = $this->eventModel->find($id);

        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        $baseUrl = base_url('public/uploads/events/');

        $event['poster_image'] = !empty($event['poster_image'])
            ? $baseUrl . 'poster_images/' . $event['poster_image']
            : null;

        $gallery = json_decode($event['gallery_images'], true);
        $event['gallery_images'] = is_array($gallery)
            ? array_map(fn($img) => $baseUrl . 'gallery_images/' . $img, $gallery)
            : [];

        $hostIDs = json_decode($event['host_id'], true) ?? [];
        $tagIDs = json_decode($event['tag_id'], true) ?? [];

        $hosts = [];
        if (!empty($hostIDs)) {
            $hosts = $this->db->table('hosts')
                ->whereIn('host_id', $hostIDs)
                ->get()
                ->getResultArray();

            foreach ($hosts as &$host) {
                $host['host_image'] = !empty($host['host_image'])
                    ? base_url('public/uploads/host_images/') . $host['host_image']
                    : null;
            }
        }

        $tags = [];
        if (!empty($tagIDs)) {
            $tags = $this->db->table('event_tags')
                ->whereIn('tag_id', $tagIDs)
                ->get()
                ->getResultArray();
        }

        $event['ticket_categories'] = $this->db->table('event_ticket_category')
            ->select('category_name, price')
            ->where('event_id', $id)
            ->where('status', 1)
            ->get()
            ->getResultArray();

        $eventCounts = $this->db->table('event_counts')
            ->select('total_booking')
            ->where('event_id', $id)
            ->get()
            ->getRowArray();

        $event['total_booking'] = $eventCounts['total_booking'] ?? 0;

        unset($event['host_id'], $event['tag_id']);
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

        $eventDateStart = !empty($_POST['event_date_start'])
            ? date('Y-m-d', strtotime($_POST['event_date_start']))
            : null;

        $eventDateEnd = !empty($_POST['event_date_end'])
            ? date('Y-m-d', strtotime($_POST['event_date_end']))
            : null;

        $event = [
            'event_name' => $_POST['event_name'] ?? null,
            'event_description' => $_POST['event_description'] ?? null,
            'event_location' => $_POST['event_location'] ?? null,
            'event_map' => $_POST['event_map'] ?? null,
            'event_date_start' => $eventDateStart,
            'event_date_end' => $eventDateEnd,
            'dress_code' => $_POST['dress_code'] ?? null,
            'event_code' => !empty($_POST['event_code']) ? strtoupper(trim($_POST['event_code'])) : null,
            'whatsappmessage_code' => $_POST['whatsappmessage_code'] ?? null,
            'age_limit' => $_POST['age_limit'] ?? null,
            'event_type' => $_POST['event_type'] ?? null,
            'created_by' => $_POST['created_by'] ?? null,
            'event_time_start' => !empty($_POST['event_time_start'])
                ? date('H:i:s', strtotime($_POST['event_time_start']))
                : null,
            'event_time_end' => !empty($_POST['event_time_end'])
                ? date('H:i:s', strtotime($_POST['event_time_end']))
                : null,
            'event_city' => $_POST['event_city'] ?? null,
            'total_seats' => $_POST['total_seats'] ?? null,
            'status' => 1, // Default to Upcoming
        ];

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

        // COUNT QUERY 
        $dataBuilder = $this->eventModel->builder();
        $dataBuilder->where('status !=', 4);
        if (!empty($search)) {
            $dataBuilder->groupStart()
                ->like('event_name', $search)
                ->orLike('event_city', $search)
                ->orLike('event_location', $search)
                ->groupEnd();
        }

        $total = $dataBuilder->countAllResults();

        // ---------- DATA QUERY ----------
        $dataBuilder = $this->eventModel->where('status !=', 4);

        if (!empty($search)) {
            $dataBuilder->groupStart()
                ->like('event_name', $search)
                ->orLike('event_city', $search)
                ->orLike('event_location', $search)
                ->groupEnd();
        }

        $events = $dataBuilder
            ->orderBy('event_id', 'DESC')
            ->findAll($limit, $offset);

        // ---------- FORMAT DATA ----------
        $baseUrl = base_url('public/uploads/events/');

        foreach ($events as &$event) {

            $event['poster_image'] = !empty($event['poster_image'])
                ? $baseUrl . 'poster_images/' . $event['poster_image']
                : null;

            $gallery = json_decode($event['gallery_images'], true);
            $event['gallery_images'] = is_array($gallery)
                ? array_map(fn($img) => $baseUrl . 'gallery_images/' . $img, $gallery)
                : [];

            $event['ticket_categories'] = $this->db->table('event_ticket_category')
                ->select('category_name, price')
                ->where('event_id', $event['event_id'])
                ->where('status', 1)
                ->get()
                ->getResultArray();

            $eventCounts = $this->db->table('event_counts')
                ->selectSum('total_booking')
                ->where('event_id', $event['event_id'])
                ->get()
                ->getRowArray();

            $event['total_booking'] = (int) ($eventCounts['total_booking'] ?? 0);
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $limit,
                'keyword' => $search,
                'total_records' => $total,
                'total_pages' => ceil($total / $limit),
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

            // Skip null or empty string
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($field, ['event_date_start', 'event_date_end'])) {
                $timestamp = strtotime($value);
                if ($timestamp === false) {
                    continue; // invalid date → skip
                }
                $value = date('Y-m-d', $timestamp);
            }

            if (in_array($field, ['event_time_start', 'event_time_end'])) {
                $timestamp = strtotime($value);
                if ($timestamp === false) {
                    continue; // invalid time → skip
                }
                $value = date('H:i:s', $timestamp);
            }

            $data[$field] = $value;
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
            // Gallery
            $uploadPath = FCPATH . 'public/uploads/events/gallery_images/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            /**
             * OLD GALLERY FROM DB
             */
            $oldGallery = json_decode($event['gallery_images'], true);
            $oldGallery = is_array($oldGallery) ? $oldGallery : [];

            /**
             * EXISTING GALLERY FROM FRONTEND (URLs / filenames)
             * This comes from normal POST (not files)
             */
            $existingGallery = $this->request->getPost('gallery_images');

            /**
             * FORCE ARRAY
             */
            if ($existingGallery === null) {
                // User did not modify gallery
                $existingGallery = $oldGallery;
            } else {
                $existingGallery = (array) $existingGallery;

                // Normalize existing values (extract filename from URL)
                $existingGallery = array_map(function ($img) {
                    return basename($img);
                }, $existingGallery);

                /**
                 * DELETE REMOVED IMAGES
                 */
                $deletedImages = array_diff($oldGallery, $existingGallery);

                foreach ($deletedImages as $img) {
                    $filePath = $uploadPath . $img;
                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                }
            }

            /**
             * HANDLE NEW FILE UPLOADS (binary)
             */
            $newGallery = [];
            $uploadedFiles = $this->request->getFiles();
            $galleryFiles = $uploadedFiles['gallery_images'] ?? [];

            if (!empty($galleryFiles)) {
                foreach ($galleryFiles as $file) {

                    // Only process real uploaded files
                    if (is_object($file) && $file->isValid() && !$file->hasMoved()) {

                        $random = substr(md5(uniqid()), 0, 6);
                        $ext = $file->getExtension();
                        $newName = 'gallery_' . time() . '_' . $random . '.' . $ext;

                        $file->move($uploadPath, $newName);
                        $newGallery[] = $newName;
                    }
                }
            }

            /**
             * FINAL GALLERY (existing + new)
             */
            $finalGallery = array_values(array_unique(array_merge($existingGallery, $newGallery)));

            /**
             * SAVE TO DB
             */
            $data['gallery_images'] = json_encode($finalGallery);

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
        // CORS Headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        header("Access-Control-Allow-Methods: POST, OPTIONS");

        // Handle Preflight (OPTION) request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return $this->response->setStatusCode(200);
        }

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
