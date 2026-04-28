<?php

use Bitrix\Main\Config\Option;

$module_id = 'avs_booking';

$RIGHT = $APPLICATION->GetGroupRight($module_id);
if ($RIGHT < 'R') {
    $APPLICATION->AuthForm('Доступ запрещён');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $RIGHT >= 'W' && check_bitrix_sessid()) {
    Option::set($module_id, 'api_url', $_POST['api_url']);
    Option::set($module_id, 'api_username', $_POST['api_username']);
    Option::set($module_id, 'api_password', $_POST['api_password']);
    Option::set($module_id, 'default_schedule_id', $_POST['default_schedule_id']);
    Option::set($module_id, 'timezone_offset', $_POST['timezone_offset']);
    Option::set($module_id, 'default_deposit_amount', $_POST['default_deposit_amount']);
    Option::set($module_id, 'service_product_id', $_POST['service_product_id']);
    Option::set($module_id, 'yookassa_paysystem_id', $_POST['yookassa_paysystem_id']);
    Option::set($module_id, 'admin_email', $_POST['admin_email']);
    Option::set($module_id, 'bitrix24_webhook', $_POST['bitrix24_webhook']);
    Option::set($module_id, 'export_1c_url', $_POST['export_1c_url']);
    Option::set($module_id, 'export_1c_key', $_POST['export_1c_key']);
    Option::set($module_id, 'summer_season_start', $_POST['summer_season_start']);
    Option::set($module_id, 'summer_season_end', $_POST['summer_season_end']);
    Option::set($module_id, 'winter_end_hour', $_POST['winter_end_hour']);
    Option::set($module_id, 'summer_end_hour', $_POST['summer_end_hour']);

    if (isset($_POST['api_1c_key']) && !empty($_POST['api_1c_key'])) {
        Option::set($module_id, 'api_1c_key', $_POST['api_1c_key']);
    }

    CAdminMessage::ShowMessage([
        'MESSAGE' => 'Настройки сохранены',
        'TYPE' => 'OK'
    ]);
}

$APPLICATION->SetTitle('Настройки модуля бронирования AVS');

$aTabs = [
    [
        'DIV' => 'edit1',
        'TAB' => 'API LibreBooking',
        'TITLE' => 'Настройки подключения к LibreBooking',
    ],
    [
        'DIV' => 'edit2',
        'TAB' => 'Оплата',
        'TITLE' => 'Настройки оплаты',
    ],
    [
        'DIV' => 'edit3',
        'TAB' => 'Уведомления',
        'TITLE' => 'Настройки уведомлений',
    ],
    [
        'DIV' => 'edit4',
        'TAB' => 'API для 1С',
        'TITLE' => 'Настройки интеграции с 1С',
    ],
    [
        'DIV' => 'edit5',
        'TAB' => '1С (Экспорт)',
        'TITLE' => 'Настройки экспорта бронирований в 1С',
    ],
    [
        'DIV' => 'edit6',
        'TAB' => 'Сезонные настройки',
        'TITLE' => 'Настройка летнего и зимнего сезонов',
    ],
    [
        'DIV' => 'edit7',
        'TAB' => 'Настройки заказа',
        'TITLE' => 'Настройки для создания заказа',
    ],
];

$tabControl = new CAdminTabControl('tabControl', $aTabs);
?>

<form method="post">
    <?= bitrix_sessid_post() ?>
    <? $tabControl->Begin(); ?>

    <? $tabControl->BeginNextTab(); ?>
    <tr class="heading">
        <td colspan="2">Подключение к LibreBooking API</td>
    </tr>
    <tr>
        <td width="40%">URL API:</td>
        <td width="60%"><input type="text" name="api_url" value="<?= htmlspecialchars(Option::get($module_id, 'api_url', 'https://park.na4u.ru/booking/Web/Services/index.php')) ?>" size="60"></td>
    </tr>
    <tr>
        <td>Логин API:</td>
        <td><input type="text" name="api_username" value="<?= htmlspecialchars(Option::get($module_id, 'api_username')) ?>" size="40"></td>
    </tr>
    <tr>
        <td>Пароль API:</td>
        <td><input type="password" name="api_password" value="<?= htmlspecialchars(Option::get($module_id, 'api_password')) ?>" size="40" autocomplete="off"></td>
    </tr>
    <tr>
        <td>ID расписания по умолчанию:</td>
        <td><input type="text" name="default_schedule_id" value="<?= htmlspecialchars(Option::get($module_id, 'default_schedule_id', '2')) ?>" size="10"></td>
    </tr>
    <tr>
        <td>Часовой пояс:</td>
        <td><input type="text" name="timezone_offset" value="<?= htmlspecialchars(Option::get($module_id, 'timezone_offset', '+05:00')) ?>" size="10"></td>
    </tr>

    <? $tabControl->BeginNextTab(); ?>
    <tr class="heading">
        <td colspan="2">Настройки оплаты</td>
    </tr>
    <tr>
        <td width="40%">Сумма предоплаты по умолчанию (₽):</td>
        <td width="60%"><input type="text" name="default_deposit_amount" value="<?= htmlspecialchars(Option::get($module_id, 'default_deposit_amount', '0')) ?>" size="10"></td>
    </tr>

    <? $tabControl->BeginNextTab(); ?>
    <tr class="heading">
        <td colspan="2">Email и CRM уведомления</td>
    </tr>
    <tr>
        <td width="40%">Email администратора:</td>
        <td width="60%"><input type="text" name="admin_email" value="<?= htmlspecialchars(Option::get($module_id, 'admin_email')) ?>" size="40"></td>
    </tr>
    <tr>
        <td>Webhook Битрикс24:</td>
        <td><input type="text" name="bitrix24_webhook" value="<?= htmlspecialchars(Option::get($module_id, 'bitrix24_webhook')) ?>" size="60"></td>
    </tr>

    <? $tabControl->BeginNextTab(); ?>
    <tr class="heading">
        <td colspan="2">Настройки для интеграции с 1С (приём бронирований)</td>
    </tr>
    <tr>
        <td width="40%">API-ключ для 1С:</td>
        <td width="60%"><input type="text" name="api_1c_key" value="<?= htmlspecialchars(Option::get($module_id, 'api_1c_key')) ?>" size="40"><br><small>Используйте этот ключ в заголовке X-API-KEY при запросах из 1С</small></td>
    </tr>

    <? $tabControl->BeginNextTab(); ?>
    <tr class="heading">
        <td colspan="2">Настройки отправки бронирований в 1С (экспорт)</td>
    </tr>
    <tr>
        <td width="40%">URL API 1С:</td>
        <td width="60%"><input type="text" name="export_1c_url" value="<?= htmlspecialchars(Option::get($module_id, 'export_1c_url')) ?>" size="60"><br><small>Адрес эндпоинта 1С для приёма бронирований</small></td>
    </tr>
    <tr>
        <td>API-ключ для экспорта:</td>
        <td><input type="text" name="export_1c_key" value="<?= htmlspecialchars(Option::get($module_id, 'export_1c_key')) ?>" size="40"><br><small>Секретный ключ для авторизации в 1С</small></td>
    </tr>

    <? $tabControl->BeginNextTab(); ?>
    <tr class="heading">
        <td colspan="2">Настройка летнего и зимнего сезонов</td>
    </tr>
    <tr>
        <td width="40%">Начало летнего сезона:</td>
        <td width="60%"><input type="text" name="summer_season_start" value="<?= htmlspecialchars(Option::get($module_id, 'summer_season_start', '01.06')) ?>" size="10"><br><small>Формат: ДД.ММ</small></td>
    </tr>
    <tr>
        <td>Конец летнего сезона:</td>
        <td><input type="text" name="summer_season_end" value="<?= htmlspecialchars(Option::get($module_id, 'summer_season_end', '31.08')) ?>" size="10"><br><small>Формат: ДД.ММ</small></td>
    </tr>
    <tr>
        <td>Зимнее время окончания работы:</td>
        <td><input type="text" name="winter_end_hour" value="<?= htmlspecialchars(Option::get($module_id, 'winter_end_hour', '22')) ?>" size="5"></td>
    </tr>
    <tr>
        <td>Летнее время окончания работы:</td>
        <td><input type="text" name="summer_end_hour" value="<?= htmlspecialchars(Option::get($module_id, 'summer_end_hour', '23')) ?>" size="5"></td>
    </tr>

    <? $tabControl->BeginNextTab(); ?>
    <tr class="heading">
        <td colspan="2">Настройки для создания заказа</td>
    </tr>
    <tr>
        <td width="40%">ID товара "Аренда беседки":</td>
        <td width="60%">
            <input type="text" name="service_product_id" value="<?= htmlspecialchars(Option::get($module_id, 'service_product_id', '0')) ?>" size="10">
            <br><small>ID товара в каталоге для услуги аренды</small>
        </td>
    </tr>
    <tr>
        <td>ID платежной системы ЮKassa:</td>
        <td>
            <input type="text" name="yookassa_paysystem_id" value="<?= htmlspecialchars(Option::get($module_id, 'yookassa_paysystem_id', '0')) ?>" size="10">
            <br><small>ID из списка платежных систем Битрикс</small>
        </td>
    </tr>

    <? $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <? $tabControl->End(); ?>
</form>