<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

if (!CModule::IncludeModule('avs_booking')) {
    ShowError('Модуль avs_booking не установлен');
    return;
}

$elementId = intval($arParams['ELEMENT_ID']);
if (!$elementId) {
    ShowError('Не указан ID беседки');
    return;
}

$arResult['GAZEBO_DATA'] = AVSBookingModule::getGazeboData($elementId);
$arResult['ELEMENT_ID'] = $elementId;

$this->IncludeComponentTemplate();
