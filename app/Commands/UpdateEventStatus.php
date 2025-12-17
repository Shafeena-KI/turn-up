<?php

namespace App\Commands;

use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\BaseCommand;
use App\Models\Api\EventModel;

class UpdateEventStatus extends BaseCommand
{
    protected $group = 'cron';
    protected $name = 'cron:update-events';
    protected $description = 'Update events status based on end_time/end_date';

    /*
    # Events Status :
    */
    const UPCOMING = 1;
    const COMPLETED = 2;

    public function run(array $params = [])
    {
        $dateTime = date('Y-m-d H:i:s');
        $date     = date('Y-m-d');
        $time     = date('H:i:s');

        $model = new EventModel();

        /*
        --------------------------------------------------
        CASE 1: Events with end_date < today → COMPLETED
        --------------------------------------------------
        */
        $model->builder()
            ->where('event_date_end <', $date)
            ->where('status !=', self::COMPLETED)
            ->update(['status' => self::COMPLETED]);

        /*
        --------------------------------------------------
        CASE 2: end_date = today AND end_time <= now
        --------------------------------------------------
        */
        $model->builder()
            ->where('event_date_end', $date)
            ->where('event_time_end IS NOT NULL', null, false)
            ->where('event_time_end <=', $time)
            ->where('status !=', self::COMPLETED)
            ->update(['status' => self::COMPLETED]);

        /*
        --------------------------------------------------
        CASE 3: NO end_date & end_time
        → Complete after 1 day from start
        --------------------------------------------------
        */
        $model->builder()
            ->where('event_date_end IS NULL', null, false)
            ->where('event_time_end IS NULL', null, false)
            ->where('event_date_start IS NOT NULL', null, false)
            ->where('event_time_start IS NOT NULL', null, false)
            ->where(
                "DATE_ADD(CONCAT(event_date_start,' ',event_time_start), INTERVAL 1 DAY) <= '{$dateTime}'",
                null,
                false
            )
            ->where('status !=', self::COMPLETED)
            ->update(['status' => self::COMPLETED]);

        /*
        --------------------------------------------------
        UPCOMING EVENTS
        --------------------------------------------------
        */
        $model->builder()
            ->where('event_date_start >', $date)
            ->where('status !=', self::UPCOMING)
            ->update(['status' => self::UPCOMING]);

        CLI::write("Update Event Status executed at {$dateTime}");
    }

}
