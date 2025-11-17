<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventInviteModel extends Model
{
    protected $table = 'event_invites';
    protected $primaryKey = 'invite_id';

    protected $allowedFields = [
        'event_id', 'user_id', 'category_id','entry_type','accompanied_by',
    'invite_total','invite_male_total','invite_female_total','invite_couple_total',
        'status', 'approval_type', 'requested_at', 'approved_at'
    ];

    protected $useTimestamps = false;

    public function getInvitesByEvent($event_id)
    {
        return $this->where('event_id', $event_id)->findAll();
    }

    public function getInvitesByUser($user_id)
    {
        return $this->where('user_id', $user_id)->findAll();
    }
}
