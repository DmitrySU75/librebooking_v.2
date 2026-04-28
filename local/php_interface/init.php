<?php
// Устанавливаем часовой пояс как в LibreBooking
date_default_timezone_set('Asia/Yekaterinburg');

// Подключение конфигурации LibreBooking
require_once __DIR__ . '/librebooking_config.php';

// Подключаем API-класс
require_once __DIR__ . '/lib/LibreBookingAPI.php';

// Подключаем обработчики смартфильтра
require_once __DIR__ . '/include/smartfilter_date.php';

use Bitrix\Main\EventManager;

// Регистрируем модуль avs_booking
CModule::AddAutoloadClasses(
    'avs_booking',
    [
        'AVSBookingModule' => '/local/modules/avs_booking/include.php',
        'AVSBookingApiClient' => '/local/modules/avs_booking/lib/ApiClient.php',
        'AVSPaymentHandler' => '/local/modules/avs_booking/lib/PaymentHandler.php',
        'AVSNotificationService' => '/local/modules/avs_booking/lib/NotificationService.php',
        'AVSServicesManager' => '/local/modules/avs_booking/lib/ServicesManager.php',
    ]
);

$eventManager = EventManager::getInstance();

// Событие для добавления виртуального поля в фильтр
$eventManager->addEventHandler(
    'iblock',
    'OnBuildFilterSelect',
    ['LibreBookingSmartFilter', 'onBuildFilterSelect']
);

// Событие для модификации фильтра перед выборкой элементов
$eventManager->addEventHandler(
    'iblock',
    'OnBeforeCIBlockElementGetList',
    ['LibreBookingSmartFilter', 'onBeforeGetList']
);
