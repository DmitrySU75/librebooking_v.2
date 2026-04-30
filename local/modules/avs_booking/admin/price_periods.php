<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin.php';

$moduleId = 'avs_booking';
CModule::IncludeModule($moduleId);
CModule::IncludeModule('iblock');

$APPLICATION->SetTitle('Управление ценовыми периодами');

$GAZEBO_IBLOCK_ID = 12;

$gazebos = [];
$res = CIBlockElement::GetList(['NAME' => 'ASC'], ['IBLOCK_ID' => $GAZEBO_IBLOCK_ID, 'ACTIVE' => 'Y']);
while ($el = $res->GetNext()) {
    $gazebos[$el['ID']] = $el['NAME'];
}

$pricePeriodsIblockId = Option::get($moduleId, 'price_periods_iblock_id', 0);
$resourceId = $_GET['resource_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if ($pricePeriodsIblockId) {
        CIBlockElement::DeleteByProperty('RESOURCE_ID', $_POST['resource_id']);
        
        foreach ($_POST['periods'] as $period) {
            if (empty($period['date_from']) || empty($period['date_to'])) continue;
            
            $el = new CIBlockElement();
            $el->Add([
                'IBLOCK_ID' => $pricePeriodsIblockId,
                'NAME' => "Период с {$period['date_from']} по {$period['date_to']}",
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => [
                    'RESOURCE_ID' => $_POST['resource_id'],
                    'DATE_FROM' => $period['date_from'],
                    'DATE_TO' => $period['date_to'],
                    'PRICE_HOUR' => $period['price_hour'],
                    'PRICE_DAY' => $period['price_day'],
                    'PRICE_NIGHT' => $period['price_night']
                ]
            ]);
        }
        
        CAdminMessage::ShowMessage(['MESSAGE' => 'Цены сохранены', 'TYPE' => 'OK']);
    }
}

$existingPeriods = [];
if ($pricePeriodsIblockId && $resourceId) {
    $res = CIBlockElement::GetList(
        ['PROPERTY_DATE_FROM' => 'ASC'],
        [
            'IBLOCK_ID' => $pricePeriodsIblockId,
            'PROPERTY_RESOURCE_ID' => $resourceId,
            'ACTIVE' => 'Y'
        ]
    );
    while ($period = $res->GetNextElement()) {
        $props = $period->GetProperties();
        $existingPeriods[] = [
            'date_from' => $props['DATE_FROM']['VALUE'],
            'date_to' => $props['DATE_TO']['VALUE'],
            'price_hour' => $props['PRICE_HOUR']['VALUE'],
            'price_day' => $props['PRICE_DAY']['VALUE'],
            'price_night' => $props['PRICE_NIGHT']['VALUE']
        ];
    }
}
?>

<form method="post">
    <?= bitrix_sessid_post() ?>
    
    <div style="margin-bottom:20px;">
        <label>Выберите беседку:</label>
        <select name="resource_id" onchange="this.form.submit()">
            <option value="">-- Выберите --</option>
            <?php foreach ($gazebos as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($resourceId == $id) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <?php if ($resourceId): ?>
    <div id="periods-container">
        <h3>Ценовые периоды</h3>
        <p><small>Бронирования на дату, попадающую в период, используют цены этого периода.</small></p>
        
        <?php $periods = !empty($existingPeriods) ? $existingPeriods : [['date_from' => '', 'date_to' => '', 'price_hour' => '', 'price_day' => '', 'price_night' => '']]; ?>
        <?php foreach ($periods as $idx => $period): ?>
        <div class="period-row" style="border:1px solid #ccc; padding:10px; margin-bottom:10px; background:#f9f9f9;">
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <div><label>Дата от:</label><br><input type="date" name="periods[<?= $idx ?>][date_from]" value="<?= $period['date_from'] ?>"></div>
                <div><label>Дата до:</label><br><input type="date" name="periods[<?= $idx ?>][date_to]" value="<?= $period['date_to'] ?>"></div>
                <div><label>Цена час:</label><br><input type="text" name="periods[<?= $idx ?>][price_hour]" value="<?= $period['price_hour'] ?>" style="width:100px;"></div>
                <div><label>Цена день:</label><br><input type="text" name="periods[<?= $idx ?>][price_day]" value="<?= $period['price_day'] ?>" style="width:100px;"></div>
                <div><label>Цена ночь:</label><br><input type="text" name="periods[<?= $idx ?>][price_night]" value="<?= $period['price_night'] ?>" style="width:100px;"></div>
                <div><button type="button" onclick="this.closest('.period-row').remove()" style="background:#e74c3c; color:white; border:none; padding:6px 12px;">Удалить</button></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <button type="button" onclick="addPeriodRow()" style="background:#2ecc71; color:white; border:none; padding:8px 16px; margin-top:10px;">+ Добавить период</button>
    <input type="submit" value="Сохранить" style="background:#3498db; color:white; border:none; padding:8px 16px; margin-left:10px;">
    <?php endif; ?>
</form>

<script>
function addPeriodRow() {
    const container = document.getElementById('periods-container');
    const idx = container.querySelectorAll('.period-row').length;
    const newRow = document.createElement('div');
    newRow.className = 'period-row';
    newRow.style.cssText = 'border:1px solid #ccc; padding:10px; margin-bottom:10px; background:#f9f9f9;';
    newRow.innerHTML = `
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <div><label>Дата от:</label><br><input type="date" name="periods[${idx}][date_from]"></div>
            <div><label>Дата до:</label><br><input type="date" name="periods[${idx}][date_to]"></div>
            <div><label>Цена час:</label><br><input type="text" name="periods[${idx}][price_hour]" style="width:100px;"></div>
            <div><label>Цена день:</label><br><input type="text" name="periods[${idx}][price_day]" style="width:100px;"></div>
            <div><label>Цена ночь:</label><br><input type="text" name="periods[${idx}][price_night]" style="width:100px;"></div>
            <div><button type="button" onclick="this.closest('.period-row').remove()" style="background:#e74c3c; color:white; border:none; padding:6px 12px;">Удалить</button></div>
        </div>
    `;
    container.appendChild(newRow);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>