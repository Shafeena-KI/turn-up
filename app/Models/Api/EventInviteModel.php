<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventInviteModel extends Model
{
    protected $table = 'event_invites';
    protected $primaryKey = 'invite_id';

    protected $allowedFields = [
        'event_id',
        'user_id',
        'category_id',
        'entry_type',
        'invite_code',
        'partner',
        'status',
        'approval_type',
        'requested_at',
        'approved_at'
    ];

    protected $useTimestamps = false;

    public function getInvitesByEvent($event_id)
    {
        return $this->where('event_id', $event_id)->findAll();
    }

    public function getInvitesByUser($user_id)
    {
        return $this->db->table('event_invites')
            ->select('event_invites.*, event_ticket_category.category_name')
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_invites.category_id', 'left')
            ->where('event_invites.user_id', $user_id)
            ->get()
            ->getResultArray();
    }

}
