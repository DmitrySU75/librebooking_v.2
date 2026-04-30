<?php

use Bitrix\Main\EventManager;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Main\Event;

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/payment_debug.log';

function writeLogHandler($message)
{
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [handlers.php] ' . $message . PHP_EOL, FILE_APPEND);
}

class AVSBookingEventHandlers
{
    public static function onEpilog()
    {
        return true;
    }
}

class AVSBookingHandlers
{
    public static function onSalePaymentPaid(Event $event)
    {
        writeLogHandler('========== onSalePaymentPaid ВЫЗВАН ==========');

        $payment = $event->getParameter('ENTITY');

        if (!$payment) {
            writeLogHandler('ОШИБКА: payment не найден');
            return;
        }

        writeLogHandler('payment ID: ' . $payment->getId());
        writeLogHandler('payment paid: ' . ($payment->isPaid() ? 'true' : 'false'));

        if (!$payment->isPaid()) {
            writeLogHandler('Платёж не оплачен, выходим');
            return;
        }

        $order = $payment->getOrder();
        $orderId = $order->getId();
        writeLogHandler("Заказ ID: $orderId");

        session_start();
        $bookingData = $_SESSION['avs_booking_data'] ?? null;

        if ($bookingData && $bookingData['order_id'] == $orderId) {
            $data = $bookingData['booking_data'];
            writeLogHandler("Данные бронирования найдены: " . print_r($data, true));

            $result = AVSBookingModule::createBooking(
                $data['resource_id'],
                $data['start_time'],
                $data['end_time'],
                $data['user_data']
            );

            if (isset($result['referenceNumber'])) {
                writeLogHandler("Бронирование создано: {$result['referenceNumber']}");

                AVSBookingModule::sendNotifications(
                    $result['referenceNumber'],
                    $data,
                    $data['deposit_amount']
                );

                $order->setField('COMMENTS', $order->getField('COMMENTS') . "\nНомер бронирования в LibreBooking: " . $result['referenceNumber']);
                $order->save();

                unset($_SESSION['avs_booking_data']);
                writeLogHandler("Сессия очищена");
            } else {
                writeLogHandler("ОШИБКА: Бронирование не создано");
            }
        } else {
            writeLogHandler("Данные бронирования НЕ найдены для order_id=$orderId");
            writeLogHandler('$_SESSION[avs_booking_data]: ' . print_r($_SESSION['avs_booking_data'] ?? 'null', true));
        }

        writeLogHandler('========== onSalePaymentPaid ЗАВЕРШЁН ==========');
    }
}

// Регистрация обработчиков
$eventManager = EventManager::getInstance();

$eventManager->registerEventHandler(
    'sale',
    'OnSalePaymentPaid',
    'avs_booking',
    'AVSBookingHandlers',
    'onSalePaymentPaid'
);

writeLogHandler('Обработчик onSalePaymentPaid зарегистрирован');
