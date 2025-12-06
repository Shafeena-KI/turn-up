<?php

namespace App\Libraries;

use App\Models\Api\EventCategoryModel;
use Config\Database;

class EventLibrary
{
    protected $db;
    protected $categoryModel;
    
    public function __construct(){
        
        $this->db = Database::connect();
        $this->categoryModel = new EventCategoryModel();

    }

    public function updateCategorySeatsFromEventCounts($event_id)
    {
        // Get all categories for this event
        $categories = $this->categoryModel
            ->where('event_id', $event_id)
            ->findAll();

        foreach ($categories as $cat) {

            $catRowId = $cat['category_id'];                  // <-- REAL category row ID
            $categoryType = $cat['category_name'];   // <-- 1 (VIP) or 2 (Normal)
            $totalSeats = $cat['total_seats'];

            // Fetch category-wise total booking from event_counts
            $countData = $this->db->table('event_counts')
                ->select('total_booking')
                ->where('event_id', $event_id)
                ->where('category_id', $categoryType)   // match type
                ->get()
                ->getRowArray();

            // If no rows → no booking for this category
            $totalBooking = $countData['total_booking'] ?? 0;

            // Calculate balance
            $balance = $totalSeats - $totalBooking;
            if ($balance < 0)
                $balance = 0;

            // Update category table
            $this->categoryModel->update($catRowId, [
                'actual_booked_seats' => $totalBooking,
                'balance_seats' => $balance
            ]);
        }
    }
}