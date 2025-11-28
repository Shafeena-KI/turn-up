<?php

namespace App\Models\Api;

use CodeIgniter\Model;

class EventCountsModel extends Model
{
    protected $table = 'event_counts';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'event_id', 'category_id',
        'total_invites', 'total_male_invites', 'total_female_invites', 'total_couple_invites',
        'total_other_invites',
        'total_booking', 'total_male_booking', 'total_female_booking', 'total_couple_booking',
        'total_other_booking',
        'total_checkin', 'total_male_checkin', 'total_female_checkin', 'total_couple_checkin',
        'total_other_checkin',
        'updated_at'
    ];
}
