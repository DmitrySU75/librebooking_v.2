<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/PaymentHandler.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/ServicesManager.php';
require_once __DIR__ . '/lib/Export1C.php';

class AVSBookingModule
{
    private static $apiClient = null;
    private static $moduleId = 'avs_booking';
    
    public static function getApiClient()
    {
        if (self::$apiClient === null) {
            $apiUrl = Option::get(self::$moduleId, 'api_url');
            $username = Option::get(self::$moduleId, 'api_username');
            $password = Option::get(self::$moduleId, 'api_password');
            
            if (!$apiUrl || !$username || !$password) {
                throw new Exception('Настройки API не заполнены');
            }
            
            self::$apiClient = new AVSBookingApiClient($apiUrl, $username, $password);
        }
        return self::$apiClient;
    }
    
    public static function getGazeboData($elementId)
    {
        if (!Loader::includeModule('iblock')) {
            return null;
        }
        
        $res = \CIBlockElement::GetList(
            [],
            ['ID' => (int)$elementId, 'ACTIVE' => 'Y'],
            false,
            false,
            [
                'ID', 
                'NAME',
                'PROPERTY_LIBREBOOKING_RESOURCE_ID',
                'PROPERTY_PRICE_HOUR', 
                'PROPERTY_PRICE', 
                'PROPERTY_PRICE_NIGHT',
                'PROPERTY_NIGHT_SEASON_START', 
                'PROPERTY_NIGHT_SEASON_END',
                'PROPERTY_DEPOSIT_AMOUNT',
                'PROPERTY_MIN_HOURS'
            ]
        );
        
        if ($element = $res->Fetch()) {
            return [
                'id' => (int)$element['ID'],
                'name' => (string)$element['NAME'],
                'resource_id' => (int)$element['PROPERTY_LIBREBOOKING_RESOURCE_ID_VALUE'],
                'hourly_price' => (float)$element['PROPERTY_PRICE_HOUR_VALUE'],
                'full_day_price' => (float)$element['PROPERTY_PRICE_VALUE'],
                'night_price' => (float)$element['PROPERTY_PRICE_NIGHT_VALUE'],
                'night_season_start' => $element['PROPERTY_NIGHT_SEASON_START_VALUE'],
                'night_season_end' => $element['PROPERTY_NIGHT_SEASON_END_VALUE'],
                'deposit_amount' => (float)$element['PROPERTY_DEPOSIT_AMOUNT_VALUE'],
                'min_hours' => (int)$element['PROPERTY_MIN_HOURS_VALUE'] ?: 4
            ];
        }
        
        return null;
    }
    
    public static function getGazeboDataByResourceId($resourceId)
    {
        if (!Loader::includeModule('iblock')) {
            return null;
        }
        
        $res = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => 12,
                'ACTIVE' => 'Y',
                'PROPERTY_LIBREBOOKING_RESOURCE_ID' => $resourceId
            ],
            false,
            false,
            [
                'ID', 
                'NAME',
                'PROPERTY_LIBREBOOKING_RESOURCE_ID',
                'PROPERTY_PRICE_HOUR', 
                'PROPERTY_PRICE', 
                'PROPERTY_PRICE_NIGHT',
                'PROPERTY_NIGHT_SEASON_START', 
                'PROPERTY_NIGHT_SEASON_END',
                'PROPERTY_DEPOSIT_AMOUNT',
                'PROPERTY_MIN_HOURS'
            ]
        );
        
        if ($element = $res->Fetch()) {
            return [
                'id' => (int)$element['ID'],
                'name' => (string)$element['NAME'],
                'resource_id' => (int)$element['PROPERTY_LIBREBOOKING_RESOURCE_ID_VALUE'],
                'hourly_price' => (float)$element['PROPERTY_PRICE_HOUR_VALUE'],
                'full_day_price' => (float)$element['PROPERTY_PRICE_VALUE'],
                'night_price' => (float)$element['PROPERTY_PRICE_NIGHT_VALUE'],
                'night_season_start' => $element['PROPERTY_NIGHT_SEASON_START_VALUE'],
                'night_season_end' => $element['PROPERTY_NIGHT_SEASON_END_VALUE'],
                'deposit_amount' => (float)$element['PROPERTY_DEPOSIT_AMOUNT_VALUE'],
                'min_hours' => (int)$element['PROPERTY_MIN_HOURS_VALUE'] ?: 4
            ];
        }
        
        return null;
    }
    
    public static function getPriceForDate($elementId, $date, $priceType)
    {
        if (Loader::includeModule('iblock')) {
            $periodsIblockId = Option::get(self::$moduleId, 'price_periods_iblock_id', 0);
            
            if ($periodsIblockId) {
                $res = \CIBlockElement::GetList(
                    ['PROPERTY_DATE_FROM' => 'DESC'],
                    [
                        'IBLOCK_ID' => $periodsIblockId,
                        'PROPERTY_RESOURCE_ID' => $elementId,
                        'ACTIVE' => 'Y',
                        '<=PROPERTY_DATE_FROM' => $date,
                        '>=PROPERTY_DATE_TO' => $date
                    ],
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'PROPERTY_PRICE_HOUR', 'PROPERTY_PRICE_DAY', 'PROPERTY_PRICE_NIGHT']
                );
                
                if ($period = $res->Fetch()) {
                    $field = 'PROPERTY_PRICE_' . strtoupper($priceType === 'hourly' ? 'HOUR' : ($priceType === 'full_day' ? 'DAY' : 'NIGHT'));
                    $price = $period[$field . '_VALUE'] ?? null;
                    if ($price !== null && $price > 0) {
                        return (float)$price;
                    }
                }
            }
        }
        
        $gazebo = self::getGazeboData($elementId);
        if (!$gazebo) return null;
        
        switch ($priceType) {
            case 'hourly': return $gazebo['hourly_price'];
            case 'full_day': return $gazebo['full_day_price'];
            case 'night': return $gazebo['night_price'];
            default: return null;
        }
    }
    
    public static function getAvailableRentalTypes($elementId, $bookingDate)
    {
        $gazebo = self::getGazeboData($elementId);
        if (!$gazebo || !$gazebo['resource_id']) return [];
        
        $types = [];
        $timestamp = strtotime($bookingDate);
        
        $hourlyPrice = self::getPriceForDate($elementId, $bookingDate, 'hourly');
        $fullDayPrice = self::getPriceForDate($elementId, $bookingDate, 'full_day');
        $nightPrice = self::getPriceForDate($elementId, $bookingDate, 'night');
        
        if ($hourlyPrice > 0) {
            $types['hourly'] = ['price' => $hourlyPrice, 'label' => 'Почасовая аренда'];
        }
        
        if ($fullDayPrice > 0) {
            $types['full_day'] = ['price' => $fullDayPrice, 'label' => 'Весь день (10:00-22:00)'];
        }
        
        if ($nightPrice > 0 && self::isNightSeasonActive($gazebo, $timestamp)) {
            $types['night'] = ['price' => $nightPrice, 'label' => 'Ночь (00:00-09:00)'];
        }
        
        return $types;
    }
    
    private static function isNightSeasonActive($gazebo, $timestamp)
    {
        $currentDate = date('Y-m-d', $timestamp);
        
        if ($gazebo && !empty($gazebo['night_season_start']) && !empty($gazebo['night_season_end'])) {
            $start = $gazebo['night_season_start'];
            $end = $gazebo['night_season_end'];
            $startTimestamp = strtotime($start);
            $endTimestamp = strtotime($end);
            return ($timestamp >= $startTimestamp && $timestamp <= $endTimestamp);
        }
        
        $startRaw = Option::get('avs_booking', 'summer_season_start', '01.06');
        $endRaw = Option::get('avs_booking', 'summer_season_end', '31.08');
        
        $year = date('Y', $timestamp);
        $startParts = explode('.', $startRaw);
        $endParts = explode('.', $endRaw);
        
        $startDate = $year . '-' . str_pad($startParts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($startParts[0], 2, '0', STR_PAD_LEFT);
        $endDate = $year . '-' . str_pad($endParts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($endParts[0], 2, '0', STR_PAD_LEFT);
        
        $startTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate);
        
        return ($timestamp >= $startTimestamp && $timestamp <= $endTimestamp);
    }
    
    public static function getWorkEndHour($elementId, $date)
    {
        $gazebo = null;
        if ($elementId > 0) {
            $gazebo = self::getGazeboData($elementId);
        }
        
        $dateTime = new \DateTime($date, new \DateTimeZone('Asia/Yekaterinburg'));
        $timestamp = $dateTime->getTimestamp();
        $isNightSeason = self::isNightSeasonActive($gazebo, $timestamp);
        
        if ($isNightSeason) {
            return (int)Option::get('avs_booking', 'summer_end_hour', 23);
        } else {
            return (int)Option::get('avs_booking', 'winter_end_hour', 22);
        }
    }
    
    public static function calculateTimeRange($rentalType, $date, $elementId, $startHour = null, $hours = null)
    {
        $timezone = '+05:00';
        
        switch ($rentalType) {
            case 'full_day':
                $workEndHour = self::getWorkEndHour($elementId, $date);
                return [
                    'start' => $date . 'T10:00:00' . $timezone,
                    'end' => $date . 'T' . $workEndHour . ':00:00' . $timezone
                ];
            case 'night':
                $workEndHour = self::getWorkEndHour($elementId, $date);
                $nextDay = date('Y-m-d', strtotime($date . ' +1 day'));
                return [
                    'start' => $date . 'T' . $workEndHour . ':00:00' . $timezone,
                    'end' => $nextDay . 'T09:00:00' . $timezone
                ];
            case 'hourly':
                $start = $date . 'T' . sprintf('%02d', $startHour) . ':00:00' . $timezone;
                return [
                    'start' => $start,
                    'end' => date('Y-m-d\TH:i:sP', strtotime($start . ' +' . $hours . ' hours'))
                ];
            default:
                return null;
        }
    }
    
    public static function createBooking($resourceId, $startTime, $endTime, $userData)
    {
        $api = self::getApiClient();
        return $api->createReservation($resourceId, $startTime, $endTime, $userData);
    }
    
    public static function checkAvailability($resourceId, $startTime, $endTime)
    {
        $api = self::getApiClient();
        return $api->checkAvailability($resourceId, $startTime, $endTime);
    }
    
    public static function getAvailableSlots($resourceId, $date)
    {
        $api = self::getApiClient();
        return $api->getAvailableSlotsForDate($resourceId, $date);
    }
    
    public static function sendNotifications($reference, $bookingData, $depositAmount)
    {
        $notificationService = new AVSNotificationService();
        $notificationService->sendAdminEmail($reference, $bookingData, $depositAmount);
        $notificationService->sendBitrix24Lead($reference, $bookingData, $depositAmount);
    }
}
?>