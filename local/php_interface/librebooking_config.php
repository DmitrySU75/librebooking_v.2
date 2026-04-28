<?php
// Конфигурация LibreBooking
define('LIBREBOOKING_BASE_URL', 'https://park.na4u.ru/booking');
define('LIBREBOOKING_API_URL', LIBREBOOKING_BASE_URL . '/Web/Services/index.php');
define('LIBREBOOKING_API_USER', 'libreuser');
define('LIBREBOOKING_API_PASSWORD', '^ti*54gjnI');

// Настройки кэширования (в секундах)
define('LIBREBOOKING_CACHE_TTL', 3600);

// Длительность бронирования по умолчанию (минуты)
define('LIBREBOOKING_DEFAULT_DURATION', 60);

// ID инфоблока с беседками
define('LIBREBOOKING_IBLOCK_ID', 12);

// Код свойства с ID ресурса в LibreBooking
define('LIBREBOOKING_RESOURCE_PROPERTY_CODE', 'LIBREBOOKING_RESOURCE_ID');
