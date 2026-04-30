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
        $this->MODULE_DESCRIPTION = 'Интеграция с LibreBooking, ЮKassa, Битрикс24';
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
        $componentDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/avs_booking/booking.form';
        if (!is_dir($componentDir)) {
            mkdir($componentDir, 0755, true);
        }

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

        $templateDir = $componentDir . '/templates/.default';
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0755, true);
        }

        $sourceTemplate = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/avs_booking/templates/.default/template.php';
        if (file_exists($sourceTemplate)) {
            copy($sourceTemplate, $templateDir . '/template.php');
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
            'main',
            'OnEpilog',
            $this->MODULE_ID,
            'AVSBookingEventHandlers',
            'onEpilog'
        );

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
            'main',
            'OnEpilog',
            $this->MODULE_ID,
            'AVSBookingEventHandlers',
            'onEpilog'
        );

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
        Option::set($this->MODULE_ID, 'api_url', 'https://park.na4u.ru/booking/Web/Services/index.php');
        Option::set($this->MODULE_ID, 'api_username', '');
        Option::set($this->MODULE_ID, 'api_password', '');
        Option::set($this->MODULE_ID, 'default_schedule_id', 2);
        Option::set($this->MODULE_ID, 'timezone_offset', '+05:00');
        Option::set($this->MODULE_ID, 'default_deposit_amount', 0);
        Option::set($this->MODULE_ID, 'service_product_id', 347);
        Option::set($this->MODULE_ID, 'yookassa_paysystem_id', 2);
        Option::set($this->MODULE_ID, 'admin_email', '');
        Option::set($this->MODULE_ID, 'bitrix24_webhook', '');
        Option::set($this->MODULE_ID, 'api_1c_key', md5(uniqid('avs_booking_', true) . time()));
        Option::set($this->MODULE_ID, 'export_1c_url', '');
        Option::set($this->MODULE_ID, 'export_1c_key', '');
        Option::set($this->MODULE_ID, 'summer_season_start', '01.06');
        Option::set($this->MODULE_ID, 'summer_season_end', '31.08');
        Option::set($this->MODULE_ID, 'winter_end_hour', '22');
        Option::set($this->MODULE_ID, 'summer_end_hour', '23');

        return true;
    }

    public function UnInstallDB()
    {
        Option::delete($this->MODULE_ID);
        return true;
    }
}
