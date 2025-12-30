<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelDownload extends BaseController
{
    protected $eventModel;
    protected $inviteModel;
    protected $bookingModel;

    public function __construct()
    {
        $this->eventModel = new EventModel();
        $this->inviteModel = new EventInviteModel();
        $this->bookingModel = new EventBookingModel();
    }

    public function downloadInvites()
    {
        try {
            $eventId = $this->request->getGet('event_id');

            if (empty($eventId)) {
                throw new \Exception('Event ID missing');
            }

            $event = $this->eventModel->find($eventId);
            if (!$event) {
                throw new \Exception('Event not found');
            }

            $invites = $this->inviteModel->getInvitesByEventDetails($eventId);

            // Force array check
            if (!is_array($invites)) {
                throw new \Exception('Invites data is invalid');
            }

            $this->generateExcel($event, $invites);

        } catch (\Throwable $th) {
            return $this->response
                ->setStatusCode(500)
                ->setBody('Error: ' . $th->getMessage());
        }
    }

    private function generateExcel(array $event, array $invites): void
    {
        if (ob_get_length()) {
            ob_end_clean();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // EVENT HEADER 
        $sheet->setCellValue('A1', 'Event Name: ' . $event['event_name']);
        $sheet->mergeCells('A1:I1');

        $sheet->setCellValue('A2', 'Event Code: ' . $event['event_code']);
        $sheet->mergeCells('A2:I2');

        $sheet->setCellValue('A3', 'Location: ' . ($event['event_location'] ?? ''));
        $sheet->mergeCells('A3:I3');

        $eventDateTime = '';

        if (!empty($event['event_date_start']) && !empty($event['event_time_start'])) {
            $eventDateTime = date(
                'd M Y, h:i A',
                strtotime($event['event_date_start'] . ' ' . $event['event_time_start'])
            );
        }

        $sheet->setCellValue(
            'A4',
            'Event Date & Time: ' . $eventDateTime
        );

        $sheet->mergeCells('A4:I4');

        // INVITE COUNTS 
        // Calculate counts
        $counts = [
            'total_male_invites' => 0,
            'total_female_invites' => 0,
            'total_other_invites' => 0,
            'total_couple_invites' => 0,
            'total_invites' => count($invites),
        ];

        foreach ($invites as $invite) {
            switch ($invite['entry_type']) {
                case 'Male':
                    $counts['total_male_invites']++;
                    break;
                case 'Female':
                    $counts['total_female_invites']++;
                    break;
                case 'Other':
                    $counts['total_other_invites']++;
                    break;
                case 'Couple':
                    $counts['total_couple_invites']++;
                    break;
            }
        }

        // Header row (Row 5)
        $sheet->setCellValue('A5', 'Counts');
        $sheet->setCellValue('B5', 'Male');
        $sheet->setCellValue('C5', 'Female');
        $sheet->setCellValue('D5', 'Other');
        $sheet->setCellValue('E5', 'Couple');
        $sheet->setCellValue('F5', 'Total');

        // Data row (Row 6)
        $sheet->setCellValue('A6', 'Total');
        $sheet->setCellValue('B6', $counts['total_male_invites']);
        $sheet->setCellValue('C6', $counts['total_female_invites']);
        $sheet->setCellValue('D6', $counts['total_other_invites']);
        $sheet->setCellValue('E6', $counts['total_couple_invites']);
        $sheet->setCellValue('F6', $counts['total_invites']);

        // TABLE HEADER 
        $headers = [
            'Sl No',
            'Invite Code',
            'Guest Name',
            'Email',
            'Phone',
            'Profile Status',
            'Entry Type',
            'Ticket Type',
            'Partner',
            'Status',
            'Requested At',
            'Approved At'
        ];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '9', $header); // Row 7
            $col++;
        }

        $sheet->getStyle('A9:L9')->getFont()->setBold(true);

        // DATA 
        $row = 10;
        $sl = 1;

        foreach ($invites as $invite) {
            $sheet->setCellValue('A' . $row, $sl++);
            $sheet->setCellValue('B' . $row, $invite['invite_code'] ?? '');
            $sheet->setCellValue('C' . $row, $invite['guest_name'] ?? '');
            $sheet->setCellValue('D' . $row, $invite['guest_email'] ?? '');
            $sheet->setCellValue('E' . $row, $invite['guest_phone'] ?? '');
            $sheet->setCellValue('F' . $row, $invite['profile_status'] ?? '');
            $sheet->setCellValue('G' . $row, $invite['entry_type'] ?? 'N/A');
            $sheet->setCellValue('H' . $row, $invite['ticket_type'] ?? 'N/A');

            $sheet->setCellValue('I' . $row, $invite['partner'] ?? '');
            $sheet->setCellValue('J' . $row, $invite['status'] ?? 'N/A');
            $sheet->setCellValue('K' . $row, $invite['requested_at'] ?? '');
            $sheet->setCellValue('L' . $row, $invite['approved_at'] ?? '');
            $row++;
        }

        foreach (range('A', 'L') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        // DOWNLOAD 
        $fileName = 'Invites_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
    //  Download Booking
    public function downloadBookings()
    {
        try {
            $eventId = $this->request->getGet('event_id');
            if (!$eventId) {
                throw new \Exception('Event ID missing');
            }

            $event = $this->eventModel->find($eventId);
            if (!$event) {
                throw new \Exception('Event not found');
            }

            $bookings = $this->bookingModel->getBookingsByEventDetails($eventId);

            if (!is_array($bookings)) {
                throw new \Exception('Invalid booking data');
            }

            $this->generateBookingExcel($event, $bookings);

        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(500)
                ->setBody('Error: ' . $e->getMessage());
        }
    }
    private function generateBookingExcel(array $event, array $bookings): void
    {
        if (ob_get_length()) {
            ob_end_clean();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        /* ======================
         * EVENT HEADER
         * ====================== */
        $sheet->setCellValue('A1', 'Event Name: ' . $event['event_name']);
        $sheet->mergeCells('A1:J1');

        $sheet->setCellValue('A2', 'Event Code: ' . $event['event_code']);
        $sheet->mergeCells('A2:J2');

        $sheet->setCellValue('A3', 'Location: ' . ($event['event_location'] ?? ''));
        $sheet->mergeCells('A3:J3');

        $eventDateTime = '';

        if (!empty($event['event_date_start']) && !empty($event['event_time_start'])) {
            $eventDateTime = date(
                'd M Y, h:i A',
                strtotime($event['event_date_start'] . ' ' . $event['event_time_start'])
            );
        }

        $sheet->setCellValue(
            'A4',
            'Event Date & Time: ' . $eventDateTime
        );

        $sheet->mergeCells('A4:J4');

        /* ======================
         * BOOKING COUNTS
         * ====================== */
        $counts = [
            'male' => 0,
            'female' => 0,
            'other' => 0,
            'couple' => 0,
            'total' => count($bookings),
        ];

        foreach ($bookings as $booking) {
            switch ($booking['entry_type'] ?? '') {
                case 'Male':
                    $counts['male']++;
                    break;
                case 'Female':
                    $counts['female']++;
                    break;
                case 'Other':
                    $counts['other']++;
                    break;
                case 'Couple':
                    $counts['couple']++;
                    break;
            }
        }

        // Count Header (Row 5)
        $sheet->setCellValue('A5', 'Counts');
        $sheet->setCellValue('B5', 'Male');
        $sheet->setCellValue('C5', 'Female');
        $sheet->setCellValue('D5', 'Other');
        $sheet->setCellValue('E5', 'Couple');
        $sheet->setCellValue('F5', 'Total');

        // Count Values (Row 6)
        $sheet->setCellValue('A6', 'Total');
        $sheet->setCellValue('B6', $counts['male']);
        $sheet->setCellValue('C6', $counts['female']);
        $sheet->setCellValue('D6', $counts['other']);
        $sheet->setCellValue('E6', $counts['couple']);
        $sheet->setCellValue('F6', $counts['total']);

        /* ======================
         * TABLE HEADER
         * ====================== */
        $headers = [
            'Sl No',
            'Booking Code',
            'Guest Name',
            'Email',
            'Phone',
            'Profile Status',
            'Entry Type',
            'Ticket Type',
            'Payment Status',
            'Status',
            'Booked At'
        ];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '9', $header);
            $col++;
        }

        $sheet->getStyle('A9:K9')->getFont()->setBold(true);

        /* ======================
         * DATA
         * ====================== */
        $row = 10;
        $sl = 1;

        foreach ($bookings as $b) {
            $sheet->setCellValue('A' . $row, $sl++);
            $sheet->setCellValue('B' . $row, $b['booking_code'] ?? '');
            $sheet->setCellValue('C' . $row, $b['guest_name'] ?? '');
            $sheet->setCellValue('D' . $row, $b['guest_email'] ?? '');
            $sheet->setCellValue('E' . $row, $b['guest_phone'] ?? '');
            $sheet->setCellValue('F' . $row, $b['profile_status'] ?? '');
            $sheet->setCellValue('G' . $row, $b['entry_type'] ?? 'N/A');
            $sheet->setCellValue('H' . $row, $b['ticket_type'] ?? 'N/A');
            $sheet->setCellValue('I' . $row, $b['payment_status'] ?? 'N/A');
            $sheet->setCellValue('J' . $row, $b['status'] ?? 'N/A');
            $sheet->setCellValue('K' . $row, $b['created_at'] ?? '');
            $row++;
        }

        foreach (range('A', 'K') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        /* ======================
         * DOWNLOAD
         * ====================== */
        $fileName = 'Bookings_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
    // Download Checkins
    public function downloadCheckins()
    {
        try {
            $eventId = $this->request->getGet('event_id');
            if (!$eventId) {
                throw new \Exception('Event ID missing');
            }

            $event = $this->eventModel->find($eventId);
            if (!$event) {
                throw new \Exception('Event not found');
            }

            // MODEL FUNCTION → getCheckinsByEvent($eventId)
            $checkins = $this->bookingModel->getCheckinsByEventDetails($eventId);


            if (!is_array($checkins)) {
                throw new \Exception('Invalid checkin data');
            }

            $this->generateCheckinExcel($event, $checkins);

        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(500)
                ->setBody('Error: ' . $e->getMessage());
        }
    }
    private function generateCheckinExcel(array $event, array $checkins): void
    {
        if (ob_get_length()) {
            ob_end_clean();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // EVENT HEADER

        $sheet->setCellValue('A1', 'Event Name: ' . $event['event_name']);
        $sheet->mergeCells('A1:J1');

        $sheet->setCellValue('A2', 'Event Code: ' . $event['event_code']);
        $sheet->mergeCells('A2:J2');

        $sheet->setCellValue('A3', 'Venue: ' . ($event['event_location'] ?? ''));
        $sheet->mergeCells('A3:J3');
        $eventDateTime = '';

        if (!empty($event['event_date_start']) && !empty($event['event_time_start'])) {
            $eventDateTime = date(
                'd M Y, h:i A',
                strtotime($event['event_date_start'] . ' ' . $event['event_time_start'])
            );
        }

        $sheet->setCellValue(
            'A4',
            'Event Date & Time: ' . $eventDateTime
        );

        $sheet->mergeCells('A4:J4');

        // COUNTS

        $counts = [
            'male' => 0,
            'female' => 0,
            'other' => 0,
            'couple' => 0,
            'total' => count($checkins),
        ];

        foreach ($checkins as $c) {
            switch ($c['entry_type'] ?? '') {
                case 'Male':
                    $counts['male']++;
                    break;
                case 'Female':
                    $counts['female']++;
                    break;
                case 'Other':
                    $counts['other']++;
                    break;
                case 'Couple':
                    $counts['couple']++;
                    break;
            }
        }

        $sheet->setCellValue('A6', 'Counts');
        $sheet->setCellValue('B6', 'Male');
        $sheet->setCellValue('C6', 'Female');
        $sheet->setCellValue('D6', 'Other');
        $sheet->setCellValue('E6', 'Couple');
        $sheet->setCellValue('F6', 'Total');

        $sheet->setCellValue('A7', 'Total');
        $sheet->setCellValue('B7', $counts['male']);
        $sheet->setCellValue('C7', $counts['female']);
        $sheet->setCellValue('D7', $counts['other']);
        $sheet->setCellValue('E7', $counts['couple']);
        $sheet->setCellValue('F7', $counts['total']);

        /* ======================
         * TABLE HEADER
         * ====================== */
        $headers = [
            'Sl.No',
            'Name',
            'Phone',
            'Email',
            'Booking ID',
            'Ticket Type',
            'Entry Type',
            'Partner',
            'Checkin Time',
            'Checked By'
        ];

        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . '10', $h);
            $col++;
        }

        $sheet->getStyle('A10:J10')->getFont()->setBold(true);

        /* ======================
         * DATA ROWS
         * ====================== */
        $row = 11;
        $sl = 1;

        foreach ($checkins as $c) {
            $sheet->setCellValue('A' . $row, $sl++);
            $sheet->setCellValue('B' . $row, $c['guest_name'] ?? '');
            $sheet->setCellValue('C' . $row, $c['guest_phone'] ?? '');
            $sheet->setCellValue('D' . $row, $c['guest_email'] ?? '');
            $sheet->setCellValue('E' . $row, $c['booking_code'] ?? '');
            $sheet->setCellValue('F' . $row, $c['ticket_type'] ?? 'N/A');
            $sheet->setCellValue('G' . $row, $c['entry_type'] ?? '');
            $sheet->setCellValue('H' . $row, $c['partner_name'] ?? '');
            $sheet->setCellValue('I' . $row, $c['checkin_time'] ?? '');
            $sheet->setCellValue('J' . $row, $c['checkedin_by'] ?? '');
            $row++;
        }

        foreach (range('A', 'J') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        /* ======================
         * DOWNLOAD
         * ====================== */
        $fileName = 'Checkins_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

}
