<?php

use Bitrix\Main\EventManager;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;

class AVSBookingEventHandlers
{
    public static function onEpilog()
    {
        return true;
    }
}

class AVSBookingHandlers
{
    /**
     * Обработчик оплаты заказа (пока не используется, оставлен для будущего)
     */
    public static function onSalePaymentPaid(Event $event)
    {
        $payment = $event->getParameter('ENTITY');

        if ($payment->isPaid()) {
            $order = $payment->getOrder();
            $orderId = $order->getId();

            session_start();
            $bookingData = $_SESSION['avs_booking_data'] ?? null;

            if ($bookingData && $bookingData['order_id'] == $orderId) {
                $data = $bookingData['booking_data'];

                $result = AVSBookingModule::createBooking(
                    $data['resource_id'],
                    $data['start_time'],
                    $data['end_time'],
                    $data['user_data']
                );

                if (isset($result['referenceNumber'])) {
                    AVSBookingModule::sendNotifications(
                        $result['referenceNumber'],
                        $data,
                        $data['deposit_amount']
                    );

                    $order->setField('COMMENTS', $order->getField('COMMENTS') . "\nНомер бронирования: " . $result['referenceNumber']);
                    $order->save();

                    unset($_SESSION['avs_booking_data']);
                }
            }
        }
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
