<?php

use Bitrix\Main\Config\Option;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/include.php';

use Bitrix\Sale\Order;
use Bitrix\Sale\Basket;
use Bitrix\Sale\PaySystem\Manager;

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_available_slots':
            getAvailableSlots();
            break;
        case 'create_payment':
            createPayment();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getAvailableSlots()
{
    $elementId = (int)$_POST['element_id'];
    $date = $_POST['date'];

    if (!$elementId || !$date) {
        echo json_encode(['success' => false, 'message' => 'Не указаны беседка или дата']);
        return;
    }

    $gazebo = AVSBookingModule::getGazeboData($elementId);
    if (!$gazebo || !$gazebo['resource_id']) {
        echo json_encode(['success' => false, 'message' => 'Беседка не найдена']);
        return;
    }

    $rentalTypes = AVSBookingModule::getAvailableRentalTypes($elementId, $date);
    $slots = AVSBookingModule::getAvailableSlots($gazebo['resource_id'], $date);
    $workEndHour = AVSBookingModule::getWorkEndHour($elementId, $date);

    $availableTypes = [];
    foreach ($rentalTypes as $type => $info) {
        $isAvailable = false;

        switch ($type) {
            case 'hourly':
                $isAvailable = !empty($slots['hourly']);
                break;
            case 'full_day':
                $isAvailable = ($slots['full_day'] === true);
                break;
            case 'night':
                $isAvailable = ($slots['night'] === true);
                break;
        }

        if ($isAvailable) {
            $availableTypes[$type] = $info;
        }
    }

    echo json_encode([
        'success' => true,
        'slots' => $slots,
        'rental_types' => $availableTypes,
        'has_conflicts' => empty($availableTypes),
        'work_end_hour' => $workEndHour
    ]);
}

function createPayment()
{
    global $USER;

    $elementId = (int)$_POST['element_id'];
    $date = $_POST['date'];
    $rentalType = $_POST['rental_type'];
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $comment = trim($_POST['comment'] ?? '');
    $startHour = (int)($_POST['start_hour'] ?? 10);
    $hours = (int)($_POST['hours'] ?? 4);

    if (!$elementId || !$date || !$rentalType || !$name || !$phone || !$email) {
        echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
        return;
    }

    $gazebo = AVSBookingModule::getGazeboData($elementId);
    if (!$gazebo || !$gazebo['resource_id']) {
        echo json_encode(['success' => false, 'message' => 'Беседка не найдена']);
        return;
    }

    $timezone = '+05:00';

    switch ($rentalType) {
        case 'full_day':
            $workEndHour = AVSBookingModule::getWorkEndHour($elementId, $date);
            $start = $date . 'T10:00:00' . $timezone;
            $end = $date . 'T' . $workEndHour . ':00:00' . $timezone;
            break;
        case 'night':
            $workEndHour = AVSBookingModule::getWorkEndHour($elementId, $date);
            $nextDay = date('Y-m-d', strtotime($date . ' +1 day'));
            $start = $date . 'T' . $workEndHour . ':00:00' . $timezone;
            $end = $nextDay . 'T09:00:00' . $timezone;
            break;
        case 'hourly':
            $start = $date . 'T' . sprintf('%02d', $startHour) . ':00:00' . $timezone;
            $end = date('Y-m-d\TH:i:sP', strtotime($start . ' +' . $hours . ' hours'));
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Неверный тип аренды']);
            return;
    }

    $price = AVSBookingModule::getPriceForDate($elementId, $date, $rentalType);
    $totalPrice = $price * ($rentalType === 'hourly' ? $hours : 1);

    $depositAmount = $gazebo['deposit_amount'] ?? (float)Option::get('avs_booking', 'default_deposit_amount', 0);
    if ($depositAmount > $totalPrice) {
        $depositAmount = $totalPrice;
    }

    try {
        if (!CModule::IncludeModule('sale') || !CModule::IncludeModule('catalog')) {
            throw new Exception('Модули sale или catalog не установлены');
        }

        $siteId = SITE_ID;
        $userId = $USER->IsAuthorized() ? $USER->GetID() : \CSaleUser::GetAnonymousUserID();

        $basket = Basket::create($siteId);

        $serviceProductId = (int)Option::get('avs_booking', 'service_product_id', 0);
        if (!$serviceProductId) {
            throw new Exception('Не настроен товар для услуги аренды');
        }

        $item = $basket->createItem('catalog', $serviceProductId);
        $item->setField('QUANTITY', 1);
        $item->setField('CUSTOM_PRICE', 'Y');
        $item->setField('PRICE', $totalPrice);
        $item->setField('NAME', 'Аренда ' . $gazebo['name'] . ' на ' . $date);
        $item->setField('CURRENCY', 'RUB');

        $basket->save();

        $order = Order::create($siteId, $userId);
        $order->setPersonTypeId(1);
        $order->setBasket($basket);

        $propertyCollection = $order->getPropertyCollection();

        $nameProperty = $propertyCollection->getItemByOrderPropertyCode('FIO');
        if ($nameProperty) {
            $nameProperty->setValue($name);
        }

        $phoneProperty = $propertyCollection->getItemByOrderPropertyCode('PHONE');
        if ($phoneProperty) {
            $phoneProperty->setValue($phone);
        }

        $emailProperty = $propertyCollection->getItemByOrderPropertyCode('EMAIL');
        if ($emailProperty) {
            $emailProperty->setValue($email);
        }

        $order->setField('COMMENTS', "Бронирование беседки {$gazebo['name']}\nДата: {$date}\nВремя: {$start} - {$end}\nТип аренды: {$rentalType}\nТелефон: {$phone}\nКомментарий: {$comment}");

        $orderResult = $order->save();
        if (!$orderResult->isSuccess()) {
            throw new Exception(implode(', ', $orderResult->getErrorMessages()));
        }

        $paymentCollection = $order->getPaymentCollection();

        $paySystemId = (int)Option::get('avs_booking', 'yookassa_paysystem_id', 0);
        if (!$paySystemId) {
            throw new Exception('Не настроена платежная система ЮKassa');
        }

        $payment = $paymentCollection->createItem(Manager::getObjectById($paySystemId));
        $payment->setField('SUM', $depositAmount);
        $payment->setField('CURRENCY', 'RUB');

        $paymentResult = $payment->save();
        if (!$paymentResult->isSuccess()) {
            throw new Exception(implode(', ', $paymentResult->getErrorMessages()));
        }

        $_SESSION['avs_booking_data'] = [
            'order_id' => $order->getId(),
            'booking_data' => [
                'resource_id' => $gazebo['resource_id'],
                'resource_name' => $gazebo['name'],
                'date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'rental_type' => $rentalType,
                'total_price' => $totalPrice,
                'deposit_amount' => $depositAmount,
                'user_data' => [
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[1] ?? '',
                    'phone' => $phone,
                    'email' => $email,
                    'comment' => $comment
                ]
            ]
        ];

        // ВРЕМЕННО: создаём бронирование сразу (для теста, без оплаты)
        $bookingResult = AVSBookingModule::createBooking(
            $gazebo['resource_id'],
            $start,
            $end,
            [
                'first_name' => explode(' ', $name)[0],
                'last_name' => explode(' ', $name)[1] ?? '',
                'phone' => $phone,
                'email' => $email,
                'comment' => $comment
            ]
        );

        if (isset($bookingResult['referenceNumber'])) {
            $bookingData = [
                'resource_name' => $gazebo['name'],
                'date' => $date,
                'rental_type' => $rentalType,
                'start_time' => $start,
                'end_time' => $end,
                'user_data' => [
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[1] ?? '',
                    'phone' => $phone,
                    'email' => $email,
                    'comment' => $comment
                ]
            ];
            AVSBookingModule::sendNotifications($bookingResult['referenceNumber'], $bookingData, $depositAmount);
        }

        echo json_encode([
            'success' => true,
            'confirmation_url' => '/booking-success/'
        ]);
    } catch (Exception $e) {
        $errorMessage = urlencode('Ошибка: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'confirmation_url' => '/booking-error/?error=' . $errorMessage
        ]);
    }
}
