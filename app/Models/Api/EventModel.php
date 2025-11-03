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
        'event_map',
        'event_date_start',
        'event_date_end',
        'dress_code',
        'age_limit',
        'host_id',
        'tag_id',
        'poster_image',
        'gallery_images',
        'total_seats',
        'status',
        'created_by',
        'created_at',
        'updated_at'
    ];
}
