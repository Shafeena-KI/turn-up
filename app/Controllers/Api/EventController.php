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
            return $this->response->setJSON(['status' => false, 'message' => 'Event not found']);
        }
        return $this->response->setJSON(['status' => true, 'data' => $event]);
    }

    // Create New Event
    public function create()
    {
        //  Use getVar() instead of getPost() to handle form-data properly
        $data = [
            'event_name' => $this->request->getVar('event_name'),
            'event_description' => $this->request->getVar('event_description'),
            'event_location' => $this->request->getVar('event_location'),
            'event_map' => $this->request->getVar('event_map'),
            'event_date_start' => $this->request->getVar('event_date_start'),
            'event_date_end' => $this->request->getVar('event_date_end'),
            'dress_code' => $this->request->getVar('dress_code'),
            'age_limit' => $this->request->getVar('age_limit'),
            'host_id' => $this->request->getVar('host_id'),
            'tag_id' => $this->request->getVar('tag_id'),
            'total_seats' => $this->request->getVar('total_seats'),
            'status' => $this->request->getVar('status'),
            'created_by' => $this->request->getVar('created_by'),
        ];

        // ✅ Poster image
        $poster = $this->request->getFile('poster_image');
        if ($poster && $poster->isValid() && !$poster->hasMoved()) {
            $newName = $poster->getRandomName();
            $poster->move(FCPATH . 'uploads/events', $newName);
            $data['poster_image'] = $newName;
        }

        // ✅ Gallery images (optional)
        $galleryImages = $this->request->getFiles()['gallery_images'] ?? [];
        $galleryNames = [];
        foreach ($galleryImages as $file) {
            if ($file->isValid() && !$file->hasMoved()) {
                $newName = $file->getRandomName();
                $file->move(FCPATH . 'uploads/events/gallery', $newName);
                $galleryNames[] = $newName;
            }
        }

        $data['gallery_images'] = implode(',', $galleryNames);

        // ✅ Insert into database
        if ($this->eventModel->insert($data)) {
            return $this->response->setJSON(['status' => true, 'message' => 'Event created successfully']);
        }

        return $this->response->setJSON(['status' => false, 'message' => 'Failed to create event']);
    }





    public function update($id)
    {
        // Check if event exists
        $event = $this->eventModel->find($id);
        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        // Collect all possible fields
        $data = [
            'event_name' => $this->request->getVar('event_name'),
            'event_description' => $this->request->getVar('event_description'),
            'event_location' => $this->request->getVar('event_location'),
            'event_map' => $this->request->getVar('event_map'),
            'event_date_start' => $this->request->getVar('event_date_start'),
            'event_date_end' => $this->request->getVar('event_date_end'),
            'dress_code' => $this->request->getVar('dress_code'),
            'age_limit' => $this->request->getVar('age_limit'),
            'host_id' => $this->request->getVar('host_id'),
            'tag_id' => $this->request->getVar('tag_id'),
            'total_seats' => $this->request->getVar('total_seats'),
            'status' => $this->request->getVar('status'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Remove any null or empty values to prevent overwriting existing data
        $data = array_filter($data, fn($value) => $value !== null && $value !== '');

        // Handle Poster Image (if uploaded)
        $poster = $this->request->getFile('poster_image');
        if ($poster && $poster->isValid() && !$poster->hasMoved()) {
            $newName = $poster->getRandomName();
            $poster->move(FCPATH . 'uploads/events', $newName);
            $data['poster_image'] = $newName;
        }

        // Handle Gallery Images (if uploaded)
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

        // Update only non-empty fields
        if (!empty($data) && $this->eventModel->update($id, $data)) {
            return $this->response->setJSON(['status' => true, 'message' => 'Event updated successfully']);
        }

        return $this->response->setJSON(['status' => false, 'message' => 'No valid data to update or update failed']);
    }




    // ✅ Delete Event
    public function delete($id)
    {
        if ($this->eventModel->delete($id)) {
            return $this->response->setJSON(['status' => true, 'message' => 'Event deleted successfully']);
        }
        return $this->response->setJSON(['status' => false, 'message' => 'Failed to delete event']);
    }
}
