<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//Очистка ввода от XSS
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}
//Перенаправление с сообщением
function redirect_with_message($url, $message, $type = 'success') {
    $_SESSION['message'] = [
        'text' => $message,
        'type' => $type
    ];
    header("Location: $url");
    exit;
}
//Вывод сообщения из сессии
function display_message() {
    if (isset($_SESSION['message']) && is_array($_SESSION['message'])) { // Проверяем, что это массив
        $message = $_SESSION['message'];
        // Проверка на наличие и пустоту текста
        if (!empty($message['text'])) {
             $type = htmlspecialchars($message['type'] ?? 'info');
             $text = htmlspecialchars($message['text']);
             echo '<div class="alert alert-' . $type . '">' . $text . '</div>';
        }
        unset($_SESSION['message']);
    }
}
//Проверка доступа по роли
function check_role($allowed_roles = ['student', 'teacher', 'admin']) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
        if (!defined('BASE_URL')) { define('BASE_URL', '/'); }
        redirect_with_message(BASE_URL . 'pages/login.php', 'У вас нет доступа к этой странице', 'error');
    }
}
function getUserRoleLabel(?string $role): string
{
    switch ($role) {
        case 'student':
            return 'Студент';
        case 'teacher':
            return 'Преподаватель';
        case 'admin':
            return 'Администратор';
        default:
            return 'Неизвестно';
    }
}
function truncate_text(string $text, int $maxLength): string {
    if (mb_strlen($text, 'UTF-8') > $maxLength) { 
        return mb_substr($text, 0, $maxLength, 'UTF-8') . '...';
    }
    return $text;
}
// Вспомогательная функция для поиска группы в массиве
function find_group_by_id(array $groups, int $id): ?array {
    foreach ($groups as $group) {
        if ($group['id'] === $id) {
            return $group;
        }
    }
    return null;
}
// Функция для имени в чате
function getShortName(?string $fullName): string
{
    if (empty($fullName)) {
        return 'Пользователь'; // Или другое значение по умолчанию
    }
    $fullName = trim($fullName);
    $parts = explode(' ', $fullName, 3); // Разбить по пробелу, максимум 3 части

    if (count($parts) >= 2) {
        // Берем первые две части (предполагаем Имя Фамилия или Фамилия Имя)
        return htmlspecialchars($parts[0] . ' ' . $parts[1]);
    } elseif (count($parts) === 1) {
         // Если только одно слово, возвращаем его
         return htmlspecialchars($parts[0]);
    } else {
        // На всякий случай возвращаем исходное, если что-то пошло не так
        return htmlspecialchars($fullName);
    }
}
// Форматирует строку даты/времени из БД в формат для datetime-local input
function format_datetime_local(?string $datetime_str): string {
    if (empty($datetime_str)) {
        return '';
    }
    try {
        $date = new DateTime($datetime_str);
        return $date->format('Y-m-d\TH:i');
    } catch (Exception $e) {
        error_log("Error formatting datetime for input: " . $e->getMessage());
        return '';
    }
}
// Возвращает текстовое описание ошибки загрузки файла PHP
function get_upload_error_message(int $error_code): string {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return "Размер файла превышает лимит upload_max_filesize в php.ini.";
        case UPLOAD_ERR_FORM_SIZE:
            return "Размер файла превышает лимит MAX_FILE_SIZE в HTML-форме.";
        case UPLOAD_ERR_PARTIAL:
            return "Файл был загружен только частично.";
        case UPLOAD_ERR_NO_FILE:
            return "Файл не был загружен.";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Отсутствует временная директория для загрузки.";
        case UPLOAD_ERR_CANT_WRITE:
            return "Не удалось записать файл на диск.";
        case UPLOAD_ERR_EXTENSION:
            return "PHP-расширение остановило загрузку файла.";
        default:
            return "Неизвестная ошибка загрузки файла.";
    }
}
// Форматирует дату и время в формат (дд.мм.гггг ЧЧ:ММ)
function format_ru_datetime(?string $datetime_str): string {
    if (empty($datetime_str)) {
        return '';
    }
    try {
        $date_obj = new DateTime($datetime_str);
        return $date_obj->format('d.m.Y H:i');
    } catch (Exception $e) {
        error_log("Error formatting RU datetime: " . $e->getMessage() . " for value: " . $datetime_str);
        return '';
    }
}

// тоже форматирует дату
function format_ru_datetime_short(?string $datetime_str): string {
    if (empty($datetime_str)) {
       return '';
   }
   try {
       $date_obj = new DateTime($datetime_str);
       return $date_obj->format('d.m H:i');
   } catch (Exception $e) {
       error_log("Error formatting RU short datetime: " . $e->getMessage() . " for value: " . $datetime_str);
       return '';
   }
}

// Обрабатывает загрузку файла
function handleFileUpload(array $fileInfo, string $targetDir, string $prefix = '', array $allowedExtensions = [], int $maxFileSize = 0): string|false
{
    try {
        if (!isset($fileInfo['error']) || is_array($fileInfo['error'])) {
            throw new RuntimeException('Некорректные параметры загрузки файла.');
        }
        switch ($fileInfo['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException('Файл не был загружен.'); 
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException('Превышен допустимый размер файла.');
            default:
                throw new RuntimeException('Неизвестная ошибка загрузки файла (код: ' . $fileInfo['error'] . ').');
        }

        // Проверка существования и доступности директории для записи
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
             if (!is_dir($targetDir)) {
                 if (!mkdir($targetDir, 0775, true)) {
                      throw new RuntimeException('Не удалось создать директорию для загрузки: ' . htmlspecialchars($targetDir));
                 }
             } else { 
                  throw new RuntimeException('Директория для загрузки недоступна для записи: ' . htmlspecialchars($targetDir));
             }
        }

        // Проверка размера файла
        if ($maxFileSize > 0 && $fileInfo['size'] > $maxFileSize) {
            throw new RuntimeException('Размер файла (' . round($fileInfo['size'] / 1024 / 1024, 2) . ' MB) превышает максимальный лимит (' . round($maxFileSize / 1024 / 1024, 2) . ' MB).');
        }

        // Проверка расширения файла
        $fileExtension = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        if (!empty($allowedExtensions) && !in_array($fileExtension, $allowedExtensions)) {
            throw new RuntimeException('Недопустимый тип файла. Разрешенные типы: ' . implode(', ', $allowedExtensions));
        }

        // Генерация уникального имени файла
        $newFileName = $prefix . uniqid('', true) . '.' . $fileExtension;
        $targetFilePath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFileName;

        // Перемещение файла из временной директории
        if (!move_uploaded_file($fileInfo['tmp_name'], $targetFilePath)) {
            throw new RuntimeException('Не удалось переместить загруженный файл.');
        }

        // Установка прав на файл 
        chmod($targetFilePath, 0664);
        return $newFileName;

    } catch (RuntimeException $e) {
        error_log("File Upload Error: " . $e->getMessage());
        return false;
    }
}

// Проверяет, является ли указанное имя файла текущей страницей
function is_active_page(string|array $page_filenames): string
{
    $current_page = basename($_SERVER['PHP_SELF']);

    if (is_string($page_filenames)) {
        // Простое сравнение строки
        if ($current_page === $page_filenames) {
            return 'active';
        }
    } elseif (is_array($page_filenames)) {
        // Проверка по массиву
        if (in_array($current_page, $page_filenames, true)) {
            return 'active';
        }
    }

    if ($page_filenames === 'dashboard.php' && $current_page === 'dashboard.php') return 'active';
    if ($page_filenames === 'student_dashboard.php' && $current_page === 'student_dashboard.php') return 'active';

    return '';
}
// функция для формата времени
function format_hours(float $hours): string {
    return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
}
// функция для отображения статуса заявки на консультацию
function get_consultation_request_status_text(string $status_key): string {
    $statuses = [
        'pending_teacher_response' => 'Ожидает ответа преподавателя',
        'teacher_responded_pending_student_confirmation' => 'Ожидает вашего подтверждения',
        'scheduled_confirmed' => 'Консультация назначена',
        'completed' => 'Завершена',
        'cancelled_by_student_before_confirmation' => 'Отменена вами (до ответа)',
        'cancelled_by_student_after_confirmation' => 'Отменена вами (после назначения)',
        'cancelled_by_teacher' => 'Отменена преподавателем',
        'student_rejected_offer' => 'Вы отклонили предложение'
    ];
    return $statuses[$status_key] ?? ucfirst(str_replace('_', ' ', $status_key));
}
// для цветового кодирования статусов 
function get_consultation_request_status_badge_class(string $status_key): string {
    switch ($status_key) {
        case 'pending_teacher_response':
        case 'teacher_responded_pending_student_confirmation':
            return 'warning text-dark'; 
        case 'scheduled_confirmed':
            return 'info text-dark';   
        case 'completed':
            return 'success';         
        case 'cancelled_by_student_before_confirmation':
        case 'cancelled_by_student_after_confirmation':
        case 'cancelled_by_teacher':
        case 'student_rejected_offer':
            return 'danger';           
        default:
            return 'secondary';         
    }
}

// для форматирования только времени
function format_ru_time(?string $datetime_str): string {
    if (empty($datetime_str)) {
        return '-';
    }
    try {
        $date = new DateTime($datetime_str);
        return $date->format('H:i');
    } catch (Exception $e) {
        return '-'; // Ошибка форматирования
    }
}

// ЕЩЁ ОДНА функция на дату/время
function format_ru_date(?string $datetime_str): string {
    if (empty($datetime_str)) {
        return '-'; 
    }
    try {
        $date_obj = new DateTime($datetime_str);
        if ($date_obj->format('Y') < 1900) { // Проверка на невалидную дату
            return '-';
        }
        return $date_obj->format('d.m.Y'); // Формат только даты
    } catch (Exception $e) {
        error_log("Error formatting RU date: " . $e->getMessage() . " for value: " . $datetime_str);
        return '-'; 
    }
}

if (!function_exists('get_file_icon_class')) {
    function get_file_icon_class(?string $file_path): string {
        if (empty($file_path)) {
            return 'fa-file text-body-secondary'; 
        }
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        switch ($extension) {
            case 'pdf':
                return 'fa-file-pdf text-danger';
            case 'doc':
            case 'docx':
                return 'fa-file-word text-primary';
            case 'ppt':
            case 'pptx':
                return 'fa-file-powerpoint text-warning';
            case 'xls':
            case 'xlsx':
                return 'fa-file-excel text-success';
            case 'zip':
            case 'rar':
            case '7z':
                return 'fa-file-archive text-secondary'; 
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'bmp':
            case 'svg':
            case 'webp':
                return 'fa-file-image text-info';
            case 'txt':
            case 'md':
                return 'fa-file-alt text-muted'; 
            case 'mp3':
            case 'wav':
            case 'ogg':
                return 'fa-file-audio text-purple'; 
            case 'mp4':
            case 'mov':
            case 'avi':
            case 'mkv':
                return 'fa-file-video text-orange';
            case 'js':
            case 'css':
            case 'html':
            case 'php':
            case 'py':
                return 'fa-file-code text-indigo'; 
            default:
                return 'fa-file text-body-secondary';
        }
    }
}
// Вспомогательная функция для поиска подстроки в массиве ошибок
if (!function_exists('in_array_str_contains')) {
    function in_array_str_contains(string $needle, array $haystack): bool {
        foreach ($haystack as $item) {
            if (is_string($item) && stripos($item, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

// для колокольчика
if (!function_exists('get_notification_icon')) {
    function get_notification_icon(string $type = 'default'): string {
        switch ($type) {
            case 'new_message': return 'fa-comment-dots';
            case 'assignment_graded': return 'fa-graduation-cap';
            case 'new_material': return 'fa-folder-plus';
            default: return 'fa-info-circle';
        }
    }
}
if (!function_exists('normalize_internal_url')) {
    function normalize_internal_url(string $url): string {
        if (empty($url)) {
            return '#'; 
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (defined('BASE_URL')) {
            $baseUrl = rtrim(BASE_URL, '/'); 

            if (strpos($url, '/') === 0) { 
                if (strpos($url, $baseUrl) === 0 && strlen($url) > strlen($baseUrl)) {
                }
                return $baseUrl . $url; 
            } else {
                return $url; 
            }
        }
        return $url;
    }
}

if (!function_exists('get_lesson_type_text_short')) {
    function get_lesson_type_text_short(string $type_key): string {
        $types = [
            'lecture'    => 'Лек.',
            'practice'   => 'Прак.',
            'assessment' => 'Атт.',
            'other'      => 'Проч.' 
        ];
        return $types[$type_key] ?? ucfirst(substr($type_key, 0, 4)).'.';
    }
}

if (!function_exists('getWeekDates')) {
    function getWeekDates(string $date_string = 'now'): array {
        try {
            $date = new DateTime($date_string);
        } catch (Exception $e) {
            error_log("getWeekDates: Invalid date string '{$date_string}', defaulting to 'now'. Error: " . $e->getMessage());
            try {
                $date = new DateTime('now');
            } catch (Exception $e_now) { 
                throw new Exception("Failed to create DateTime object even líderes 'now': " . $e_now->getMessage());
            }
        }

        $day_of_week = (int)$date->format('N'); // 1 (для понедельника) до 7 (для воскресенья)

        $start_of_week = clone $date;
        if ($day_of_week > 1) {
            $start_of_week->modify('-' . ($day_of_week - 1) . ' days');
        } 

        $end_of_week = clone $start_of_week;
        $end_of_week->modify('+6 days');

        return [
            'start' => $start_of_week->format('Y-m-d'),
            'end' => $end_of_week->format('Y-m-d 23:59:59'),
            'display_start' => $start_of_week,
            'display_end' => $end_of_week 
        ];
    }
}

if (!function_exists('format_ru_weekday')) {
    function format_ru_weekday($day_number_1_to_7, bool $short = false): string {
        $day_number = (int)$day_number_1_to_7;
        $days_full = [1 => 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'];
        $days_short = [1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

        if ($short) {
            return $days_short[$day_number] ?? 'Н/Д';
        }
        return $days_full[$day_number] ?? 'Неизвестный день';
    }
}
// функции для csrf токенов для создания пользователей админом
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                // Обработка ошибки генерации случайных байт, если random_bytes недоступен
                $_SESSION['csrf_token'] = md5(uniqid((string)mt_rand(), true));
                error_log('CSRF token generation failed with random_bytes, used fallback: ' . $e->getMessage());
            }
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token(string $submitted_token): bool {
        if (isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted_token)) {
            return true;
        }
        return false;
    }
}
?>