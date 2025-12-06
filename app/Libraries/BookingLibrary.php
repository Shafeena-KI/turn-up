<?php

namespace App\Libraries;

use Config\Database;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\EventInviteModel;
use App\Models\Api\inviteModel;

class BookingLibrary
{   
    protected $db;
    protected $inviteModel;
    protected $categoryLibrary;
    protected $eventLibrary;
    protected $qrLibrary;
    protected $notificationLibrary;

    public function __construct() {

        $this->db                   = Database::connect();
        $this->inviteModel          = new EventInviteModel();

        $this->categoryLibrary      = new CategoryLibrary();
        $this->eventLibrary         = new EventLibrary();
        $this->qrLibrary            = new QrLibrary();
        $this->notificationLibrary  = new NotificationLibrary();
    }

    // Function to generate Booking Reference Code
    public function generateBookingCode($event_code = null, $new_booking_no = null)
    {
       $prefix = env('BOOKING_CODE_PREFIX') ?? 'BK';

        return $prefix . $event_code . str_pad($new_booking_no, 3, '0', STR_PAD_LEFT);
    }

    // Function to create Event Booked
    public function BookEvent($inviteId, $userId) {

        $invite = $this->inviteModel->getInviteDetails($inviteId, $userId);
        if (empty($invite)) {
            return false;
        }

        // VIP AUTO APPROVE
        $inviteStatus = ($invite->category_id == EventCategoryModel::VIP_CATEGORY_CODE) ? 1 : 0;

        $counterTable = $this->db->table('event_counters');

        // // VIP INVITE  (AUTO BOOKING + send TWO messages)
        if ((int) $inviteStatus === 1) {

            // Fetching Category counts from event category Librarylibrary
            $category_counts = $this->categoryLibrary->categoryCount($invite->entry_type);
            if (!$category_counts) {
                return false;
            }


            // GET COUNTER
            $counter = $counterTable->get()->getRow();
            if (!$counter) {
                return false;
            }

            $new_booking_no = (int) $counter->last_booking_no + 1;

            // UPDATE COUNTER
            $counterTable->where('id', $counter->id)->update([
                'last_booking_no' => $new_booking_no,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // BOOKING CODE
            $booking_code = $this->generateBookingCode($invite->invite_code,$new_booking_no);

            // SAVE BOOKING
            $bookingData = [
                'invite_id' => $invite->invite_id,
                'event_id' => $invite->event_id,
                'user_id' => $invite->user_id,
                'category_id' => $invite->category_id,
                'booking_code' => $booking_code,
                'total_price' => $invite->price,
                'quantity' => $category_counts['invite_total'],
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $this->db->table('event_booking')->insert($bookingData);

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
            }

            // UPDATE SEATS
            $this->eventLibrary->updateCategorySeatsFromEventCounts($invite->event_id);

            // QR CODE
            $qr_url = $this->qrLibrary->createQrForBooking($booking_code);

            // GET USER
            $user = $this->db->table('app_users')
                ->where('user_id', $invite->user_id)
                ->get()->getRow();

            if ($user) {

                // 1️⃣ SEND EVENT BOOKING CONFIRMATION
                $this->notificationLibrary->sendEventConfirmation(
                    $user->phone,
                    $user->name,
                    $invite->event_name
                );

                // 2️⃣ SEND INVITE CONFIRMATION
                $this->notificationLibrary->sendInviteConfirmation(
                    $user->phone,
                    $user->name,
                    $invite->event_name
                );

                return true;
            }
        }

        // NORMAL INVITE  (ONLY ONE MESSAGE)
        else if ((int) $inviteStatus === 0) {

            $user = $this->db->table('app_users')
                ->where('user_id', $invite->user_id)
                ->get()->getRow();

            if ($user) {

                // ONLY SEND INVITE CONFIRMATION
                $whatsappResponse = $this->notificationLibrary->sendInviteConfirmation(
                    $user->phone,
                    $user->name,
                    $invite->event_name
                );

                return true;
            }
        }

        return false;
    }
}