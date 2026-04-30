<?php
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;

class avs_booking extends CModule
{
    public $MODULE_ID = 'avs_booking';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = 'Модуль бронирования AVS';
        $this->MODULE_DESCRIPTION = 'Интеграция с LibreBooking, ЮKassa, Битрикс24, 1С';
        $this->PARTNER_NAME = 'AVS Group';
        $this->PARTNER_URI = 'https://avsgroup.ru';
    }

    public function DoInstall()
    {
        global $APPLICATION;
        
        if (!CheckVersion(ModuleManager::getVersion('main'), '14.00.00')) {
            $APPLICATION->ThrowException('Версия главного модуля ниже 14.00.00');
            return false;
        }
        
        $this->InstallFiles();
        $this->InstallDB();
        $this->InstallEvents();
        
        ModuleManager::registerModule($this->MODULE_ID);
        
        return true;
    }

    public function DoUninstall()
    {
        $this->UnInstallFiles();
        $this->UnInstallDB();
        $this->UnInstallEvents();
        
        ModuleManager::unRegisterModule($this->MODULE_ID);
        
        return true;
    }

    public function InstallFiles()
    {
        // Создаём папку компонента
        $componentDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/avs_booking/booking.form';
        if (!is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }
        
        // Файл .description.php
        $desc = '<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

$arComponentDescription = [
    "NAME" => "Форма бронирования",
    "DESCRIPTION" => "Форма бронирования беседок",
    "PATH" => [
        "ID" => "avs_booking",
        "NAME" => "AVS Бронирование",
    ],
];
';
        file_put_contents($componentDir . '/.description.php', $desc);
        
        // Файл component.php
        $comp = '<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

if (!CModule::IncludeModule("avs_booking")) {
    ShowError("Модуль avs_booking не установлен");
    return;
}

$elementId = intval($arParams["ELEMENT_ID"]);
if (!$elementId) {
    ShowError("Не указан ID беседки");
    return;
}

$arResult["GAZEBO_DATA"] = AVSBookingModule::getGazeboData($elementId);
$arResult["ELEMENT_ID"] = $elementId;

$this->IncludeComponentTemplate();
';
        file_put_contents($componentDir . '/component.php', $comp);
        
        // Папка шаблона
        $templateDir = $componentDir . '/templates/.default';
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0755, true);
        }
        
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFilesEx('/bitrix/components/avs_booking/booking.form');
        return true;
    }
    
    public function InstallEvents()
    {
        $eventManager = EventManager::getInstance();
        
        $eventManager->registerEventHandler(
            'sale',
            'OnSalePaymentPaid',
            $this->MODULE_ID,
            'AVSBookingHandlers',
            'onSalePaymentPaid'
        );
        
        return true;
    }
    
    public function UnInstallEvents()
    {
        $eventManager = EventManager::getInstance();
        
        $eventManager->unRegisterEventHandler(
            'sale',
            'OnSalePaymentPaid',
            $this->MODULE_ID,
            'AVSBookingHandlers',
            'onSalePaymentPaid'
        );
        
        return true;
    }

    public function InstallDB()
    {
        // API LibreBooking
        Option::set($this->MODULE_ID, 'api_url', 'https://park.na4u.ru/booking/Web/Services/index.php');
        Option::set($this->MODULE_ID, 'api_username', '');
        Option::set($this->MODULE_ID, 'api_password', '');
        Option::set($this->MODULE_ID, 'default_schedule_id', 2);
        Option::set($this->MODULE_ID, 'timezone_offset', '+05:00');
        
        // Оплата
        Option::set($this->MODULE_ID, 'default_deposit_amount', 0);
        Option::set($this->MODULE_ID, 'yookassa_shop_id', '');
        Option::set($this->MODULE_ID, 'yookassa_secret_key', '');
        Option::set($this->MODULE_ID, 'service_product_id', 0);
        Option::set($this->MODULE_ID, 'yookassa_paysystem_id', 2);
        
        // Уведомления
        Option::set($this->MODULE_ID, 'admin_email', '');
        Option::set($this->MODULE_ID, 'bitrix24_webhook', '');
        
        // API для 1С
        Option::set($this->MODULE_ID, 'api_1c_key', md5(uniqid('avs_booking_', true) . time()));
        
        // Экспорт в 1С
        Option::set($this->MODULE_ID, 'export_1c_url', '');
        Option::set($this->MODULE_ID, 'export_1c_key', '');
        
        // Сезонные настройки
        Option::set($this->MODULE_ID, 'summer_season_start', '01.06');
        Option::set($this->MODULE_ID, 'summer_season_end', '31.08');
        Option::set($this->MODULE_ID, 'winter_end_hour', '22');
        Option::set($this->MODULE_ID, 'summer_end_hour', '23');
        
        // Создаём инфоблоки
        $this->createIblocks();
        
        return true;
    }

    public function UnInstallDB()
    {
        Option::delete($this->MODULE_ID);
        $this->removeIblocks();
        return true;
    }
    
    private function createIblocks()
    {
        if (!Loader::includeModule('iblock')) {
            return false;
        }
        
        $iblock = new \CIBlock();
        
        // 1. Инфоблок «Услуги и скидки»
        $servicesIblockId = $iblock->Add([
            'ACTIVE' => 'Y',
            'NAME' => 'Услуги и скидки бронирования',
            'IBLOCK_TYPE_ID' => 'services',
            'CODE' => 'booking_services',
            'SITE_ID' => ['s1'],
            'GROUP_ID' => ['2' => 'R', '1' => 'W'],
            'VERSION' => 2
        ]);
        
        if ($servicesIblockId) {
            $this->addServiceProperties($servicesIblockId);
            Option::set($this->MODULE_ID, 'services_iblock_id', $servicesIblockId);
        }
        
        // 2. Инфоблок «Ценовые периоды»
        $priceIblockId = $iblock->Add([
            'ACTIVE' => 'Y',
            'NAME' => 'Ценовые периоды',
            'IBLOCK_TYPE_ID' => 'prices',
            'CODE' => 'price_periods',
            'SITE_ID' => ['s1'],
            'GROUP_ID' => ['2' => 'R', '1' => 'W'],
            'VERSION' => 2
        ]);
        
        if ($priceIblockId) {
            $this->addPricePeriodProperties($priceIblockId);
            Option::set($this->MODULE_ID, 'price_periods_iblock_id', $priceIblockId);
        }
        
        return true;
    }
    
    private function addServiceProperties($iblockId)
    {
        $properties = [
            'SERVICE_TYPE' => [
                'NAME' => 'Тип услуги',
                'PROPERTY_TYPE' => 'L',
                'VALUES' => ['extra' => 'Доп. услуга', 'discount' => 'Скидка']
            ],
            'PRICE_TYPE' => [
                'NAME' => 'Тип цены',
                'PROPERTY_TYPE' => 'L',
                'VALUES' => [
                    'one_time' => 'Единоразовая',
                    'per_day' => 'В сутки',
                    'per_person' => 'За человека',
                    'discount_percent' => 'Скидка %'
                ]
            ],
            'PRICE_VALUE' => ['NAME' => 'Стоимость/процент', 'PROPERTY_TYPE' => 'N'],
            'APPLY_TO_RESOURCES' => ['NAME' => 'ID ресурсов', 'PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'Y'],
            'DATE_FROM' => ['NAME' => 'Дата начала', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            'DATE_TO' => ['NAME' => 'Дата окончания', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime']
        ];
        
        foreach ($properties as $code => $prop) {
            $prop['IBLOCK_ID'] = $iblockId;
            $prop['CODE'] = $code;
            $iblockProp = new \CIBlockProperty();
            $iblockProp->Add($prop);
        }
    }
    
    private function addPricePeriodProperties($iblockId)
    {
        $properties = [
            'RESOURCE_ID' => ['NAME' => 'ID беседки', 'PROPERTY_TYPE' => 'N', 'IS_REQUIRED' => 'Y'],
            'DATE_FROM' => ['NAME' => 'Дата начала', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime', 'IS_REQUIRED' => 'Y'],
            'DATE_TO' => ['NAME' => 'Дата окончания', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime', 'IS_REQUIRED' => 'Y'],
            'PRICE_HOUR' => ['NAME' => 'Цена час', 'PROPERTY_TYPE' => 'N'],
            'PRICE_DAY' => ['NAME' => 'Цена день', 'PROPERTY_TYPE' => 'N'],
            'PRICE_NIGHT' => ['NAME' => 'Цена ночь', 'PROPERTY_TYPE' => 'N']
        ];
        
        foreach ($properties as $code => $prop) {
            $prop['IBLOCK_ID'] = $iblockId;
            $prop['CODE'] = $code;
            $iblockProp = new \CIBlockProperty();
            $iblockProp->Add($prop);
        }
    }
    
    private function removeIblocks()
    {
        $servicesIblockId = Option::get($this->MODULE_ID, 'services_iblock_id', 0);
        if ($servicesIblockId) {
            \CIBlock::Delete($servicesIblockId);
        }
        
        $priceIblockId = Option::get($this->MODULE_ID, 'price_periods_iblock_id', 0);
        if ($priceIblockId) {
            \CIBlock::Delete($priceIblockId);
        }
    }
}