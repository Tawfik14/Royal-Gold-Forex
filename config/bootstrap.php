// config/bootstrap.php
// ... (contenu existant)

date_default_timezone_set($_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'Europe/Paris');

