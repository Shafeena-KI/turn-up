<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use Config\Database;

class Dashboard extends BaseController
{
    protected $db;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->db = Database::connect();
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
    protected function validateToken()
    {
        $token = $this->getToken();

        // Token is OPTIONAL → validate only if provided
        if ($token) {
            $user = $this->db->table('admin_users')
                ->where('token', trim($token))
                ->get()
                ->getRow();

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

        return true; // token valid OR not provided
    }

    // GET TOTAL USERS COUNT
    public function getTotalUsers()
    {
        $auth = $this->validateToken();
        if ($auth !== true)
            return $auth;
        $count = $this->db->table('app_users')->countAllResults();

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Total users count fetched successfully',
            'total_users' => $count
        ]);
    }
    public function getTotalEvents()
    {
        $auth = $this->validateToken();
        if ($auth !== true)
            return $auth;
        $count = $this->db->table('events')
            ->whereNotIn('status', [3, 4]) // exclude status 3 & 4
            ->countAllResults();

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Total events count fetched successfully',
            'total_events' => $count
        ]);
    }
    public function getTotalApprovedInvites()
    {
        $auth = $this->validateToken();
        if ($auth !== true)
            return $auth;
        // Sum total approved invites from event_counts table
        $result = $this->db->table('event_counts')
            ->selectSum('total_approved', 'total_approved')
            ->get()
            ->getRow();

        $totalApproved = $result->total_approved ?? 0;

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Total approved invites count fetched successfully',
            'total_approved_invites' => (int) $totalApproved
        ]);
    }
    public function getTotalBookings()
    {
        $auth = $this->validateToken();
        if ($auth !== true)
            return $auth;
        $result = $this->db->table('event_counts')
            ->selectSum('total_booking', 'total_bookings')
            ->get()
            ->getRow();

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Total bookings fetched successfully',
            'total_bookings' => (int) ($result->total_bookings ?? 0)
        ]);
    }

    public function getUpcomingEventsDetails()
    {
        $auth = $this->validateToken();
        if ($auth !== true)
            return $auth;
        $events = $this->db->table('events')
            ->where('status', 1)
            ->orderBy('event_date_start', 'ASC')
            ->get()
            ->getResult();

        // Base URLs
        $posterBaseURL = base_url('public/uploads/events/poster_images/');
        $galleryBaseURL = base_url('public/uploads/events/gallery_images/');

        foreach ($events as $event) {

            // ---- POSTER IMAGE ----
            $event->poster_image = $event->poster_image
                ? $posterBaseURL . $event->poster_image
                : null;

            // ---- GALLERY IMAGES ----
            // gallery_images is stored as JSON string → convert to array
            $galleryArray = json_decode($event->gallery_images, true);

            if (is_array($galleryArray) && count($galleryArray) > 0) {
                $fullGallery = [];
                foreach ($galleryArray as $img) {
                    $fullGallery[] = $galleryBaseURL . $img;
                }
                $event->gallery_images = $fullGallery;
            } else {
                $event->gallery_images = [];
            }
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Upcoming events fetched successfully',
            'data' => $events
        ]);
    }

}
