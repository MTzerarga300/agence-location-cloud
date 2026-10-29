<?php
/**
 * Gabarits HTML partagés : en-tête de page, navigation publique,
 * coque des tableaux de bord, badges et composants.
 */
declare(strict_types=1);

function asset(string $path): string
{
    $file = PUBLIC_DIR . '/assets/' . $path;
    $version = is_file($file) ? filemtime($file) : 1;
    return 'assets/' . $path . '?v=' . $version;
}

function render_head(string $title, string $bodyClass = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <meta name="description" content="Location de voitures à Oran : citadines, berlines et SUV de prestige, réservables en ligne.">
    <link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('app.js') ?>" defer></script>
</head>
<body class="<?= e($bodyClass) ?>">
<?php
}

function render_foot(): void
{
    echo "\n</body>\n</html>\n";
}

// ---------- Composants ----------

function icon(string $name): string
{
    $paths = [
        'logout'   => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'search'   => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
        'pin'      => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1.5 1.5 0 0 1-1.6 1.5A16 16 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4z"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
        'gauge'    => '<path d="M4 16a8 8 0 1 1 16 0"/><path d="M12 16l4-4"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17"/><path d="M8 3v4M16 3v4"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
        'image'    => '<rect x="3.5" y="5" width="17" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M20.5 16l-5-5L6 19"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
        . ($paths[$name] ?? '') . '</svg>';
}

function plate(?string $number): string
{
    return '<span class="plate" title="Immatriculation">' . e($number) . '</span>';
}

function badge(string $tone, string $label): string
{
    return '<span class="badge badge--' . e($tone) . '">' . e($label) . '</span>';
}

function car_status_badge(string $status, bool $reservedToday = false): string
{
    if ($status === 'available' && $reservedToday) {
        return badge('info', "Réservée aujourd'hui");
    }
    return match ($status) {
        'available'    => badge('ok', 'Disponible'),
        'rented'       => badge('info', 'Louée'),
        'maintenance'  => badge('warn', 'En révision'),
        'hors-service' => badge('muted', 'Retirée de la flotte'),
        default        => badge('muted', $status),
    };
}

function rental_status_badge(string $status): string
{
    return match ($status) {
        'ongoing'   => badge('info', 'En cours'),
        'completed' => badge('ok', 'Terminée'),
        'cancelled' => badge('bad', 'Annulée'),
        default     => badge('muted', $status),
    };
}

function payment_method_label(?string $method): string
{
    return match ($method) {
        'ccp'           => 'CCP',
        'cash'          => 'Espèces',
        'credit_card'   => 'Carte bancaire',
        'debit_card'    => 'Carte de débit',
        'bank_transfer' => 'Virement',
        default         => '—',
    };
}

function payment_badge(string $rentalStatus, ?string $paymentStatus): string
{
    if ($paymentStatus === 'refunded') {
        return badge('muted', 'Remboursé');
    }
    if ($rentalStatus === 'cancelled') {
        return badge('muted', 'Sans paiement');
    }
    return $paymentStatus === 'paid' ? badge('ok', 'Payé') : badge('warn', 'En attente');
}

function role_label(string $role): string
{
    return match ($role) {
        'admin'    => 'Administrateur',
        'agent'    => 'Agence',
        'mechanic' => 'Mécanicien',
        default    => $role,
    };
}

function flash_messages(): void
{
    foreach (pull_flashes() as $flash) {
        $tone = $flash['type'] === 'success' ? 'ok' : 'bad';
        echo '<div class="notice notice--' . $tone . '" role="status">' . e($flash['message']) . '</div>';
    }
}

function empty_row(int $columns, string $message): string
{
    return '<tr class="table__empty"><td colspan="' . $columns . '">' . e($message) . '</td></tr>';
}

function brand_mark(): string
{
    return '<a class="brand" href="' . e(url('accueil')) . '">'
        . '<span class="brand__mark" aria-hidden="true">MT</span>'
        . '<span class="brand__name">Mohamed Tayeb<small>Agency · Oran</small></span>'
        . '</a>';
}

// ---------- Pages publiques ----------

function public_header(): void
{
    $client = current_client();
    ?>
<header class="site-header">
    <div class="wrap site-header__inner">
        <?= brand_mark() ?>
        <nav class="site-nav" aria-label="Navigation principale">
            <a href="<?= e(url('accueil')) ?>#flotte">La flotte</a>
            <a href="<?= e(url('accueil')) ?>#reserver">Réserver</a>
            <a href="<?= e(url('accueil')) ?>#contact">Contact</a>
        </nav>
        <div class="site-header__actions">
            <a class="link-quiet" href="<?= e(url('login')) ?>">Espace staff</a>
            <?php if ($client): ?>
                <a class="btn btn--brass" href="<?= e(url('dashboard_client')) ?>">Mon espace</a>
            <?php else: ?>
                <a class="btn btn--brass" href="<?= e(url('login_client')) ?>">Espace client</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php
}

function public_footer(): void
{
    ?>
<footer class="site-footer" id="contact">
    <div class="wrap site-footer__grid">
        <div>
            <?= brand_mark() ?>
            <p class="site-footer__text">Location de voitures à Oran, de la citadine au SUV de prestige. Réservation en ligne, paiement en espèces ou par CCP.</p>
        </div>
        <div>
            <h2 class="site-footer__title">Nous joindre</h2>
            <ul class="contact-list">
                <li><?= icon('phone') ?><a href="tel:+213657140089">+213 657 14 00 89</a></li>
                <li><?= icon('mail') ?><a href="mailto:mohamed.zerarga@ensttic.dz">mohamed.zerarga@ensttic.dz</a></li>
                <li><?= icon('pin') ?><span>ENSTTIC, Oran, Algérie</span></li>
            </ul>
        </div>
        <div>
            <h2 class="site-footer__title">Suivre l'agence</h2>
            <ul class="contact-list">
                <li><a href="https://www.facebook.com/tayeb.mohamed.397/" target="_blank" rel="noopener">Facebook</a></li>
                <li><a href="https://www.instagram.com/mohamed.tayeb.zerarga/" target="_blank" rel="noopener">Instagram</a></li>
                <li><a href="https://www.linkedin.com/in/mohamed-tayeb-zerarga/" target="_blank" rel="noopener">LinkedIn</a></li>
            </ul>
        </div>
    </div>
    <div class="wrap site-footer__legal">© <?= date('Y') ?> Mohamed Tayeb Agency. Projet de démonstration.</div>
</footer>
<?php
}

// ---------- Tableaux de bord ----------

/**
 * @param array $nav  liste [ancre => libellé]
 * @param array $user ['name' => ..., 'detail' => ..., 'logout' => page]
 */
function dashboard_start(string $title, string $subtitle, string $space, array $nav, array $user): void
{
    render_head($title, 'page-dash');
    ?>
<div class="dash">
    <aside class="dash-side">
        <div class="dash-side__top">
            <?= brand_mark() ?>
            <span class="dash-side__space"><?= e($space) ?></span>
        </div>
        <nav class="dash-nav" aria-label="Sections">
            <?php foreach ($nav as $anchor => $label): ?>
                <a href="#<?= e($anchor) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="dash-user">
            <span class="dash-user__avatar" aria-hidden="true"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
            <span class="dash-user__text"><strong><?= e($user['name']) ?></strong><small><?= e($user['detail']) ?></small></span>
            <a class="dash-user__logout" href="<?= e(url($user['logout'])) ?>" title="Se déconnecter" aria-label="Se déconnecter"><?= icon('logout') ?></a>
        </div>
    </aside>
    <main class="dash-main">
        <header class="dash-head">
            <h1><?= e($title) ?></h1>
            <p><?= e($subtitle) ?></p>
        </header>
        <div class="notices"><?php flash_messages(); ?></div>
<?php
}

function dashboard_end(): void
{
    echo "    </main>\n</div>\n";
    render_foot();
}

function stat_tile(string $label, string $value, string $hint = '', string $tone = ''): string
{
    return '<div class="stat' . ($tone ? ' stat--' . e($tone) : '') . '">'
        . '<span class="stat__label">' . e($label) . '</span>'
        . '<span class="stat__value">' . e($value) . '</span>'
        . ($hint !== '' ? '<span class="stat__hint">' . e($hint) . '</span>' : '')
        . '</div>';
}

function password_section(string $page): void
{
    ?>
<section class="panel" id="mot-de-passe">
    <header class="panel__head">
        <h2>Mot de passe</h2>
        <p>Choisissez un mot de passe d'au moins 6 caractères.</p>
    </header>
    <form class="form form--narrow" method="post" action="<?= e(url($page)) ?>#mot-de-passe">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <label class="field"><span>Mot de passe actuel</span>
            <input type="password" name="current_password" required autocomplete="current-password"></label>
        <label class="field"><span>Nouveau mot de passe</span>
            <input type="password" name="new_password" required minlength="6" autocomplete="new-password"></label>
        <label class="field"><span>Confirmer le nouveau mot de passe</span>
            <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password"></label>
        <div class="form__actions"><button class="btn btn--brass" type="submit">Changer le mot de passe</button></div>
    </form>
</section>
<?php
}
