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

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/payment_debug.log';

function writeLog($message)
{
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

writeLog('========== НАЧАЛО ЗАПРОСА ==========');
writeLog('POST: ' . print_r($_POST, true));

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
    writeLog('ОШИБКА: ' . $e->getMessage());
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
    
    writeLog('========== createPayment ==========');
    
    $elementId = (int)$_POST['element_id'];
    $date = $_POST['date'];
    $rentalType = $_POST['rental_type'];
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $comment = trim($_POST['comment'] ?? '');
    $startHour = (int)($_POST['start_hour'] ?? 10);
    $hours = (int)($_POST['hours'] ?? 4);
    
    writeLog("Параметры: elementId=$elementId, date=$date, rentalType=$rentalType, name=$name, phone=$phone, email=$email, startHour=$startHour, hours=$hours");
    
    if (!$elementId || !$date || !$rentalType || !$name || !$phone || !$email) {
        writeLog('ОШИБКА: Не заполнены обязательные поля');
        echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
        return;
    }
    
    $gazebo = AVSBookingModule::getGazeboData($elementId);
    if (!$gazebo || !$gazebo['resource_id']) {
        writeLog('ОШИБКА: Беседка не найдена, elementId=' . $elementId);
        echo json_encode(['success' => false, 'message' => 'Беседка не найдена']);
        return;
    }
    
    writeLog('Беседка найдена: ' . $gazebo['name'] . ', resource_id=' . $gazebo['resource_id']);
    
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
    
    writeLog("Время: start=$start, end=$end");
    
    $price = AVSBookingModule::getPriceForDate($elementId, $date, $rentalType);
    $totalPrice = $price * ($rentalType === 'hourly' ? $hours : 1);
    $depositAmount = $gazebo['deposit_amount'] ?? (float)Option::get('avs_booking', 'default_deposit_amount', 0);
    if ($depositAmount > $totalPrice) {
        $depositAmount = $totalPrice;
    }
    
    writeLog("Цена: price=$price, totalPrice=$totalPrice, depositAmount=$depositAmount");
    
    try {
        if (!CModule::IncludeModule('sale') || !CModule::IncludeModule('catalog')) {
            throw new Exception('Модули sale или catalog не установлены');
        }
        writeLog("Модули sale и catalog загружены");
        
        $siteId = SITE_ID;
        $userId = $USER->IsAuthorized() ? $USER->GetID() : \CSaleUser::GetAnonymousUserID();
        writeLog("siteId=$siteId, userId=$userId, USER->IsAuthorized=" . ($USER->IsAuthorized() ? 'true' : 'false'));
        
        // Создаем корзину
        writeLog("Создание корзины...");
        $basket = Basket::create($siteId);
        writeLog("Корзина создана");
        
        $serviceProductId = (int)Option::get('avs_booking', 'service_product_id', 0);
        if (!$serviceProductId) {
            throw new Exception('Не настроен товар для услуги аренды');
        }
        writeLog("serviceProductId=$serviceProductId");
        
        // Добавляем товар в корзину
        writeLog("Добавление товара в корзину...");
        $item = $basket->createItem('catalog', $serviceProductId);
        $item->setField('QUANTITY', 1);
        $item->setField('CUSTOM_PRICE', 'Y');
        $item->setField('PRICE', $totalPrice);
        $item->setField('NAME', 'Аренда ' . $gazebo['name'] . ' на ' . $date);
        $item->setField('CURRENCY', 'RUB');
        writeLog("Товар добавлен");
        
        // Сохраняем корзину
        writeLog("Сохранение корзины...");
        $basketSaveResult = $basket->save();
        if (!$basketSaveResult->isSuccess()) {
            $errors = $basketSaveResult->getErrorMessages();
            writeLog("ОШИБКИ при сохранении корзины: " . implode(', ', $errors));
            throw new Exception('Ошибка сохранения корзины: ' . implode(', ', $errors));
        }
        writeLog("Корзина сохранена");
        
        // Создаем заказ
        writeLog("Создание заказа...");
        $order = Order::create($siteId, $userId);
        $order->setPersonTypeId(1);
        $order->setBasket($basket);
        writeLog("Заказ создан");
        
        // Устанавливаем свойства заказа
        writeLog("Установка свойств заказа...");
        $propertyCollection = $order->getPropertyCollection();
        
        $nameProperty = $propertyCollection->getItemByOrderPropertyCode('FIO');
        if ($nameProperty) {
            $nameProperty->setValue($name);
            writeLog("Установлено имя: $name");
        }
        
        $phoneProperty = $propertyCollection->getItemByOrderPropertyCode('PHONE');
        if ($phoneProperty) {
            $phoneProperty->setValue($phone);
            writeLog("Установлен телефон: $phone");
        }
        
        $emailProperty = $propertyCollection->getItemByOrderPropertyCode('EMAIL');
        if ($emailProperty) {
            $emailProperty->setValue($email);
            writeLog("Установлен email: $email");
        }
        
        $order->setField('COMMENTS', "Бронирование беседки {$gazebo['name']}\nДата: {$date}\nВремя: {$start} - {$end}\nТип аренды: {$rentalType}\nТелефон: {$phone}\nКомментарий: {$comment}");
        
        // Сохраняем заказ
        writeLog("Сохранение заказа...");
        $orderResult = $order->save();
        if (!$orderResult->isSuccess()) {
            $errors = $orderResult->getErrorMessages();
            writeLog("ОШИБКИ при сохранении заказа: " . implode(', ', $errors));
            throw new Exception('Ошибка сохранения заказа: ' . implode(', ', $errors));
        }
        
        $orderId = $order->getId();
        
        // Принудительно устанавливаем ACCOUNT_NUMBER
        $order->setField('ACCOUNT_NUMBER', (string)$orderId);
        $order->save();
        
        writeLog("Заказ создан, ID=$orderId, ACCOUNT_NUMBER=" . $order->getField('ACCOUNT_NUMBER'));
        
        // Добавляем оплату
        writeLog("Создание платежа...");
        $paymentCollection = $order->getPaymentCollection();
        
        $paySystemId = (int)Option::get('avs_booking', 'yookassa_paysystem_id', 0);
        if (!$paySystemId) {
            throw new Exception('Не настроена платежная система ЮKassa');
        }
        writeLog("paySystemId=$paySystemId");
        
        $payment = $paymentCollection->createItem(Manager::getObjectById($paySystemId));
        $payment->setField('SUM', $depositAmount);
        $payment->setField('CURRENCY', 'RUB');
        writeLog("Платёж создан, сумма=$depositAmount");
        
        writeLog("Сохранение платежа...");
        $paymentResult = $payment->save();
        if (!$paymentResult->isSuccess()) {
            $errors = $paymentResult->getErrorMessages();
            writeLog("ОШИБКИ при сохранении платежа: " . implode(', ', $errors));
            throw new Exception('Ошибка сохранения платежа: ' . implode(', ', $errors));
        }
        
        $paymentId = $payment->getId();
        writeLog("Платёж сохранён, ID=$paymentId");
        
        // Сохраняем данные бронирования в сессию
        $_SESSION['avs_booking_data'] = [
            'order_id' => $orderId,
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
        writeLog("Данные бронирования сохранены в сессию, order_id=$orderId");
        
        // Получаем хэш заказа
        $hash = '';
        if (method_exists($order, 'getHash')) {
            $hash = $order->getHash();
            writeLog("Хэш заказа: $hash");
        }
        
        $url = '/bitrix/tools/sale_payment.php?ORDER_ID=' . $orderId . '&PAY_SYSTEM_ID=' . $paySystemId . '&HASH=' . $hash;
        writeLog("URL оплаты: $url");
        writeLog('========== createPayment УСПЕШНО ЗАВЕРШЁН ==========');
        
        echo json_encode([
            'success' => true,
            'confirmation_url' => $url
        ]);
        
    } catch (Exception $e) {
        writeLog('ОШИБКА в createPayment: ' . $e->getMessage());
        writeLog('Трассировка: ' . $e->getTraceAsString());
        writeLog('========== createPayment ЗАВЕРШЁН С ОШИБКОЙ ==========');
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка при создании заказа: ' . $e->getMessage()
        ]);
    }
}