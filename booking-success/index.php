<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Бронирование создано</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
        }

        .success {
            color: green;
            font-size: 24px;
            margin-bottom: 20px;
        }

        .message {
            font-size: 18px;
            margin-bottom: 30px;
        }

        .button {
            background: #2e7d32;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>
</head>

<body>
    <div class="success">✅ Бронирование успешно создано!</div>
    <div class="message">Подтверждение отправлено на вашу электронную почту.</div>
    <a href="/" class="button">Вернуться на главную</a>
</body>

</html>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog.php'; ?>