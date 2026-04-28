<?php

use Bitrix\Main\Config\Option;

class AVSExport1C
{
    private $moduleId = 'avs_booking';
    private $apiUrl;
    private $apiKey;

    public function __construct()
    {
        $this->apiUrl = Option::get($this->moduleId, 'export_1c_url', '');
        $this->apiKey = Option::get($this->moduleId, 'export_1c_key', '');
    }

    public function sendBooking($bookingData, $reference)
    {
        if (!$this->apiUrl || !$this->apiKey) {
            $this->log('Ошибка: не настроен URL или API-ключ для 1С');
            return ['success' => false, 'message' => 'Не настроена интеграция с 1С'];
        }

        $data = [
            'action' => 'create_booking',
            'api_key' => $this->apiKey,
            'reference' => $reference,
            'resource_name' => $bookingData['resource_name'],
            'resource_id' => $bookingData['resource_id'],
            'date' => $bookingData['date'],
            'rental_type' => $bookingData['rental_type'],
            'start_time' => $bookingData['start_time'],
            'end_time' => $bookingData['end_time'],
            'total_price' => $bookingData['total_price'],
            'deposit_amount' => $bookingData['deposit_amount'] ?? 0,
            'customer' => [
                'first_name' => $bookingData['user_data']['first_name'],
                'last_name' => $bookingData['user_data']['last_name'],
                'full_name' => trim($bookingData['user_data']['first_name'] . ' ' . $bookingData['user_data']['last_name']),
                'phone' => $bookingData['user_data']['phone'],
                'email' => $bookingData['user_data']['email'],
                'comment' => $bookingData['user_data']['comment'] ?? ''
            ],
            'created_at' => date('Y-m-d H:i:s')
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("CURL ошибка при отправке в 1С: $error");
            return ['success' => false, 'message' => "Ошибка соединения с 1С: $error"];
        }

        if ($httpCode != 200) {
            $this->log("HTTP ошибка при отправке в 1С: $httpCode, ответ: $response");
            return ['success' => false, 'message' => "Ошибка API 1С: HTTP $httpCode"];
        }

        $result = json_decode($response, true);

        if ($result && isset($result['success']) && $result['success']) {
            $this->log("Бронирование $reference успешно отправлено в 1С");
            return ['success' => true, 'message' => 'Бронирование отправлено в 1С', '1c_response' => $result];
        } else {
            $errorMsg = $result['message'] ?? 'Неизвестная ошибка';
            $this->log("Ошибка при отправке в 1С: $errorMsg");
            return ['success' => false, 'message' => "Ошибка 1С: $errorMsg"];
        }
    }

    private function log($message)
    {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/export_1c.log';
        $logMessage = date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}
