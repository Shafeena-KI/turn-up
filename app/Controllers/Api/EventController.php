<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventModel;

class EventController extends BaseController
{
    protected $eventModel;

    public function __construct()
    {
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
        $data = [
            'event_name' => $this->request->getVar('event_name'),
            'event_description' => $this->request->getVar('event_description'),
            'event_location' => $this->request->getVar('event_location'),
            'event_map' => $this->request->getVar('event_map'),
            'event_date_start' => date('Y-m-d', strtotime($this->request->getVar('event_date_start'))),
            'event_date_end' => date('Y-m-d', strtotime($this->request->getVar('event_date_end'))),
            'event_time_start' => date('H:i:s', strtotime($this->request->getVar('event_time_start'))),
            'event_time_end' => date('H:i:s', strtotime($this->request->getVar('event_time_end'))),
            'dress_code' => $this->request->getVar('dress_code'),
            'age_limit' => $this->request->getVar('age_limit'),
            'host_id' => $this->request->getVar('host_id'),
            'tag_id' => $this->request->getVar('tag_id'),
            'total_seats' => $this->request->getVar('total_seats'),
            'status' => $this->request->getVar('status'),
            'event_type' => $this->request->getVar('event_type'),
            'created_by' => $this->request->getVar('created_by'),
        ];

        // Handle poster image
        $poster = $this->request->getFile('poster_image');
        if ($poster && $poster->isValid() && !$poster->hasMoved()) {
            $newName = $poster->getRandomName();
            $poster->move(FCPATH . 'public/uploads/events/poster_images', $newName);
            $data['poster_image'] = $newName;
        }

        // Handle gallery images
        $galleryImages = $this->request->getFiles()['gallery_images'] ?? [];
        $galleryNames = [];

        foreach ($galleryImages as $file) {
            if ($file->isValid() && !$file->hasMoved()) {
                $newName = $file->getRandomName();
                $file->move(FCPATH . 'public/uploads/events/gallery_images', $newName);
                $galleryNames[] = $newName;
            }
        }

        $data['gallery_images'] = implode(',', $galleryNames);

        //  Insert event
        $inserted = $this->eventModel->insert($data);

        if ($inserted) {
            // Get the inserted ID and fetch full event data
            $eventId = $this->eventModel->getInsertID();
            $eventData = $this->eventModel->find($eventId);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Event created successfully',
                'data' => $eventData  //  Send back the full event info
            ]);
        }

        return $this->response->setJSON([
            'status' => false,
            'message' => 'Failed to create event'
        ]);
    }


    public function update()
    {
        // Determine content type before reading input
        $contentType = $this->request->getHeaderLine('Content-Type');
        $isJson = strpos($contentType, 'application/json') !== false;

        $json = [];
        if ($isJson) {
            $json = $this->request->getJSON(true);
        }

        // Get Event ID (from JSON or form-data)
        $id = $isJson
            ? ($json['id'] ?? $json['event_id'] ?? null)
            : ($this->request->getPost('id') ?? $this->request->getPost('event_id'));

        if (empty($id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event ID is required'
            ]);
        }

        // Check if event exists
        $event = $this->eventModel->find($id);
        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        // Collect updated fields
        $fields = [
            'event_name',
            'event_description',
            'event_location',
            'event_map',
            'event_date_start',
            'event_date_end',
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
            $data[$field] = $isJson ? ($json[$field] ?? null) : $this->request->getPost($field);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        // Remove empty fields
        $data = array_filter($data, fn($v) => $v !== null && $v !== '');

        // Handle file uploads (only for form-data)
        if (!$isJson) {
            // Poster image
            $poster = $this->request->getFile('poster_image');
            if ($poster && $poster->isValid() && !$poster->hasMoved()) {
                $newName = $poster->getRandomName();
                $poster->move(FCPATH . 'uploads/events', $newName);
                $data['poster_image'] = $newName;
            }

            // Gallery images
            $galleryImages = $this->request->getFiles()['gallery_images'] ?? [];
            $galleryNames = [];
            foreach ($galleryImages as $file) {
                if ($file->isValid() && !$file->hasMoved()) {
                    $newName = $file->getRandomName();
                    $file->move(FCPATH . 'uploads/events/gallery', $newName);
                    $galleryNames[] = $newName;
                }
            }
            if (!empty($galleryNames)) {
                $data['gallery_images'] = implode(',', $galleryNames);
            }
        }

        // Update event
        if (!empty($data) && $this->eventModel->update($id, $data)) {
            return $this->response->setJSON([
                'status' => true,
                'message' => 'Event updated successfully'
            ]);
        }

        return $this->response->setJSON([
            'status' => false,
            'message' => 'No valid data to update or update failed'
        ]);
    }





    // ✅ Delete Event
    public function delete()
    {
        // $id = $this->request->getVar('id'); // or getJSON()->id for raw JSON input
        $id = $this->request->getVar('id') ?? $this->request->getVar('event_id');

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
