<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Ошибка бронирования</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
        }

        .error {
            color: #c62828;
            font-size: 24px;
            margin-bottom: 20px;
        }

        .message {
            font-size: 18px;
            margin-bottom: 30px;
            color: #555;
        }

        .button {
            background: #2e7d32;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
        }

        .button-back {
            background: #757575;
            margin-left: 10px;
        }
    </style>
</head>

<body>
    <div class="error">❌ Ошибка при создании бронирования</div>
    <div class="message" id="error-message">
        <?php
        $error = $_REQUEST['error'] ?? $_SESSION['booking_error'] ?? 'Произошла непредвиденная ошибка. Пожалуйста, попробуйте позже или свяжитесь с администратором.';
        echo htmlspecialchars($error);
        unset($_SESSION['booking_error']);
        ?>
    </div>
    <div>
        <a href="javascript:history.back()" class="button button-back">← Вернуться назад</a>
        <a href="/" class="button">На главную</a>
    </div>
    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const errorParam = urlParams.get('error');
        if (errorParam) {
            document.getElementById('error-message').innerHTML = decodeURIComponent(errorParam);
        }
    </script>
</body>

</html>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog.php'; ?>