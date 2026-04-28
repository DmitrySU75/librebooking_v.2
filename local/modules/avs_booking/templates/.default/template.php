<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

$elementId = (int)$arParams['ELEMENT_ID'];

if (!$elementId) {
    echo '<p style="color:red;">Ошибка: не передан ID беседки</p>';
    return;
}

if (!CModule::IncludeModule('avs_booking')) {
    echo '<p style="color:red;">Модуль avs_booking не установлен</p>';
    return;
}

$gazebo = AVSBookingModule::getGazeboData($elementId);

if (!$gazebo || !$gazebo['resource_id']) {
    echo '<p style="color:orange;">Бронирование для этой беседки временно недоступно</p>';
    return;
}

$minHours = $gazebo['min_hours'] > 0 ? $gazebo['min_hours'] : 4;
?>

<div class="avs-booking-module" data-element-id="<?= $elementId ?>" data-min-hours="<?= $minHours ?>">
    <h3>Забронировать <?= htmlspecialchars($gazebo['name']) ?></h3>

    <div class="booking-loader" style="display:none; text-align:center; padding:10px;">
        ⏳ Проверка доступности...
    </div>

    <div class="booking-unavailable" style="display:none; background:#ffebee; color:#c62828; padding:10px; border-radius:6px; margin-bottom:15px;">
        ⚠️ Эта дата полностью занята. Выберите другую.
    </div>

    <div class="form-group">
        <label for="booking-date">Дата бронирования:</label>
        <input type="date" id="booking-date" class="form-control" min="<?= date('Y-m-d') ?>">
    </div>

    <div class="booking-form-container" style="display:none;">
        <div class="form-group">
            <label>Тип аренды:</label>
            <div id="rental_type_radios" class="rental-type-radios"></div>
        </div>

        <div class="hourly-fields" style="display:none;">
            <div class="form-group">
                <label for="start_hour">Время начала:</label>
                <select id="start_hour" name="start_hour"></select>
            </div>
            <div class="form-group">
                <label for="hours">Количество часов:</label>
                <select id="hours" name="hours"></select>
            </div>
            <div id="hourly_warning" class="warning-message" style="display:none; color:#e67e22; font-size:14px; margin-top:5px;">
                ⚠️ Для выбранного времени минимальная длительность бронирования (<?= $minHours ?> часа(ов)) недоступна
            </div>
        </div>

        <div class="form-group">
            <label>Итого: <strong class="total-price">0</strong> ₽</label>
        </div>

        <div class="form-group">
            <label for="name">Ваше имя:</label>
            <input type="text" id="name" name="name" required class="form-control">
        </div>

        <div class="form-group">
            <label for="phone">Телефон:</label>
            <input type="tel" id="phone" name="phone" required class="form-control" placeholder="+7 (___) ___-__-__">
        </div>

        <div class="form-group">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" required class="form-control">
        </div>

        <div class="form-group">
            <label for="comment">Комментарий:</label>
            <textarea id="comment" name="comment" rows="2" class="form-control"></textarea>
        </div>

        <button type="button" class="booking-submit-btn">Перейти к оплате</button>
    </div>

    <div class="booking-result" style="margin-top:15px;"></div>
</div>

<style>
    .avs-booking-module {
        background: #f5f5f5;
        padding: 20px;
        border-radius: 12px;
        margin: 20px 0;
    }

    .avs-booking-module .form-group {
        margin-bottom: 15px;
    }

    .avs-booking-module label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
    }

    .avs-booking-module .form-control {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        box-sizing: border-box;
    }

    .avs-booking-module select.form-control {
        width: auto;
    }

    .avs-booking-module .booking-submit-btn {
        background: #2e7d32;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 16px;
        width: 100%;
    }

    .avs-booking-module .booking-submit-btn:hover {
        background: #1b5e20;
    }

    .avs-booking-module .rental-type-radios {
        margin-top: 5px;
    }

    .avs-booking-module .rental-radio {
        width: auto !important;
        margin-right: 5px;
        display: inline-block !important;
    }

    .avs-booking-module .radio-option {
        display: flex;
        align-items: center;
        margin-bottom: 8px;
    }

    .avs-booking-module .radio-option label {
        margin-bottom: 0;
        font-weight: normal;
        cursor: pointer;
    }

    .warning-message {
        padding: 8px;
        background: #fff3e0;
        border-radius: 4px;
        margin-top: 5px;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.querySelector('.avs-booking-module');
        if (!container) return;

        const elementId = parseInt(container.dataset.elementId);
        const minHours = parseInt(container.dataset.minHours) || 4;

        let currentWorkEndHour = 22;

        const loader = container.querySelector('.booking-loader');
        const unavailable = container.querySelector('.booking-unavailable');
        const formContainer = container.querySelector('.booking-form-container');
        const dateInput = document.getElementById('booking-date');
        const radiosContainer = document.getElementById('rental_type_radios');
        const hourlyFields = document.querySelector('.hourly-fields');
        const startHourSelect = document.getElementById('start_hour');
        const hoursSelect = document.getElementById('hours');
        const totalSpan = document.querySelector('.total-price');
        const submitBtn = document.querySelector('.booking-submit-btn');
        const resultDiv = document.querySelector('.booking-result');
        const nameInput = document.getElementById('name');
        const phoneInput = document.getElementById('phone');
        const emailInput = document.getElementById('email');
        const commentInput = document.getElementById('comment');
        const warningDiv = document.getElementById('hourly_warning');

        let currentPrices = {};
        let currentAvailableSlots = [];
        let currentRentalType = null;

        // Функции для сортировки и фильтрации
        function sortHoursByHour(slots) {
            return slots.sort((a, b) => a.hour - b.hour);
        }

        function filterAvailableStartHours(slots) {
            const maxStartHour = currentWorkEndHour - minHours;
            return slots.filter(slot => slot.hour <= maxStartHour);
        }

        // Обновление доступных часов начала
        function updateAvailableHours() {
            if (!startHourSelect) return;

            if (currentAvailableSlots.length === 0) {
                startHourSelect.innerHTML = '<option value="">Нет доступных часов</option>';
                if (hoursSelect) {
                    hoursSelect.innerHTML = '<option value="">Недоступно</option>';
                    hoursSelect.disabled = true;
                }
                return;
            }

            const sortedSlots = sortHoursByHour([...currentAvailableSlots]);
            const filteredSlots = filterAvailableStartHours(sortedSlots);

            startHourSelect.innerHTML = '';

            if (filteredSlots.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Нет доступных часов (требуется минимум ' + minHours + ' ч.)';
                option.disabled = true;
                startHourSelect.appendChild(option);
                if (hoursSelect) {
                    hoursSelect.innerHTML = '<option value="">Недоступно</option>';
                    hoursSelect.disabled = true;
                }
                return;
            }

            for (const slot of filteredSlots) {
                const option = document.createElement('option');
                option.value = slot.hour;
                option.textContent = slot.label;
                startHourSelect.appendChild(option);
            }

            if (filteredSlots.length > 0) {
                startHourSelect.value = filteredSlots[0].hour;
                updateMaxHours();
            }
        }

        // Обновление максимального количества часов
        function updateMaxHours() {
            if (!startHourSelect || !hoursSelect || currentRentalType !== 'hourly') return;

            const selectedHour = parseInt(startHourSelect.value);
            if (isNaN(selectedHour)) return;

            const maxHours = currentWorkEndHour - selectedHour;

            if (warningDiv) {
                if (maxHours < minHours) {
                    warningDiv.style.display = 'block';
                    hoursSelect.innerHTML = '<option value="">Недоступно</option>';
                    hoursSelect.disabled = true;
                    updateTotal();
                    return;
                } else {
                    warningDiv.style.display = 'none';
                    hoursSelect.disabled = false;
                }
            }

            hoursSelect.innerHTML = '';
            for (let h = minHours; h <= maxHours; h++) {
                const option = document.createElement('option');
                option.value = h;
                option.textContent = h + ' ' + declensionHours(h);
                hoursSelect.appendChild(option);
            }

            hoursSelect.value = minHours;
            updateTotal();
        }

        // Склонение часов
        function declensionHours(hours) {
            const lastDigit = hours % 10;
            const lastTwoDigits = hours % 100;

            if (lastTwoDigits >= 11 && lastTwoDigits <= 14) {
                return 'часов';
            }
            if (lastDigit === 1) {
                return 'час';
            }
            if (lastDigit >= 2 && lastDigit <= 4) {
                return 'часа';
            }
            return 'часов';
        }

        // Обновление итоговой цены
        function updateTotal() {
            const selectedRadio = document.querySelector('input[name="rental_type"]:checked');
            const type = selectedRadio ? selectedRadio.value : '';
            let total = 0;

            if (type === 'hourly' && hoursSelect && hoursSelect.value && hoursSelect.value !== '') {
                total = (currentPrices[type] || 0) * parseInt(hoursSelect.value);
            } else if (currentPrices[type]) {
                total = currentPrices[type];
            }

            if (totalSpan) totalSpan.textContent = total.toLocaleString();
        }

        // Обработка смены типа аренды
        function handleRadioChange() {
            const selectedRadio = document.querySelector('input[name="rental_type"]:checked');
            const isHourly = selectedRadio && selectedRadio.value === 'hourly';
            currentRentalType = isHourly ? 'hourly' : (selectedRadio ? selectedRadio.value : null);

            if (hourlyFields) {
                hourlyFields.style.display = isHourly ? 'block' : 'none';
            }

            if (isHourly && currentAvailableSlots.length > 0) {
                updateAvailableHours();
            }

            updateTotal();
        }

        // Обработчик выбора даты
        if (dateInput) {
            dateInput.addEventListener('change', function() {
                const date = this.value;
                if (!date) return;

                if (loader) loader.style.display = 'block';
                if (formContainer) formContainer.style.display = 'none';
                if (unavailable) unavailable.style.display = 'none';

                const formData = new FormData();
                formData.append('action', 'get_available_slots');
                formData.append('element_id', elementId);
                formData.append('date', date);

                fetch('/local/modules/avs_booking/ajax.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (loader) loader.style.display = 'none';

                        if (!data.success) {
                            alert(data.message);
                            return;
                        }

                        if (data.work_end_hour) {
                            currentWorkEndHour = data.work_end_hour;
                        }

                        if (data.has_conflicts || !data.rental_types || Object.keys(data.rental_types).length === 0) {
                            if (unavailable) unavailable.style.display = 'block';
                            return;
                        }

                        if (formContainer) formContainer.style.display = 'block';

                        if (data.slots && data.slots.hourly && data.slots.hourly.length > 0) {
                            currentAvailableSlots = data.slots.hourly;
                        } else {
                            currentAvailableSlots = [];
                        }

                        radiosContainer.innerHTML = '';
                        currentPrices = {};

                        let firstRadio = null;
                        for (const [type, info] of Object.entries(data.rental_types)) {
                            const radioId = `rental_type_${type}`;
                            const radioDiv = document.createElement('div');
                            radioDiv.className = 'radio-option';

                            const radio = document.createElement('input');
                            radio.type = 'radio';
                            radio.name = 'rental_type';
                            radio.value = type;
                            radio.id = radioId;
                            radio.className = 'rental-radio';
                            radio.dataset.price = info.price;

                            const label = document.createElement('label');
                            label.htmlFor = radioId;

                            const endHour = data.work_end_hour || 22;

                            if (type === 'full_day') {
                                label.textContent = 'Весь день (10:00-' + endHour + ':00) — ' + info.price.toLocaleString() + ' ₽';
                            } else if (type === 'night') {
                                label.textContent = 'Ночь (00:00-09:00) — ' + info.price.toLocaleString() + ' ₽';
                            } else {
                                label.textContent = info.label + ' — ' + info.price.toLocaleString() + ' ₽';
                            }

                            label.style.marginLeft = '8px';
                            label.style.fontWeight = 'normal';
                            label.style.cursor = 'pointer';

                            radioDiv.appendChild(radio);
                            radioDiv.appendChild(label);
                            radiosContainer.appendChild(radioDiv);

                            currentPrices[type] = info.price;

                            if (!firstRadio) firstRadio = radio;
                        }

                        document.querySelectorAll('input[name="rental_type"]').forEach(radio => {
                            radio.removeEventListener('change', handleRadioChange);
                            radio.addEventListener('change', handleRadioChange);
                        });

                        if (hoursSelect) {
                            hoursSelect.removeEventListener('change', updateTotal);
                            hoursSelect.addEventListener('change', updateTotal);
                        }

                        if (startHourSelect) {
                            startHourSelect.removeEventListener('change', updateMaxHours);
                            startHourSelect.addEventListener('change', updateMaxHours);
                        }

                        if (currentAvailableSlots.length > 0) {
                            updateAvailableHours();
                        }

                        if (data.rental_types.hourly && hourlyFields) {
                            hourlyFields.style.display = 'block';
                        } else if (hourlyFields) {
                            hourlyFields.style.display = 'none';
                        }

                        if (firstRadio) {
                            firstRadio.checked = true;
                            handleRadioChange();
                        }
                    })
                    .catch(error => {
                        if (loader) loader.style.display = 'none';
                        console.error('Error:', error);
                        alert('Ошибка при проверке доступности');
                    });
            });
        }

        // Обработчики изменения полей
        if (hoursSelect) {
            hoursSelect.addEventListener('change', updateTotal);
        }

        if (startHourSelect) {
            startHourSelect.addEventListener('change', updateMaxHours);
        }

        // Обработчик отправки формы
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                const date = dateInput ? dateInput.value : '';
                const selectedRadio = document.querySelector('input[name="rental_type"]:checked');
                const rentalType = selectedRadio ? selectedRadio.value : '';
                const name = nameInput ? nameInput.value.trim() : '';
                let phoneRaw = phoneInput ? phoneInput.value : '';
                const email = emailInput ? emailInput.value.trim() : '';
                const comment = commentInput ? commentInput.value.trim() : '';
                const startHour = startHourSelect ? startHourSelect.value : 10;
                const hours = hoursSelect && hoursSelect.value ? hoursSelect.value : minHours;

                // Очистка телефона от нецифровых символов
                const phoneDigits = phoneRaw.replace(/\D/g, '');
                let phoneFormatted = '';
                if (phoneDigits.length === 11 && phoneDigits[0] === '7') {
                    phoneFormatted = '+' + phoneDigits;
                } else if (phoneDigits.length === 10) {
                    phoneFormatted = '+7' + phoneDigits;
                } else {
                    phoneFormatted = phoneRaw;
                }

                // Валидация
                if (!date) {
                    alert('Выберите дату');
                    return;
                }

                if (!rentalType) {
                    alert('Выберите тип аренды');
                    return;
                }

                if (!name || !phoneDigits || !email) {
                    alert('Заполните имя, телефон и email');
                    return;
                }

                if (phoneDigits.length !== 10 && !(phoneDigits.length === 11 && phoneDigits[0] === '7')) {
                    alert('Введите корректный номер телефона (10 цифр после +7)');
                    return;
                }

                if (rentalType === 'hourly') {
                    if (!startHourSelect || !startHourSelect.value) {
                        alert('Выберите время начала');
                        return;
                    }
                    if (!hoursSelect || !hoursSelect.value) {
                        alert('Выберите количество часов');
                        return;
                    }

                    const selectedHour = parseInt(startHour);
                    const selectedHours = parseInt(hours);
                    const maxAllowedHour = currentWorkEndHour - selectedHours;
                    if (selectedHour > maxAllowedHour) {
                        alert('При выбранном времени начала нельзя забронировать ' + selectedHours + ' ' + declensionHours(selectedHours) + '. Пожалуйста, выберите другое время или уменьшите количество часов.');
                        return;
                    }
                }

                if (resultDiv) resultDiv.innerHTML = '⏳ Перенаправление на оплату...';
                if (submitBtn) submitBtn.disabled = true;

                const formData = new FormData();
                formData.append('action', 'create_payment');
                formData.append('element_id', elementId);
                formData.append('date', date);
                formData.append('rental_type', rentalType);
                formData.append('name', name);
                formData.append('phone', phoneFormatted);
                formData.append('email', email);
                formData.append('comment', comment);
                formData.append('start_hour', startHour);
                formData.append('hours', hours);

                fetch('/local/modules/avs_booking/ajax.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = data.confirmation_url;
                        } else {
                            if (resultDiv) resultDiv.innerHTML = '<div style="color:red;">❌ ' + data.message + '</div>';
                            if (submitBtn) submitBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        if (resultDiv) resultDiv.innerHTML = '<div style="color:red;">❌ Ошибка соединения</div>';
                        if (submitBtn) submitBtn.disabled = false;
                    });
            });
        }
    });
</script>