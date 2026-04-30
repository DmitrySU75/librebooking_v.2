<?php
class AVSBookingApiClient
{
    private $apiUrl;
    private $username;
    private $password;
    private $tokenFile;
    private $sessionToken;
    private $userId;
    private $timezoneOffset;
    
    public function __construct($apiUrl, $username, $password)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->tokenFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/booking_token.json';
        $this->timezoneOffset = \Bitrix\Main\Config\Option::get('avs_booking', 'timezone_offset', '+05:00');
        
        $this->authenticate();
    }
    
    private function authenticate()
    {
        if ($this->loadCachedToken()) return true;
        return $this->fetchNewToken();
    }
    
    private function loadCachedToken()
    {
        if (!file_exists($this->tokenFile)) return false;
        $cached = json_decode(file_get_contents($this->tokenFile), true);
        if (!$cached) return false;
        
        $expiresAt = strtotime($cached['sessionExpires']);
        if (time() >= $expiresAt - 300) return false;
        
        $this->sessionToken = $cached['sessionToken'];
        $this->userId = $cached['userId'];
        return true;
    }
    
    private function fetchNewToken()
    {
        $ch = curl_init($this->apiUrl . '/Authentication/Authenticate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'username' => $this->username,
            'password' => $this->password
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Ошибка аутентификации API: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        $this->sessionToken = $data['sessionToken'];
        $this->userId = $data['userId'];
        
        file_put_contents($this->tokenFile, json_encode([
            'sessionToken' => $this->sessionToken,
            'userId' => $this->userId,
            'sessionExpires' => $data['sessionExpires']
        ]));
        
        return true;
    }
    
    private function request($method, $endpoint, $data = null)
    {
        if (file_exists($this->tokenFile)) {
            $cached = json_decode(file_get_contents($this->tokenFile), true);
            if ($cached && time() >= strtotime($cached['sessionExpires']) - 60) {
                $this->fetchNewToken();
            }
        }
        
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        if ($method === 'POST' && !str_ends_with($url, '/')) $url .= '/';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ]);
        
        if ($data !== null && ($method === 'POST' || $method === 'PUT')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception("API Error ($httpCode): $response");
        }
        
        return json_decode($response, true);
    }
    
    public function getAvailableSlotsForDate($resourceId, $date)
    {
        $scheduleId = (int)\Bitrix\Main\Config\Option::get('avs_booking', 'default_schedule_id', 2);
        $timezone = '+05:00';
        $startDateTime = $date . 'T00:00:00' . $timezone;
        $endDateTime = $date . 'T23:59:59' . $timezone;
        
        $result = ['hourly' => [], 'full_day' => null, 'night' => null];
        
        try {
            $response = $this->request('GET', "Schedules/{$scheduleId}/Slots?resourceId={$resourceId}&startDateTime={$startDateTime}&endDateTime={$endDateTime}");
            
            if (!isset($response['dates']) || empty($response['dates'])) {
                return $result;
            }
            
            $allSlots = [];
            foreach ($response['dates'] as $dateData) {
                if (isset($dateData['resources'][0]['slots'])) {
                    $allSlots = array_merge($allSlots, $dateData['resources'][0]['slots']);
                }
            }
            
            if (empty($allSlots)) {
                return $result;
            }
            
            foreach ($allSlots as $slot) {
                if ($slot['isReservable'] && !$slot['isReserved']) {
                    $hour = (int)date('H', strtotime($slot['startDateTime']));
                    $result['hourly'][] = ['hour' => $hour, 'label' => sprintf('%02d:00', $hour)];
                }
            }
            
            $uniqueHours = [];
            foreach ($result['hourly'] as $slot) {
                $uniqueHours[$slot['hour']] = $slot;
            }
            $result['hourly'] = array_values($uniqueHours);
            
            usort($result['hourly'], function($a, $b) {
                return $a['hour'] - $b['hour'];
            });
            
            $workEndHour = \AVSBookingModule::getWorkEndHour(0, $date);
            $dayStart = strtotime($date . ' 10:00:00');
            $dayEnd = strtotime($date . ' ' . $workEndHour . ':00:00');
            $result['full_day'] = $this->isIntervalFree($allSlots, $dayStart, $dayEnd);
            
            $nightStart = strtotime($date . ' ' . $workEndHour . ':00:00');
            $nightEnd = strtotime($date . ' +1 day 09:00:00');
            $result['night'] = $this->isIntervalFree($allSlots, $nightStart, $nightEnd);
            
        } catch (Exception $e) {
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/debug.log', 
                date('[Y-m-d H:i:s]') . " getAvailableSlotsForDate error: " . $e->getMessage() . "\n", 
                FILE_APPEND);
        }
        
        return $result;
    }
    
    private function isIntervalFree($slots, $start, $end)
    {
        foreach ($slots as $slot) {
            if ($slot['isReserved']) {
                $slotStart = strtotime($slot['startDateTime']);
                $slotEnd = strtotime($slot['endDateTime']);
                
                if ($slotStart < $end && $slotEnd > $start) {
                    return false;
                }
            }
        }
        return true;
    }
    
    public function checkAvailability($resourceId, $startTime, $endTime)
    {
        $date = substr($startTime, 0, 10);
        $slots = $this->getAvailableSlotsForDate($resourceId, $date);
        
        $checkStart = strtotime($startTime);
        $checkEnd = strtotime($endTime);
        
        $startHour = (int)date('H', $checkStart);
        $endHour = (int)date('H', $checkEnd);
        
        $workEndHour = \AVSBookingModule::getWorkEndHour(0, $date);
        
        if ($endHour > $workEndHour) {
            return false;
        }
        
        for ($h = $startHour; $h < $endHour; $h++) {
            $found = false;
            foreach ($slots['hourly'] as $slot) {
                if ($slot['hour'] == $h) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        
        return true;
    }
    
    public function createReservation($resourceId, $startTime, $endTime, $userData)
    {
        return $this->request('POST', 'Reservations/', [
            'resourceId' => (int)$resourceId,
            'startDateTime' => $startTime,
            'endDateTime' => $endTime,
            'title' => 'Бронирование с сайта',
            'description' => "Телефон: {$userData['phone']}\nКомментарий: {$userData['comment']}",
            'firstName' => $userData['first_name'],
            'lastName' => $userData['last_name'] ?? '',
            'emailAddress' => $userData['email'],
            'allowParticipation' => true,
            'termsAccepted' => true,
            'invitees' => [],
            'participants' => [],
            'accessories' => [],
            'customAttributes' => []
        ]);
    }
    
    public function cancelReservation($referenceNumber)
    {
        return $this->request('DELETE', "Reservations/{$referenceNumber}");
    }
    
    public function updateReservation($referenceNumber, $newStartTime, $newEndTime, $newResourceId = null)
    {
        $data = [
            'startDateTime' => $newStartTime,
            'endDateTime' => $newEndTime
        ];
        
        if ($newResourceId) {
            $data['resourceId'] = $newResourceId;
        }
        
        return $this->request('POST', "Reservations/{$referenceNumber}", $data);
    }
    
    public function getReservation($referenceNumber)
    {
        return $this->request('GET', "Reservations/{$referenceNumber}");
    }
}