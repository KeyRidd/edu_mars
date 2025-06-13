<?php
// Устанавливает соединение с базой данных PostgreSQL с использованием PDO
function getDbConnection(): ?PDO 
{
    $host = 'localhost';
    $db = 'teacher_dashboard';
    $user = 'postgres';
    $password = '1234567';
    $port = 5432;

    // DSN для PostgreSQL
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";

    // Опции PDO
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Выбрасывать исключения при ошибках
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Возвращать ассоциативные массивы по умолчанию
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Использовать настоящие подготовленные запросы
    ];

    try {
        // Создаем новый объект PDO
        $pdo = new PDO($dsn, $user, $password, $options);
        
        // Возвращаем успешное соединение
        return $pdo;

    } catch (PDOException $e) {
        error_log("Database Connection Error: " . $e->getMessage());
        die("Ошибка подключения к базе данных. Пожалуйста, попробуйте позже.");
  
    }
}
?>