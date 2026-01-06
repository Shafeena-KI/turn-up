<?php
 
namespace App\Commands;
 
use App\Models\Api\EventModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\BaseCommand;
use App\Models\Api\EventInviteModel;
use Config\Database;
 
class UpdateInviteStatus extends BaseCommand
{
    protected $group = 'cron';
    protected $name = 'cron:update-invites';
    protected $description = 'Update invite status based on start_date/start_time';
 
    public function run(array $params = [])
    {
        $date        = date('Y-m-d');
        $time        = date('H:i:s');
        $freeze_time = date('H:i:s', strtotime('-3 hours')); // 3 hours before now
       
        $db          = Database::connect();
 
        // Step 1: Build subquery to find all invite IDs to expire
        $sub    = $db->table('event_invites ei')
                    ->select('ei.invite_id')
                    ->join('events e', 'e.event_id = ei.event_id')
                    ->whereNotIn('e.status', [EventModel::CANCELLED, EventModel::DELETED])
                    ->where('ei.status', EventInviteModel::PENDING)
                    // ->where('e.event_date_start <=', $date)
                    // ->where('e.event_time_start <=', $freeze_time)
                    ->where("TIMESTAMP(e.event_date_start, e.event_time_start) <", "DATE_SUB(NOW(), INTERVAL 3 HOUR)", false)
                    ->getCompiledSelect();
 
        // Step 2: Update using WHERE IN (subquery)
        $db->table('event_invites')
            ->where("invite_id IN (SELECT invite_id FROM ($sub) AS tmp)", null, false)
            ->update([
                'status' => EventInviteModel::EXPIRED
            ]);
 
        CLI::write("Invite Auto-Expiry executed at {$date} {$time}");
    }
 
}
 