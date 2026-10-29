<?php
/**
 * Fonctions utilitaires : échappement, formats, navigation, messages,
 * protection CSRF, authentification et envoi d'images.
 */
declare(strict_types=1);

// ---------- Échappement et formats ----------

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money(mixed $amount): string
{
    return number_format((float) $amount, 0, ',', ' ') . ' DA';
}

function km(mixed $value): string
{
    return number_format((int) $value, 0, ',', ' ') . ' km';
}

function fdate(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '—';
}

function days_between(string $start, string $end): int
{
    return (int) round((strtotime($end) - strtotime($start)) / 86400);
}

// ---------- Navigation ----------

function url(string $page, array $params = []): string
{
    return '?' . http_build_query(['page' => $page] + $params);
}

function redirect(string $page, array $params = [], string $anchor = ''): never
{
    header('Location: ' . url($page, $params) . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function post_int(string $key): int
{
    return (int) ($_POST[$key] ?? 0);
}

function post_float(string $key): float
{
    return (float) str_replace(',', '.', (string) ($_POST[$key] ?? '0'));
}

// ---------- Messages flash ----------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// ---------- Protection CSRF ----------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    return is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']);
}

// ---------- Authentification ----------

function current_staff(): ?array
{
    return $_SESSION['staff'] ?? null;
}

function current_client(): ?array
{
    return $_SESSION['client'] ?? null;
}

function dashboard_page(string $role): string
{
    return 'dashboard_' . $role;
}

function require_staff(string $role): array
{
    $staff = current_staff();
    if ($staff === null) {
        flash('error', 'Connectez-vous pour accéder à cet espace.');
        redirect('login');
    }
    if ($staff['role'] !== $role) {
        redirect(dashboard_page($staff['role']));
    }
    // Un compte bloqué entre-temps est déconnecté immédiatement
    $status = q_value('SELECT status FROM staff WHERE staff_id = ?', [(int) $staff['id']]);
    if ($status !== 'active') {
        unset($_SESSION['staff']);
        flash('error', "Votre compte a été bloqué par l'administrateur.");
        redirect('login');
    }
    return $staff;
}

function require_client(): array
{
    $client = current_client();
    if ($client === null) {
        flash('error', 'Connectez-vous pour réserver une voiture.');
        redirect('login_client');
    }
    return $client;
}

/** Vérifie le mot de passe actuel puis enregistre le nouveau. Renvoie un message d'erreur ou null. */
function change_staff_password(int $staffId, string $current, string $new, string $confirm): ?string
{
    if (strlen($new) < 6) {
        return 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
    }
    if ($new !== $confirm) {
        return 'Les deux nouveaux mots de passe ne correspondent pas.';
    }
    $hash = q_value('SELECT password_hash FROM staff WHERE staff_id = ?', [$staffId]);
    if (!is_string($hash) || !password_verify($current, $hash)) {
        return 'Le mot de passe actuel est incorrect.';
    }
    q('UPDATE staff SET password_hash = ? WHERE staff_id = ?', [password_hash($new, PASSWORD_DEFAULT), $staffId]);
    return null;
}

// ---------- Images des voitures ----------

function car_image(?string $url): string
{
    $url = (string) $url;
    if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
        return $url;
    }
    if ($url !== '' && is_file(PUBLIC_DIR . '/' . $url)) {
        return $url;
    }
    return 'assets/car-placeholder.svg';
}

/**
 * Enregistre une photo envoyée par formulaire.
 * Renvoie le chemin relatif à stocker, null si aucun fichier, ou lève une exception.
 */
function save_car_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("La photo n'a pas pu être envoyée. Réessayez avec une image de moins de 5 Mo.");
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('La photo dépasse 5 Mo. Choisissez une image plus légère.');
    }

    $info = @getimagesize($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if ($info === false || !isset($extensions[$info['mime']])) {
        throw new RuntimeException('Format non pris en charge. Utilisez une photo JPG, PNG, WEBP ou GIF.');
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $name = 'car_' . bin2hex(random_bytes(8)) . '.' . $extensions[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException("La photo n'a pas pu être enregistrée sur le serveur.");
    }
    return UPLOAD_URL . '/' . $name;
}
