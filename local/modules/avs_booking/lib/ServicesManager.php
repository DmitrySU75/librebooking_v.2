<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

class AVSServicesManager
{
    private static $moduleId = 'avs_booking';

    public static function getAvailableServices($resourceId, $bookingDate)
    {
        $iblockId = Option::get(self::$moduleId, 'services_iblock_id', 0);
        if (!$iblockId || !Loader::includeModule('iblock')) return [];

        $services = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' => 'Y',
                [
                    'LOGIC' => 'OR',
                    ['PROPERTY_APPLY_TO_RESOURCES' => false],
                    ['PROPERTY_APPLY_TO_RESOURCES' => $resourceId],
                    ['PROPERTY_APPLY_TO_RESOURCES' => 'ALL']
                ]
            ],
            false,
            false,
            ['ID', 'NAME', 'PREVIEW_TEXT', 'PROPERTY_SERVICE_TYPE', 'PROPERTY_PRICE_TYPE', 'PROPERTY_PRICE_VALUE']
        );

        while ($service = $res->GetNextElement()) {
            $fields = $service->GetFields();
            $props = $service->GetProperties();

            $services[] = [
                'id' => $fields['ID'],
                'name' => $fields['NAME'],
                'description' => $fields['PREVIEW_TEXT'],
                'type' => $props['SERVICE_TYPE']['VALUE_XML_ID'],
                'price_type' => $props['PRICE_TYPE']['VALUE_XML_ID'],
                'price_value' => (float)$props['PRICE_VALUE']['VALUE']
            ];
        }

        return $services;
    }

    public static function calculateServicePrice($service, $durationDays, $quantity = 1)
    {
        switch ($service['price_type']) {
            case 'one_time':
                return $service['price_value'] * $quantity;
            case 'per_day':
                return $service['price_value'] * $durationDays * $quantity;
            case 'discount_percent':
                return - ($service['price_value'] / 100);
            default:
                return $service['price_value'] * $quantity;
        }
    }
}
