<?php

use Bitrix\Main\Event;
use Bitrix\Main\Data\Cache;

class LibreBookingSmartFilter
{
    public static function onBuildFilterSelect(Event $event)
    {
        $params = $event->getParameters();
        $arFields = &$params[2];

        $arFields['AVAILABLE_DATE'] = [
            'NAME' => 'Доступность по дате',
            'TYPE' => 'DATE',
            'CODE' => 'AVAILABLE_DATE',
            'VALUES' => [],
        ];

        return $params[0];
    }

    public static function onBeforeGetList(Event $event)
    {
        $params = $event->getParameters();
        $filter = &$params[0];

        global $arrFilter;

        if (isset($arrFilter) && is_array($arrFilter) && empty($filter)) {
            $filter = &$arrFilter;
        }

        $requestDate = $_REQUEST['available_date'] ?? '';
        $sessionDate = $_SESSION['LIBREBOOKING_FILTER_DATE'] ?? '';
        $date = $requestDate ?: $sessionDate;

        if (!empty($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $_SESSION['LIBREBOOKING_FILTER_DATE'] = $date;
            $availableElements = self::getAvailableElementsIds($date);

            if (!empty($availableElements)) {
                if (isset($filter['ID']) && !empty($filter['ID'])) {
                    $existingIds = is_array($filter['ID']) ? $filter['ID'] : [$filter['ID']];
                    $filter['ID'] = array_intersect($existingIds, $availableElements);
                } else {
                    $filter['ID'] = $availableElements;
                }
            } else {
                $filter['ID'] = 0;
            }
        }

        return $filter;
    }

    private static function getAvailableElementsIds($date)
    {
        $cacheId = 'librebooking_available_' . md5($date);
        $cachePath = '/librebooking/available/';
        $cache = Cache::createInstance();

        if ($cache->initCache(LIBREBOOKING_CACHE_TTL, $cacheId, $cachePath)) {
            return $cache->getVars();
        }

        $resourceMap = [];
        $dbItems = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => LIBREBOOKING_IBLOCK_ID,
                'ACTIVE' => 'Y',
                '!PROPERTY_' . LIBREBOOKING_RESOURCE_PROPERTY_CODE => false
            ],
            false,
            false,
            ['ID', 'PROPERTY_' . LIBREBOOKING_RESOURCE_PROPERTY_CODE]
        );

        while ($item = $dbItems->Fetch()) {
            $rid = (int)$item['PROPERTY_' . LIBREBOOKING_RESOURCE_PROPERTY_CODE . '_VALUE'];
            if ($rid) {
                $resourceMap[$rid] = $item['ID'];
            }
        }

        if (empty($resourceMap)) {
            return [];
        }

        $api = new LibreBookingAPI();
        $availability = $api->checkMultipleAvailability(
            array_keys($resourceMap),
            $date,
            LIBREBOOKING_DEFAULT_DURATION
        );

        $availableElements = [];
        foreach ($availability as $resourceId => $isAvailable) {
            if ($isAvailable && isset($resourceMap[$resourceId])) {
                $availableElements[] = $resourceMap[$resourceId];
            }
        }

        $cache->startDataCache();
        $cache->endDataCache($availableElements);

        return $availableElements;
    }

    public static function clearAvailabilityCache()
    {
        $cache = Cache::createInstance();
        $cache->cleanDir('/librebooking/available/');

        if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/upload/booking_token.json')) {
            unlink($_SERVER['DOCUMENT_ROOT'] . '/upload/booking_token.json');
        }
    }
}
