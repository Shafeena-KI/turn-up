<?php

namespace App\Libraries;

class NotificationLibrary
{
    private function formatPhone($phone)
    {
        // Remove spaces, brackets, hyphens
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Convert 00 prefix to +
        if (strpos($phone, '00') === 0) {
            $phone = '+' . substr($phone, 2);
        }

        // Must start with + and be valid length
        if (strpos($phone, '+') === 0) {
            $digits = preg_replace('/\D/', '', $phone);

            if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                return '+' . $digits;
            }
        }

        // If no country code provided → reject
        throw new \Exception('Country code is required');
    }


    private function sendWebhook($url, $payload)
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // LOG EVERYTHING
        log_message('error', 'WhatsApp Payload: ' . json_encode($payload));
        log_message('error', 'WhatsApp HTTP Code: ' . $httpCode);
        log_message('error', 'WhatsApp Response: ' . var_export($response, true));

        if ($error) {
            log_message('error', 'WhatsApp CURL Error: ' . $error);
            return [
                'success' => false,
                'error' => $error
            ];
        }

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'response' => $response
        ];
    }
    public function sendWhatsAppOtp($phone, $otp)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/6932bf7f35cc1fd9bcef86e5/webhook/execute";

        $phone = $this->formatPhone($phone);

        $payload = [
            "phone" => $phone,
            "name" => "Test",
            "otp" => (string) $otp
        ];

        return $this->sendWebhook($url, $payload);
    }
    public function sendInviteConfirmation($phone, $name, $event_name)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/6932bced35cc1fd9bcef7ebc/webhook/execute";

        $phone = $this->formatPhone($phone);
        // SIMPLE PAYLOAD SAME AS OTP 
        $payload = [
            "phone" => $phone,
            "name" => "Test",
            "username" => $name,
            "event_name" => $event_name
        ];

        return $this->sendWebhook($url, $payload);
    }

    public function sendEventConfirmation($phone, $name, $event_name)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/6932c4d435cc1fd9bcefa35a/webhook/execute";

        $phone = $this->formatPhone($phone);

        $payload = [
            "phone" => $phone,
            "username" => $name,
            "event_name" => $event_name,
        ];

        return $this->sendWebhook($url, $payload);
    }
    public function sendEventRejection($phone, $name, $event_name)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/693a3cdba21b8b5619fe2e0a/webhook/execute";

        $phone = $this->formatPhone($phone);

        $payload = [
            "phone" => $phone,
            "username" => $name,
            "event_name" => $event_name,
        ];

        return $this->sendWebhook($url, $payload);
    }

    public function sendEventQrWhatsapp($phone, $name, $eventName, $qrUrl, $bookingcode)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/69294365131681aba96784cd/webhook/execute";

        // Normalize phone (91XXXXXXXXXX)
        $phone = ltrim($phone, '+');
        if (strpos($phone, '91') !== 0) {
            $phone = '91' . ltrim($phone, '0');
        }

        $payload = [
            "phone" => $phone,
            "username" => $name,
            "event_name" => $eventName,
            "qr_url" => $qrUrl,
            "booking_code" => $bookingcode
        ];
        return $this->sendWebhook($url, $payload);
    }
}