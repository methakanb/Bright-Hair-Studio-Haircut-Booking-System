<?php
// Load local .env without third-party dependencies; existing server variables take precedence.
if (!function_exists('bright_env')) {
    function bright_env(string $key, ?string $default = null): ?string {
        static $loaded = false;
        if (!$loaded) {
            $loaded = true;
            $file = __DIR__ . '/.env';
            if (is_readable($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                    [$name, $value] = explode('=', $line, 2);
                    $name = trim($name);
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) continue;
                    $value = trim($value);
                    if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) $value = substr($value, 1, -1);
                    if (getenv($name) === false) putenv($name . '=' . $value);
                }
            }
        }
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $name) {
    if (!defined($name)) define($name, bright_env($name, ''));
}
