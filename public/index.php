<?php
/**
 * Point d'entrée unique : charge la configuration, ouvre la session,
 * vérifie la protection CSRF, puis affiche la page demandée (?page=...).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/config.php';
require ROOT_DIR . '/src/helpers.php';
require ROOT_DIR . '/src/database.php';
require ROOT_DIR . '/src/layout.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$routes = [
    'accueil'            => 'home.php',
    'login'              => 'staff-login.php',
    'login_client'       => 'client-login.php',
    'dashboard_admin'    => 'admin.php',
    'dashboard_agent'    => 'agent.php',
    'dashboard_mechanic' => 'mechanic.php',
    'dashboard_client'   => 'client.php',
];

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'accueil';

// Sonde de santé, utile pour Docker et Kubernetes
if ($page === 'health') {
    header('Content-Type: application/json');
    try {
        db()->query('SELECT 1');
        echo json_encode(['status' => 'ok']);
    } catch (Throwable) {
        http_response_code(503);
        echo json_encode(['status' => 'database_unavailable']);
    }
    exit;
}

if ($page === 'logout') {
    unset($_SESSION['staff']);
    session_regenerate_id(true);
    flash('success', 'Vous êtes déconnecté.');
    redirect('login');
}
if ($page === 'logout_client') {
    unset($_SESSION['client']);
    session_regenerate_id(true);
    redirect('accueil');
}

if (!isset($routes[$page])) {
    $page = 'accueil';
}

if (is_post() && !csrf_valid()) {
    flash('error', 'La page a expiré. Renvoyez le formulaire.');
    redirect($page);
}

try {
    db();
} catch (mysqli_sql_exception $exception) {
    http_response_code(503);
    render_head('Service indisponible', 'page-error');
    ?>
    <main class="error-page">
        <h1>La base de données ne répond pas</h1>
        <p>Vérifiez que MySQL est démarré, puis rechargez la page. Paramètres utilisés : hôte <code><?= e(DB_HOST) ?></code>, utilisateur <code><?= e(DB_USER) ?></code>.</p>
    </main>
    <?php
    render_foot();
    error_log('Connexion MySQL impossible : ' . $exception->getMessage());
    exit;
}

require ROOT_DIR . '/pages/' . $routes[$page];
