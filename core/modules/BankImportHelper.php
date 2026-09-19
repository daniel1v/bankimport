<?php

class BankImportHelper
{
    private static $envLoaded = false;

    private static $env = array();

    public static function loadEnv()
    {
        if (self::$envLoaded) return;

        $envFile = __DIR__ . '/../../.env';
        if (is_readable($envFile)) {
            $values = parse_ini_file($envFile, false, INI_SCANNER_RAW);
            if (is_array($values)) self::$env = $values;
        }
        self::$envLoaded = true;
    }

    public static function getEnv($key, $default = null)
    {
        self::loadEnv();
        if (array_key_exists($key, self::$env)) return self::$env[$key];
        if (array_key_exists($key, $_ENV)) return $_ENV[$key];

        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
