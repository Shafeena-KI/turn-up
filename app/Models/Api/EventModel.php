<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventModel extends Model
{
    protected $table = 'events';
    protected $primaryKey = 'event_id';
    protected $allowedFields = [
        'event_name',
        'event_description',
        'event_location',
        'event_city',
        'event_map',
        'event_date_start',
        'event_time_start',
        'event_date_end',
        'event_time_end',
        'dress_code',
        'event_code',
        'whatsappmessage_code',
        'age_limit',
        'host_id',
        'tag_id',
        'poster_image',
        'gallery_images',
        'total_seats',
        'status',
        'event_type',
        'created_by',
        'created_at',
        'updated_at'
    ];

    const UPCOMING = 1;
    const COMPLETED = 2;
    const CANCELLED = 3;
    const DELETED = 4;

    public function getEventCodeById($eventId)
    {
        $event = $this->select('event_code')
            ->where('event_id', $eventId)
            ->first();

        return $event['event_code'] ?? null;
    }
    public function updateEventStatuses()
    {
        $dateTime = date('Y-m-d H:i:s');
        $date = date('Y-m-d');
        $time = date('H:i:s');

        // CASE 1: end_date < today → COMPLETED
        $this->builder()
            ->where('event_date_end <', $date)
            ->where('status !=', self::COMPLETED)
            ->update(['status' => self::COMPLETED]);

        // CASE 2: end_date = today AND end_time <= now → COMPLETED
        $this->builder()
            ->where('event_date_end', $date)
            ->where('event_time_end IS NOT NULL', null, false)
            ->where('event_time_end <=', $time)
            ->where('status !=', self::COMPLETED)
            ->update(['status' => self::COMPLETED]);

        // CASE 3: No end_date & end_time → complete after 1 day from start
        $this->builder()
            ->where('event_date_end IS NULL', null, false)
            ->where('event_time_end IS NULL', null, false)
            ->where('event_date_start IS NOT NULL', null, false)
            ->where('event_date_start <', $date)
            ->where('status !=', self::COMPLETED)
            ->update(['status' => self::COMPLETED]);

        // UPCOMING events
        $this->builder()
            ->where('event_date_start >', $date)
            ->where('status !=', self::UPCOMING)
            ->update(['status' => self::UPCOMING]);
    }
}
