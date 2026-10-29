<?php
/** Connexion du personnel : administrateur, agences et mécanicien. */
declare(strict_types=1);

if ($staff = current_staff()) {
    redirect(dashboard_page($staff['role']));
}

$error = '';
$email = '';

if (is_post()) {
    $email = post('email');
    $password = (string) ($_POST['password'] ?? '');
    $account = q_one('SELECT * FROM staff WHERE email = ?', [$email]);

    if ($account === null || !password_verify($password, $account['password_hash'])) {
        $error = 'E-mail ou mot de passe incorrect.';
    } elseif ($account['status'] !== 'active') {
        $error = "Ce compte est bloqué. Contactez l'administrateur.";
    } else {
        session_regenerate_id(true);
        $_SESSION['staff'] = [
            'id'         => (int) $account['staff_id'],
            'first_name' => $account['first_name'],
            'last_name'  => $account['last_name'],
            'email'      => $account['email'],
            'role'       => $account['role'],
            'agency'     => $account['agency_name'],
        ];
        redirect(dashboard_page($account['role']));
    }
}

render_head('Connexion du personnel', 'page-auth');
?>
<main class="auth">
    <section class="auth__side">
        <?= brand_mark() ?>
        <div class="auth__pitch">
            <h1>Espace du personnel</h1>
            <p>Agences, atelier et administration : chaque compte ouvre l'espace de son rôle.</p>
        </div>
        <span class="plate plate--xl" aria-hidden="true">STAFF-ORAN-31</span>
    </section>

    <section class="auth__panel">
        <form class="form auth__form" method="post" action="<?= e(url('login')) ?>">
            <h2>Se connecter</h2>
            <?php flash_messages(); ?>
            <?php if ($error): ?><div class="notice notice--bad" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?= csrf_field() ?>
            <label class="field"><span>Adresse e-mail</span>
                <input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username"></label>
            <label class="field"><span>Mot de passe</span>
                <input type="password" name="password" required autocomplete="current-password"></label>
            <button class="btn btn--brass btn--block" type="submit">Se connecter</button>

            <details class="demo">
                <summary>Comptes de démonstration</summary>
                <dl>
                    <div><dt>Administrateur</dt><dd>admin@mohamedtayeb.com · admin123</dd></div>
                    <div><dt>Agence 1</dt><dd>agent1@mohamedtayeb.com · agent123</dd></div>
                    <div><dt>Agence 2</dt><dd>agent2@mohamedtayeb.com · agent456</dd></div>
                    <div><dt>Mécanicien</dt><dd>mechanic@mohamedtayeb.com · mech123</dd></div>
                </dl>
            </details>
            <a class="link-quiet" href="<?= e(url('accueil')) ?>">Retour au site</a>
        </form>
    </section>
</main>
<?php
render_foot();
