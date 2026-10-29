<?php
/** Connexion et création de compte des clients. */
declare(strict_types=1);

$carId = (int) ($_POST['car'] ?? $_GET['car'] ?? 0);
$afterLogin = $carId > 0 ? ['car' => $carId] : [];

if (current_client()) {
    redirect('dashboard_client', $afterLogin, 'reserver');
}

$mode = (($_GET['mode'] ?? '') === 'register' || post('action') === 'register') ? 'register' : 'login';
$errors = [];
$old = [];

function client_session(array $client): void
{
    session_regenerate_id(true);
    $_SESSION['client'] = [
        'id'         => (int) $client['client_id'],
        'first_name' => $client['first_name'],
        'last_name'  => $client['last_name'],
        'email'      => $client['email'],
    ];
}

if (is_post() && post('action') === 'login') {
    $old['email'] = post('email');
    $client = q_one('SELECT * FROM clients WHERE email = ?', [$old['email']]);
    if ($client === null || !password_verify((string) ($_POST['password'] ?? ''), $client['password_hash'])) {
        $errors[] = 'E-mail ou mot de passe incorrect.';
    } else {
        client_session($client);
        redirect('dashboard_client', $afterLogin, $carId > 0 ? 'reserver' : '');
    }
}

if (is_post() && post('action') === 'register') {
    foreach (['first_name', 'last_name', 'email', 'phone', 'driver_license', 'address'] as $field) {
        $old[$field] = post($field);
    }
    $password = (string) ($_POST['password'] ?? '');

    if ($old['first_name'] === '' || $old['last_name'] === '') $errors[] = 'Indiquez votre prénom et votre nom.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "L'adresse e-mail n'est pas valide.";
    if (!preg_match('/^[0-9 +]{9,15}$/', $old['phone'])) $errors[] = 'Le téléphone doit contenir entre 9 et 15 chiffres.';
    if (mb_strlen($old['driver_license']) < 5) $errors[] = 'Indiquez votre numéro de permis de conduire.';
    if (strlen($password) < 6) $errors[] = 'Le mot de passe doit contenir au moins 6 caractères.';
    if ($password !== (string) ($_POST['password_confirm'] ?? '')) $errors[] = 'Les deux mots de passe ne correspondent pas.';

    if (!$errors && q_value('SELECT COUNT(*) FROM clients WHERE email = ?', [$old['email']])) {
        $errors[] = 'Un compte existe déjà avec cet e-mail. Connectez-vous.';
    }
    if (!$errors && q_value('SELECT COUNT(*) FROM clients WHERE driver_license = ?', [$old['driver_license']])) {
        $errors[] = 'Ce numéro de permis est déjà associé à un compte.';
    }

    if (!$errors) {
        q('INSERT INTO clients (first_name, last_name, email, phone, password_hash, driver_license, address)
           VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$old['first_name'], $old['last_name'], $old['email'], $old['phone'],
             password_hash($password, PASSWORD_DEFAULT), $old['driver_license'], $old['address'] ?: null]);
        client_session(q_one('SELECT * FROM clients WHERE client_id = ?', [db()->insert_id]));
        flash('success', 'Compte créé. Vous pouvez réserver votre première voiture.');
        redirect('dashboard_client', $afterLogin, 'reserver');
    }
}

$photo = q_value("SELECT image_url FROM cars WHERE status <> 'hors-service' ORDER BY daily_price DESC LIMIT 1");

render_head($mode === 'register' ? 'Créer un compte' : 'Connexion client', 'page-auth');
?>
<main class="auth">
    <section class="auth__side auth__side--photo">
        <img class="auth__photo" src="<?= e(car_image(is_string($photo) ? $photo : null)) ?>" alt="" data-fallback>
        <?= brand_mark() ?>
        <div class="auth__pitch">
            <h1>Votre espace client</h1>
            <p>Réservez une voiture, suivez la confirmation de l'agence et retrouvez l'historique de vos locations.</p>
        </div>
    </section>

    <section class="auth__panel">
        <div class="auth__form">
            <div class="tabs" role="tablist">
                <a role="tab" class="tabs__item<?= $mode === 'login' ? ' is-active' : '' ?>" aria-selected="<?= $mode === 'login' ? 'true' : 'false' ?>" href="<?= e(url('login_client', $afterLogin)) ?>">Se connecter</a>
                <a role="tab" class="tabs__item<?= $mode === 'register' ? ' is-active' : '' ?>" aria-selected="<?= $mode === 'register' ? 'true' : 'false' ?>" href="<?= e(url('login_client', $afterLogin + ['mode' => 'register'])) ?>">Créer un compte</a>
            </div>

            <?php flash_messages(); ?>
            <?php foreach ($errors as $error): ?>
                <div class="notice notice--bad" role="alert"><?= e($error) ?></div>
            <?php endforeach; ?>

            <?php if ($mode === 'login'): ?>
            <form class="form" method="post" action="<?= e(url('login_client')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="car" value="<?= $carId ?>">
                <label class="field"><span>Adresse e-mail</span>
                    <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required autofocus autocomplete="username"></label>
                <label class="field"><span>Mot de passe</span>
                    <input type="password" name="password" required autocomplete="current-password"></label>
                <button class="btn btn--brass btn--block" type="submit">Se connecter</button>
                <details class="demo">
                    <summary>Compte de démonstration</summary>
                    <dl><div><dt>Client</dt><dd>client@mohamedtayeb.com · client123</dd></div></dl>
                </details>
            </form>
            <?php else: ?>
            <form class="form" method="post" action="<?= e(url('login_client', ['mode' => 'register'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <input type="hidden" name="car" value="<?= $carId ?>">
                <div class="form__row">
                    <label class="field"><span>Prénom</span>
                        <input name="first_name" value="<?= e($old['first_name'] ?? '') ?>" required autocomplete="given-name"></label>
                    <label class="field"><span>Nom</span>
                        <input name="last_name" value="<?= e($old['last_name'] ?? '') ?>" required autocomplete="family-name"></label>
                </div>
                <label class="field"><span>Adresse e-mail</span>
                    <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required autocomplete="email"></label>
                <div class="form__row">
                    <label class="field"><span>Téléphone</span>
                        <input type="tel" name="phone" value="<?= e($old['phone'] ?? '') ?>" required placeholder="0550 12 34 56" autocomplete="tel"></label>
                    <label class="field"><span>Numéro de permis</span>
                        <input name="driver_license" value="<?= e($old['driver_license'] ?? '') ?>" required></label>
                </div>
                <label class="field"><span>Adresse <em>facultatif</em></span>
                    <input name="address" value="<?= e($old['address'] ?? '') ?>" autocomplete="street-address"></label>
                <div class="form__row">
                    <label class="field"><span>Mot de passe</span>
                        <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
                    <label class="field"><span>Confirmer</span>
                        <input type="password" name="password_confirm" required minlength="6" autocomplete="new-password"></label>
                </div>
                <button class="btn btn--brass btn--block" type="submit">Créer mon compte</button>
            </form>
            <?php endif; ?>
            <a class="link-quiet" href="<?= e(url('accueil')) ?>">Retour au site</a>
        </div>
    </section>
</main>
<?php
render_foot();
