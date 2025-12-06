<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventBookingModel extends Model
{

    const BOOKED = 1;
    const CANCELLED = 2;
    const ATTENTED = 3;

    protected $table = 'event_booking';
    protected $primaryKey = 'booking_id';

    protected $allowedFields = [
        'user_id',
        'event_id',
        'category_id',
        'invite_id',
        'total_price',
        'booking_code',
        'qr_code',
        'quantity',
        'status',
        'payment_id',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'user_id' => 'required|integer',
        'event_id' => 'required|integer',
        'category_id' => 'required|integer',
        'total_price' => 'permit_empty|decimal',
        'booking_code' => 'required|max_length[50]',
        'quantity' => 'permit_empty|integer'
    ];



    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    /**
     * Get booking with event and user details
     */
    public function getBookingWithDetails($bookingId)
    {
        return $this->select('event_booking.*, events.event_name, app_users.name as user_name, app_users.email, app_users.phone')
                    ->join('events', 'events.event_id = event_booking.event_id')
                    ->join('app_users', 'app_users.user_id = event_booking.user_id')
                    ->where('event_booking.booking_id', $bookingId)
                    ->first();
    }
}