<?php
function pluralize(int $number, string $form1, string $form2, string $form5): string {
    $number = abs($number) % 100; // Работаем с последними двумя цифрами
    $lastDigit = $number % 10;

    if ($number > 10 && $number < 20) { // для 11-19
        return $form5;
    }
    if ($lastDigit > 1 && $lastDigit < 5) { // для 2-4
        return $form2;
    }
    if ($lastDigit == 1) { // для 1
        return $form1;
    }
    return $form5; // для 0, 5-9
}
?>