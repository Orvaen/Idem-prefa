<?php

class MyPDO extends PDO
{
    public function __construct($file = 'my_setting.ini')
    {
        $settings = parse_ini_file($file, true);

        if ($settings === false) {
            throw new Exception('Unable to open ' . $file . '.');
        }

        $database = $settings['database'];

        $database['host'] = getenv('MYSQL_HOST') ?: $database['host'];
        $database['port'] = getenv('MYSQL_PORT') ?: $database['port'];
        $database['schema'] = getenv('MYSQL_DATABASE') ?: $database['schema'];
        $database['username'] = getenv('MYSQL_USER') ?: $database['username'];
        $database['password'] = getenv('MYSQL_PASSWORD') ?: $database['password'];

        $dsn = $database['driver']
            . ':host=' . $database['host']
            . ';port=' . $database['port']
            . ';dbname=' . $database['schema']
            . ';charset=utf8mb4';

        parent::__construct(
            $dsn,
            $database['username'],
            $database['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
}
