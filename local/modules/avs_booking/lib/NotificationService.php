<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class AVSNotificationService
{
    private $moduleId = 'avs_booking';

    public function sendAdminEmail($reference, $bookingData, $depositAmount)
    {
        $adminEmail = Option::get($this->moduleId, 'admin_email');
        if (!$adminEmail) return;

        $siteName = Option::get('main', 'site_name', 'park66.ru');
        $message = "
            <h2>Новое бронирование #{$reference}</h2>
            <p><strong>Объект:</strong> {$bookingData['resource_name']}</p>
            <p><strong>Клиент:</strong> {$bookingData['user_data']['first_name']} {$bookingData['user_data']['last_name']}</p>
            <p><strong>Телефон:</strong> {$bookingData['user_data']['phone']}</p>
            <p><strong>Email:</strong> {$bookingData['user_data']['email']}</p>
            <p><strong>Дата:</strong> {$bookingData['date']}</p>
            <p><strong>Тип аренды:</strong> {$bookingData['rental_type']}</p>
            <p><strong>Время:</strong> {$bookingData['start_time']} — {$bookingData['end_time']}</p>
            <p><strong>Предоплата:</strong> {$depositAmount} ₽</p>
            <p><strong>Комментарий:</strong> {$bookingData['user_data']['comment']}</p>
        ";

        $event = new \CEvent();
        $event->SendImmediate('BOOKING_NEW', 's1', [
            'EMAIL_TO' => $adminEmail,
            'MESSAGE' => $message,
            'SUBJECT' => "Новое бронирование #{$reference} на {$siteName}"
        ]);
    }

    public function sendBitrix24Lead($reference, $bookingData, $depositAmount)
    {
        $webhook = Option::get($this->moduleId, 'bitrix24_webhook');
        if (!$webhook) return;

        $data = [
            'fields' => [
                'TITLE' => "Бронирование беседки #{$reference}",
                'NAME' => $bookingData['user_data']['first_name'],
                'LAST_NAME' => $bookingData['user_data']['last_name'],
                'PHONE' => [['VALUE' => $bookingData['user_data']['phone'], 'VALUE_TYPE' => 'WORK']],
                'EMAIL' => [['VALUE' => $bookingData['user_data']['email'], 'VALUE_TYPE' => 'WORK']],
                'COMMENTS' => "Объект: {$bookingData['resource_name']}\n"
                    . "Дата: {$bookingData['date']}\n"
                    . "Тип: {$bookingData['rental_type']}\n"
                    . "Время: {$bookingData['start_time']} — {$bookingData['end_time']}\n"
                    . "Предоплата: {$depositAmount} ₽\n"
                    . "Комментарий: {$bookingData['user_data']['comment']}",
                'SOURCE_ID' => 'WEB'
            ]
        ];

        $ch = curl_init($webhook . '/crm.lead.add.json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    }
}
