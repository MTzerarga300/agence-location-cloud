<?php
/**
 * Configuration de l'application.
 *
 * Chaque valeur peut être fournie par une variable d'environnement
 * (Docker, Kubernetes). Sans variable, la valeur par défaut convient à XAMPP.
 */
declare(strict_types=1);

function env(string $key, string $default): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

define('APP_NAME', 'Mohamed Tayeb Agency');

// Base de données
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env('DB_PORT', '3306'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASSWORD', env('DB_PASSWORD', ''));
define('DB_NAME', env('DB_NAME', 'mohamed_tayeb_agency_premium'));

// Fichiers
define('ROOT_DIR', dirname(__DIR__));
define('PUBLIC_DIR', ROOT_DIR . '/public');
define('UPLOAD_DIR', PUBLIC_DIR . '/uploads/cars');
define('UPLOAD_URL', 'uploads/cars');
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);

// Règles métier
define('ADMIN_COMMISSION', 0.03);   // part de l'admin sur les locations payées et les maintenances
define('MECHANIC_SHARE', 0.22);     // part du mécanicien sur le coût des maintenances

date_default_timezone_set('Africa/Algiers');
