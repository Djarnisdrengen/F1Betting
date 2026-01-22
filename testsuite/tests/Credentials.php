<?php

final class Credentials
{
    public static function promptFromPost(): array
    {
        $csrf = $_POST['csrf'] ?? '';
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($csrf === '' || $username === '' || $password === '') {
            throw new RuntimeException('All fields are required.');
        }

        return [$csrf, $username, $password];
    }
}
