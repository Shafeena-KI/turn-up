<?php

namespace App\Libraries;

use App\Models\Api\EventBookingModel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class QrLibrary
{
    protected $bookingModel;

    public function __construct()
    {
        $this->bookingModel = new EventBookingModel();
    }

    public function createQrForBooking($booking_code)
    {
        // Fetch booking
        $booking = $this->bookingModel->where('booking_code', $booking_code)->first();
        if (!$booking) {
            return null;
        }

        $secretKey = getenv('EVENT_QR_SECRET');
        $token = hash_hmac('sha256', $booking_code, $secretKey);

        $payload = json_encode([
            'booking_code' => $booking_code,
            'token' => $token
        ]);

        // PUBLIC FOLDER
        $qrFolder = FCPATH . 'public/uploads/qr_codes/';

        if (!is_dir($qrFolder)) {
            mkdir($qrFolder, 0777, true);
        }

        $fileName = $booking_code . '.png';
        $filePath = $qrFolder . $fileName;
        $qrUrl = base_url('public/uploads/qr_codes/' . $fileName);

        // Generate QR
        $qrCode = new QrCode($payload);
        $writer = new PngWriter();
        $writer->write($qrCode)->saveToFile($filePath);

        // Save in database
        $this->bookingModel->update($booking['booking_id'], [
            'qr_code' => $qrUrl
        ]);

        return $qrUrl;
    }
}