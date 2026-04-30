<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once __DIR__ . '/include.php';

header('Content-Type: application/json');

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/api_1c_debug.log';

function writeApiLog($message)
{
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [api_1c.php] ' . $message . PHP_EOL, FILE_APPEND);
}

writeApiLog('========== НАЧАЛО ==========');
writeApiLog('REQUEST: ' . print_r($_REQUEST, true));
writeApiLog('RAW INPUT: ' . file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    writeApiLog('ОШИБКА: Method not allowed');
    die();
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
if (!$apiKey && isset($input['api_key'])) {
    $apiKey = $input['api_key'];
}

$expectedKey = \Bitrix\Main\Config\Option::get('avs_booking', 'api_1c_key', '');
if (!$expectedKey || $apiKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid API key']);
    writeApiLog('ОШИБКА: Unauthorized');
    die();
}

writeApiLog('API-ключ проверен успешно');

$action = $input['action'] ?? '';

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action parameter']);
    writeApiLog('ОШИБКА: Missing action');
    die();
}

try {
    switch ($action) {
        case 'create_booking':
            createBooking($input);
            break;
        case 'cancel_booking':
            cancelBooking($input);
            break;
        case 'change_booking':
            changeBooking($input);
            break;
        case 'update_prices':
            updatePrices($input);
            break;
        case 'get_booking_status':
            getBookingStatus($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
            writeApiLog('ОШИБКА: Unknown action - ' . $action);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    writeApiLog('ИСКЛЮЧЕНИЕ: ' . $e->getMessage());
}

writeApiLog('========== КОНЕЦ ==========');

// ==================== CREATE BOOKING ====================
function createBooking($input)
{
    writeApiLog('========== CREATE BOOKING ==========');
    
    $required = ['resource_id', 'start_time', 'end_time', 'first_name', 'last_name'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            writeApiLog("ОШИБКА: Missing field - {$field}");
            return;
        }
    }
    
    writeApiLog("Параметры: resource_id={$input['resource_id']}, start_time={$input['start_time']}, end_time={$input['end_time']}");
    
    $gazebo = AVSBookingModule::getGazeboDataByResourceId((int)$input['resource_id']);
    if (!$gazebo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Resource not found in Bitrix']);
        writeApiLog("ОШИБКА: Resource not found - resource_id={$input['resource_id']}");
        return;
    }
    
    writeApiLog("Беседка найдена: {$gazebo['name']}, ID={$gazebo['resource_id']}");
    
    $available = AVSBookingModule::checkAvailability(
        (int)$input['resource_id'],
        $input['start_time'],
        $input['end_time']
    );
    
    if (!$available) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Selected time slot is already booked']);
        writeApiLog("ОШИБКА: Time slot already booked - {$input['start_time']} - {$input['end_time']}");
        return;
    }
    
    writeApiLog("Время доступно");
    
    try {
        $source = $input['source'] ?? '1c';
        $createdBy = $input['created_by'] ?? '';
        $document1c = $input['document_1c'] ?? '';
        
        $comment = $input['comment'] ?? '';
        if ($source === '1c') {
            $comment = "Создано из 1С. " . ($createdBy ? "Сотрудник: {$createdBy}. " : "") 
                     . ($document1c ? "Документ: {$document1c}. " : "") 
                     . $comment;
        }
        
        $userData = [
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'phone' => $input['phone'] ?? '',
            'email' => $input['email'] ?? '',
            'comment' => $comment
        ];
        
        $result = AVSBookingModule::createBooking(
            (int)$input['resource_id'],
            $input['start_time'],
            $input['end_time'],
            $userData
        );
        
        if (isset($result['referenceNumber'])) {
            saveBookingReference($result['referenceNumber'], $input);
            
            writeApiLog("Бронирование создано: {$result['referenceNumber']}");
            
            $logMessage = date('[Y-m-d H:i:s]') . " Создано бронирование из {$source}";
            if ($createdBy) {
                $logMessage .= " (автор: {$createdBy})";
            }
            $logMessage .= ": {$result['referenceNumber']}\n";
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/booking_reference.log', $logMessage, FILE_APPEND);
            
            echo json_encode([
                'success' => true,
                'reference' => $result['referenceNumber'],
                'message' => 'Booking created successfully'
            ]);
        } else {
            throw new Exception('Failed to create booking in LibreBooking');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        writeApiLog("ОШИБКА: " . $e->getMessage());
    }
}

// ==================== CANCEL BOOKING ====================
function cancelBooking($input)
{
    writeApiLog('========== CANCEL BOOKING ==========');
    
    $required = ['reference'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            writeApiLog("ОШИБКА: Missing field - {$field}");
            return;
        }
    }
    
    $reference = $input['reference'];
    writeApiLog("Отмена бронирования: {$reference}");
    
    try {
        $api = AVSBookingModule::getApiClient();
        $result = $api->cancelReservation($reference);
        
        if ($result) {
            $logMessage = date('[Y-m-d H:i:s]') . " Отмена бронирования из 1С: {$reference}\n";
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/import_1c.log', $logMessage, FILE_APPEND);
            
            writeApiLog("Бронирование отменено: {$reference}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Booking cancelled successfully'
            ]);
        } else {
            throw new Exception('Failed to cancel booking');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        writeApiLog("ОШИБКА: " . $e->getMessage());
    }
}

// ==================== CHANGE BOOKING ====================
function changeBooking($input)
{
    writeApiLog('========== CHANGE BOOKING ==========');
    
    $required = ['reference', 'new_start_time', 'new_end_time'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            writeApiLog("ОШИБКА: Missing field - {$field}");
            return;
        }
    }
    
    $reference = $input['reference'];
    $newResourceId = isset($input['new_resource_id']) ? (int)$input['new_resource_id'] : null;
    $newStartTime = $input['new_start_time'];
    $newEndTime = $input['new_end_time'];
    
    writeApiLog("Изменение бронирования: {$reference}");
    writeApiLog("Новое время: {$newStartTime} - {$newEndTime}");
    
    try {
        $api = AVSBookingModule::getApiClient();
        
        if ($newResourceId) {
            writeApiLog("Новый ресурс ID: {$newResourceId}");
            $available = AVSBookingModule::checkAvailability($newResourceId, $newStartTime, $newEndTime);
            if (!$available) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'New time slot is already booked']);
                writeApiLog("ОШИБКА: Новый слот уже занят");
                return;
            }
        }
        
        $result = $api->updateReservation($reference, $newStartTime, $newEndTime, $newResourceId);
        
        if ($result) {
            $logMessage = date('[Y-m-d H:i:s]') . " Изменение бронирования из 1С: {$reference}\n";
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/import_1c.log', $logMessage, FILE_APPEND);
            
            writeApiLog("Бронирование изменено: {$reference}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Booking updated successfully',
                'reference' => $reference
            ]);
        } else {
            throw new Exception('Failed to update booking');
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        writeApiLog("ОШИБКА: " . $e->getMessage());
    }
}

// ==================== UPDATE PRICES ====================
function updatePrices($input)
{
    writeApiLog('========== UPDATE PRICES ==========');
    
    $required = ['prices'];
    foreach ($required as $field) {
        if (empty($input[$field]) || !is_array($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing or invalid field: {$field}"]);
            writeApiLog("ОШИБКА: Missing or invalid field - {$field}");
            return;
        }
    }
    
    $updated = 0;
    $errors = [];
    $pricePeriodsIblockId = \Bitrix\Main\Config\Option::get('avs_booking', 'price_periods_iblock_id', 0);
    
    writeApiLog("Инфоблок ценовых периодов: {$pricePeriodsIblockId}");
    writeApiLog("Количество записей для обработки: " . count($input['prices']));
    
    foreach ($input['prices'] as $priceData) {
        $resourceId = isset($priceData['resource_id']) ? (int)$priceData['resource_id'] : 0;
        
        if ($resourceId > 0) {
            $elementId = $resourceId;
            writeApiLog("Поиск по ID: {$elementId}");
        } else {
            $res = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => 12, 'NAME' => $priceData['resource_name'], 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID']
            );
            if ($el = $res->Fetch()) {
                $elementId = $el['ID'];
                writeApiLog("Поиск по названию '{$priceData['resource_name']}': найден ID {$elementId}");
            } else {
                $errors[] = "Resource not found: {$priceData['resource_name']}";
                writeApiLog("ОШИБКА: Ресурс не найден - {$priceData['resource_name']}");
                continue;
            }
        }
        
        if ($pricePeriodsIblockId) {
            \CIBlockElement::DeleteByProperty('RESOURCE_ID', $elementId);
            writeApiLog("Удалены старые периоды для беседки ID {$elementId}");
            
            $el = new \CIBlockElement();
            $addResult = $el->Add([
                'IBLOCK_ID' => $pricePeriodsIblockId,
                'NAME' => "Период с {$priceData['date_from']} по {$priceData['date_to']}",
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => [
                    'RESOURCE_ID' => $elementId,
                    'DATE_FROM' => $priceData['date_from'],
                    'DATE_TO' => $priceData['date_to'],
                    'PRICE_HOUR' => $priceData['price_hour'],
                    'PRICE_DAY' => $priceData['price_day'],
                    'PRICE_NIGHT' => $priceData['price_night']
                ]
            ]);
            
            if ($addResult) {
                $updated++;
                writeApiLog("Добавлен период для беседки ID {$elementId}: {$priceData['date_from']} - {$priceData['date_to']}");
            } else {
                $errors[] = "Failed to add period for resource ID {$elementId}";
                writeApiLog("ОШИБКА: Не удалось добавить период для беседки ID {$elementId}");
            }
            
            \CIBlockElement::SetPropertyValues($elementId, 12, $priceData['price_hour'], 'PRICE_HOUR');
            \CIBlockElement::SetPropertyValues($elementId, 12, $priceData['price_day'], 'PRICE');
            \CIBlockElement::SetPropertyValues($elementId, 12, $priceData['price_night'], 'PRICE_NIGHT');
            writeApiLog("Обновлены текущие цены для беседки ID {$elementId}");
        }
    }
    
    $logMessage = date('[Y-m-d H:i:s]') . " Обновление цен: {$updated} обновлено, ошибок: " . count($errors) . "\n";
    file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/price_sync.log', $logMessage, FILE_APPEND);
    
    writeApiLog("Итог: обновлено {$updated}, ошибок " . count($errors));
    
    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'errors' => $errors,
        'message' => "Обновлено цен: {$updated}"
    ]);
}

// ==================== GET BOOKING STATUS ====================
function getBookingStatus($input)
{
    writeApiLog('========== GET BOOKING STATUS ==========');
    
    $required = ['reference'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
            writeApiLog("ОШИБКА: Missing field - {$field}");
            return;
        }
    }
    
    $reference = $input['reference'];
    writeApiLog("Запрос статуса: {$reference}");
    
    try {
        $api = AVSBookingModule::getApiClient();
        $reservation = $api->getReservation($reference);
        
        if ($reservation) {
            $status = 'active';
            if (isset($reservation['startDate']) && strtotime($reservation['startDate']) < time()) {
                $status = 'completed';
            }
            
            writeApiLog("Статус бронирования {$reference}: {$status}");
            
            echo json_encode([
                'success' => true,
                'reference' => $reference,
                'status' => $status,
                'start_time' => $reservation['startDate'] ?? null,
                'end_time' => $reservation['endDate'] ?? null
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Booking not found'
            ]);
            writeApiLog("ОШИБКА: Бронирование не найдено - {$reference}");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        writeApiLog("ОШИБКА: " . $e->getMessage());
    }
}

function saveBookingReference($reference, $input)
{
    writeApiLog("Сохранение связи: {$reference}");
    
    $data = [
        'reference' => $reference,
        'resource_id' => $input['resource_id'],
        'start_time' => $input['start_time'],
        'end_time' => $input['end_time'],
        'created_at' => date('Y-m-d H:i:s'),
        'source' => $input['source'] ?? '1c',
        'created_by' => $input['created_by'] ?? '',
        'document_1c' => $input['document_1c'] ?? ''
    ];
    
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/booking_reference.log';
    file_put_contents($logFile, json_encode($data) . PHP_EOL, FILE_APPEND);
    
    writeApiLog("Связь сохранена");
}