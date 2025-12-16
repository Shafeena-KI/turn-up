<?php

namespace App\Libraries;

use Config\Database;
use App\Models\Api\EventModel;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventCategoryModel;


class BookingLibrary
{
    protected $db;
    protected $inviteModel;
    protected $eventModel;
    protected $categoryModel;

    protected $categoryLibrary;
    protected $eventLibrary;
    protected $qrLibrary;
    protected $notificationLibrary;

    public function __construct()
    {

        $this->db = Database::connect();
        $this->inviteModel = new EventInviteModel();
        $this->categoryModel = new EventCategoryModel();
        $this->eventModel = new EventModel();

        $this->categoryLibrary = new CategoryLibrary();
        $this->eventLibrary = new EventLibrary();
        $this->qrLibrary = new QrLibrary();
        $this->notificationLibrary = new NotificationLibrary();
    }


    // Function to generate Booking Reference Code
    public function generateBookingCode($event_code = null, $new_booking_no = null)
    {
        $prefix = env('BOOKING_CODE_PREFIX') ?? 'BK';

        return $prefix . $event_code . str_pad($new_booking_no, 3, '0', STR_PAD_LEFT);
    }

    // Function to create Event Booked
    public function BookEvent($inviteId, $userId)
    {

        $invite = $this->inviteModel->getInviteDetails($inviteId, $userId);
        if (empty($invite)) {
            return ['success' => false, 'message' => 'Invite not found'];
        }

        $counterTable = $this->db->table('event_counters');

        // Fetching Category counts from event category library
        $category_counts = $this->categoryLibrary->categoryCount($invite->entry_type,$invite->partner ?? null);
        if (!$category_counts) {
            return ['success' => false, 'message' => 'Failed to calculate category counts'];
        }

        // GET COUNTER
        $counter = $counterTable->get()->getRow();
        if (!$counter) {
            return ['success' => false, 'message' => 'Booking counter not found'];
        }

        $new_booking_no = (int) $counter->last_booking_no + 1;

        // UPDATE COUNTER
        $counterTable->where('id', $counter->id)->update([
            'last_booking_no' => $new_booking_no,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // BOOKING CODE
        $event_code = $this->eventModel->getEventCodeById($invite->event_id);
        $booking_code = $this->generateBookingCode($event_code, $new_booking_no);

        // SAVE BOOKING
        $bookingData = [
            'invite_id' => $invite->invite_id,
            'event_id' => $invite->event_id,
            'user_id' => $invite->user_id,
            'category_id' => $invite->category_id,
            'booking_code' => $booking_code,
            'total_price' => $invite->price,
            'quantity' => $category_counts['invite_total'],
            'status' => EventBookingModel::BOOKED,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->db->table('event_booking')->insert($bookingData);
        $bookingId = $this->db->insertID();

        if (!$bookingId) {
            return ['success' => false, 'message' => 'Failed to create booking'];
        }

        // UPDATE COUNTS
        $countsTable = $this->db->table('event_counts');
        $eventCount = $countsTable
            ->where('event_id', $invite->event_id)
            ->where('category_id', $invite->category_id)
            ->get()->getRow();

        if ($eventCount) {
            $countsTable->where('id', $eventCount->id)->update([
                'total_booking' => $eventCount->total_booking + $category_counts['invite_total'],
                'total_male_booking' => $eventCount->total_male_booking + $category_counts['male_total'],
                'total_female_booking' => $eventCount->total_female_booking + $category_counts['female_total'],
                'total_other_booking' => $eventCount->total_other_booking + $category_counts['other_total'],
                'total_couple_booking' => $eventCount->total_couple_booking + $category_counts['couple_total'],
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            // If counts row was missing (unlikely here) insert with booking counts
            $countsTable->insert([
                'event_id' => $invite->event_id,
                'category_id' => $invite->category_id,
                'total_invites' => 0,
                'total_male_invites' => 0,
                'total_female_invites' => 0,
                'total_other_invites' => 0,
                'total_couple_invites' => 0,
                'total_booking' => $category_counts['invite_total'],
                'total_male_booking' => $category_counts['male_total'],
                'total_female_booking' => $category_counts['female_total'],
                'total_other_booking' => $category_counts['other_total'],
                'total_couple_booking' => $category_counts['couple_total'],
                'total_checkin' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        // UPDATE SEATS
        $this->updateCategorySeatsFromEventCounts($invite->event_id);

        // QR CODE
        $qr_url = $this->qrLibrary->createQrForBooking($booking_code);

        // Update QR code in booking
        $this->db->table('event_booking')->where('booking_id', $bookingId)->update(['qr_code' => $qr_url]);

        // GET USER
        $user = $this->db->table('app_users')
            ->where('user_id', $invite->user_id)
            ->get()->getRow();

        if ($user) {
            // SEND EVENT BOOKING CONFIRMATION
            $whatsappResponse = $this->notificationLibrary->sendEventQrWhatsapp(
                $user->phone,
                $user->name,
                $invite->event_name,
                $qr_url,          // ← QR URL you generated earlier
                $booking_code     // ← Your booking code variable
            );
        }

        return [
            'success' => true,
            'booking_id' => $bookingId,
            'booking_code' => $booking_code,
            'qr_code' => $qr_url
        ];
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