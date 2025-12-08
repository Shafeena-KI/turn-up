<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelDownload extends BaseController
{
    protected $eventModel;
    protected $inviteModel;

    public function __construct()
    {
        $this->eventModel = new EventModel();
        $this->inviteModel = new EventInviteModel();
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
        // Prevent "headers already sent"
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

        $sheet->setCellValue('A3', 'Location: ' . ($event['event_location'] ?? ''));
        $sheet->mergeCells('A3:J3');

        $sheet->setCellValue(
            'A4',
            'Event Date & Time: ' . (
                !empty($event['start_datetime'])
                ? date('d M Y, h:i A', strtotime($event['start_datetime']))
                : ''
            )
        );
        $sheet->mergeCells('A4:J4');

        // TABLE HEADER 
        $headers = [
            'Sl No',
            'Invite Code',
            'Guest Name',
            'Phone',
            'Entry Type',
            'Partner',
            'Approval Type',
            'Status',
            'Requested At',
            'Approved At'
        ];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '6', $header);
            $col++;
        }

        // Bold headers
        $sheet->getStyle('A6:J6')->getFont()->setBold(true);

        /* ================= DATA ================= */
        $row = 7;
        $sl = 1;

        foreach ($invites as $invite) {

            $sheet->setCellValue('A' . $row, $sl++);
            $sheet->setCellValue('B' . $row, $invite['invite_code'] ?? '');
            $sheet->setCellValue('C' . $row, $invite['guest_name'] ?? '');
            $sheet->setCellValue('D' . $row, $invite['phone'] ?? '');
            $sheet->setCellValue('E' . $row, $invite['entry_type'] ?? 'N/A');
            $sheet->setCellValue('F' . $row, $invite['partner'] ?? '');
            $sheet->setCellValue('G' . $row, $invite['approval_type'] ?? 'N/A');
            $sheet->setCellValue('H' . $row, $invite['status'] ?? 'N/A');
            $sheet->setCellValue('I' . $row, $invite['requested_at'] ?? '');
            $sheet->setCellValue('J' . $row, $invite['approved_at'] ?? '');

            $row++;
        }


        // Auto column width
        foreach (range('A', 'J') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        /* ================= DOWNLOAD ================= */
        $fileName = 'Invites_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
