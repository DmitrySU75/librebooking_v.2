<?php

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Data\Cache;

class LibreBookingAPI
{
    private $apiUrl;
    private $username;
    private $password;
    private $httpClient;
    private $sessionToken = null;
    private $userId = null;
    private $tokenExpires = 0;
    private $tokenFile;

    public function __construct()
    {
        $this->apiUrl = rtrim(LIBREBOOKING_API_URL, '/');
        $this->username = LIBREBOOKING_API_USER;
        $this->password = LIBREBOOKING_API_PASSWORD;
        $this->tokenFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/booking_token.json';

        $this->httpClient = new HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30,
        ]);
        $this->httpClient->setHeader('Content-Type', 'application/json');

        $this->authenticate();
    }

    private function authenticate()
    {
        if ($this->loadCachedToken()) {
            return true;
        }

        $authUrl = $this->apiUrl . '/Authentication/Authenticate';

        $postData = json_encode([
            'username' => $this->username,
            'password' => $this->password
        ]);

        $this->httpClient->setHeader('Content-Type', 'application/json');
        $response = $this->httpClient->post($authUrl, $postData);

        if ($this->httpClient->getStatus() != 200) {
            \Bitrix\Main\Diag\Logger::writeToFile(
                'LibreBooking auth failed: ' . $response,
                'librebooking_error.log'
            );
            return false;
        }

        $data = json_decode($response, true);

        if (isset($data['sessionToken'])) {
            $this->sessionToken = $data['sessionToken'];
            $this->userId = $data['userId'];

            file_put_contents($this->tokenFile, json_encode([
                'sessionToken' => $this->sessionToken,
                'userId' => $this->userId,
                'sessionExpires' => $data['sessionExpires'] ?? date('Y-m-d H:i:s', time() + 3600)
            ]));

            return true;
        }

        return false;
    }

    private function loadCachedToken()
    {
        if (!file_exists($this->tokenFile)) {
            return false;
        }

        $cached = json_decode(file_get_contents($this->tokenFile), true);
        if (!$cached) {
            return false;
        }

        $expiresAt = strtotime($cached['sessionExpires']);
        if (time() >= $expiresAt - 300) {
            return false;
        }

        $this->sessionToken = $cached['sessionToken'];
        $this->userId = $cached['userId'];
        return true;
    }

    private function request($method, $endpoint, $data = null)
    {
        if (file_exists($this->tokenFile)) {
            $cached = json_decode(file_get_contents($this->tokenFile), true);
            if ($cached && time() >= strtotime($cached['sessionExpires']) - 60) {
                $this->authenticate();
            }
        }

        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');

        if ($method === 'POST' && !str_ends_with($url, '/')) {
            $url .= '/';
        }

        $this->httpClient->setHeader('X-Booked-SessionToken', $this->sessionToken);
        $this->httpClient->setHeader('X-Booked-UserId', $this->userId);

        $response = $this->httpClient->query($method, $url, $data);
        $status = $this->httpClient->getStatus();

        if ($status >= 400) {
            \Bitrix\Main\Diag\Logger::writeToFile(
                "API Error ($status): $response",
                'librebooking_error.log'
            );
            return null;
        }

        return json_decode($response, true);
    }

    public function checkAvailability($resourceId, $date, $duration = LIBREBOOKING_DEFAULT_DURATION)
    {
        $scheduleId = 2;
        $startDateTime = $date . 'T00:00:00+05:00';
        $endDateTime = $date . 'T23:59:59+05:00';

        $response = $this->request('GET', "Schedules/{$scheduleId}/Slots?resourceId={$resourceId}&startDateTime={$startDateTime}&endDateTime={$endDateTime}");

        if (!$response || !isset($response['dates'][0]['resources'][0]['slots'])) {
            return false;
        }

        $slots = $response['dates'][0]['resources'][0]['slots'];
        $durationMinutes = $duration;

        foreach ($slots as $slot) {
            if ($slot['isReservable'] && !$slot['isReserved']) {
                $start = new \DateTime($slot['startDateTime']);
                $end = new \DateTime($slot['endDateTime']);
                $interval = $start->diff($end);
                $minutes = $interval->h * 60 + $interval->i;

                if ($minutes >= $durationMinutes) {
                    return true;
                }
            }
        }

        return false;
    }

    public function checkMultipleAvailability($resourceIds, $date, $duration = LIBREBOOKING_DEFAULT_DURATION)
    {
        $results = [];
        foreach ($resourceIds as $id) {
            $results[$id] = $this->checkAvailability($id, $date, $duration);
        }
        return $results;
    }

    public function getResources()
    {
        return $this->request('GET', 'Resources/');
    }
}
