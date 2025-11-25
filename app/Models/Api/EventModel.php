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

    const UPCOMING  = 1;
    const COMPLETED = 2;
    const CANCELLED = 3;
    const DELETED   = 4;
}
