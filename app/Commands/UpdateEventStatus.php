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
        $date = date('Y-m-d');
        $time = date('H:i:s');

        $model = new EventModel();
        $builder = $model->builder();

        // // CASE 1: Completed when end_date < today (end time optional)
        // $builder->where('event_date_end <', $date)
        //     ->where('status !=', self::COMPLETED)
        //     ->set(['status' => self::COMPLETED])
        //     ->update();


        // // CASE 2: Completed when end_date = today AND end_time <= now (only if end_time present)
        // $builder->where('event_date_end', $date)
        //     ->where('event_time_end IS NOT NULL', null, false)
        //     ->where('event_time_end <=', $time)
        //     ->where('status !=', self::COMPLETED)
        //     ->set(['status' => self::COMPLETED])
        //     ->update();
        // // CASE 3: Completed when start_date + start_time + 1 day <= NOW
        // $builder->where('event_date_start IS NOT NULL', null, false)
        //     ->where('event_time_start IS NOT NULL', null, false)
        //     ->where(
        //         "DATE_ADD(CONCAT(event_date_start,' ',event_time_start), INTERVAL 1 DAY) <= '{$date} {$time}'",
        //         null,
        //         false
        //     )
        //     ->where('status !=', self::COMPLETED)
        //     ->set(['status' => self::COMPLETED])
        //     ->update();

        // UPCOMING EVENTS
        $builder->where('event_date_start >', $date)
            ->where('status !=', self::UPCOMING)
            ->set(['status' => self::UPCOMING])
            ->update();


        CLI::write("Update Event Status executed at {$date} {$time}");
    }

}
