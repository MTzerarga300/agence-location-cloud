<?php
/** Espace administrateur : indicateurs, locations, flotte, clients et équipe. */
declare(strict_types=1);

$admin = require_staff('admin');
$adminId = (int) $admin['id'];
$page = 'dashboard_admin';

if (is_post()) {
    $action = post('action');

    if ($action === 'add_staff') {
        $data = [
            'first_name' => post('first_name'),
            'last_name'  => post('last_name'),
            'email'      => post('email'),
            'phone'      => post('phone'),
            'role'       => post('role'),
            'agency'     => post('agency_name'),
            'address'    => post('address'),
        ];
        $password = (string) ($_POST['password'] ?? '');
        $isAgent = $data['role'] === 'agent';

        $error = match (true) {
            $data['first_name'] === '' || $data['last_name'] === '' => 'Indiquez le prénom et le nom.',
            !filter_var($data['email'], FILTER_VALIDATE_EMAIL)      => "L'adresse e-mail n'est pas valide.",
            !in_array($data['role'], ['agent', 'mechanic'], true)   => 'Choisissez le rôle : agence ou mécanicien.',
            $isAgent && ($data['agency'] === '' || $data['address'] === '') => "Une agence a besoin d'un nom et d'une adresse.",
            strlen($password) < 6                                   => 'Le mot de passe doit contenir au moins 6 caractères.',
            (bool) q_value('SELECT COUNT(*) FROM staff WHERE email = ?', [$data['email']]) => 'Cet e-mail est déjà utilisé par un membre de l\'équipe.',
            default => null,
        };

        if ($error) {
            flash('error', $error);
        } else {
            q('INSERT INTO staff (first_name, last_name, email, phone, role, password_hash, agency_name, address)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$data['first_name'], $data['last_name'], $data['email'], $data['phone'] ?: null, $data['role'],
                 password_hash($password, PASSWORD_DEFAULT),
                 $isAgent ? $data['agency'] : null, $isAgent ? $data['address'] : null]);
            flash('success', "{$data['first_name']} {$data['last_name']} a rejoint l'équipe (" . role_label($data['role']) . ').');
        }
        redirect($page, [], 'equipe');
    }

    if ($action === 'toggle_staff') {
        $staffId = post_int('staff_id');
        $member = q_one('SELECT first_name, last_name, role, status FROM staff WHERE staff_id = ?', [$staffId]);
        if ($member === null || $member['role'] === 'admin' || $staffId === $adminId) {
            flash('error', 'Ce compte ne peut pas être bloqué.');
        } else {
            $next = $member['status'] === 'active' ? 'blocked' : 'active';
            q('UPDATE staff SET status = ? WHERE staff_id = ?', [$next, $staffId]);
            flash('success', "{$member['first_name']} {$member['last_name']} : compte " . ($next === 'blocked' ? 'bloqué.' : 'réactivé.'));
        }
        redirect($page, [], 'equipe');
    }
}

// ---------- Indicateurs ----------

$paidRevenue = (float) q_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'paid'");
$maintenanceTotal = (float) q_value('SELECT COALESCE(SUM(cost), 0) FROM maintenance');
$profitRentals = $paidRevenue * ADMIN_COMMISSION;
$profitMaintenance = $maintenanceTotal * ADMIN_COMMISSION;

$counts = q_one(
    "SELECT
        (SELECT COUNT(*) FROM clients) AS clients,
        (SELECT COUNT(*) FROM cars WHERE status <> 'hors-service') AS cars,
        (SELECT COUNT(*) FROM rentals) AS rentals,
        (SELECT COUNT(*) FROM rentals WHERE status = 'ongoing') AS active,
        (SELECT COUNT(*) FROM staff WHERE role = 'agent') AS agencies"
);

$agencies = q_all(
    "SELECT s.staff_id, s.agency_name, s.address, s.status, s.total_debits,
            (SELECT COUNT(*) FROM cars c WHERE c.agent_id = s.staff_id AND c.status <> 'hors-service') AS cars,
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN rentals r ON r.rental_id = p.rental_id
             WHERE r.staff_id = s.staff_id AND p.status = 'paid') AS revenue
     FROM staff s WHERE s.role = 'agent' ORDER BY revenue DESC"
);
$bestRevenue = max(1.0, ...array_map(fn ($a) => (float) $a['revenue'], $agencies ?: [['revenue' => 0]]));

$rentals = q_all(
    "SELECT r.*, c.first_name, c.last_name, ca.brand, ca.model, ca.license_plate,
            s.agency_name, p.status AS payment_status, p.method AS payment_method
     FROM rentals r
     JOIN clients c ON c.client_id = r.client_id
     JOIN cars ca ON ca.car_id = r.car_id
     LEFT JOIN staff s ON s.staff_id = r.staff_id
     LEFT JOIN payments p ON p.rental_id = r.rental_id
     ORDER BY r.created_at DESC, r.rental_id DESC LIMIT 50"
);

$cars = q_all(
    "SELECT c.*, s.agency_name,
            EXISTS (SELECT 1 FROM rentals r WHERE r.car_id = c.car_id AND r.status = 'ongoing'
                    AND r.start_date <= CURDATE() AND r.end_date >= CURDATE()) AS reserved_today
     FROM cars c LEFT JOIN staff s ON s.staff_id = c.agent_id
     ORDER BY (c.status = 'hors-service'), s.agency_name, c.brand"
);

$clients = q_all(
    "SELECT c.*, COUNT(r.rental_id) AS rentals FROM clients c
     LEFT JOIN rentals r ON r.client_id = c.client_id
     GROUP BY c.client_id, c.first_name, c.last_name, c.email, c.phone, c.password_hash,
              c.driver_license, c.address, c.registration_date
     ORDER BY c.registration_date DESC"
);

$team = q_all("SELECT * FROM staff ORDER BY FIELD(role, 'admin', 'agent', 'mechanic'), last_name, first_name");

dashboard_start(
    'Vue d\'ensemble',
    "{$counts['active']} location" . ($counts['active'] > 1 ? 's' : '') . " en cours, {$counts['cars']} voitures en service dans {$counts['agencies']} agences.",
    'Administration',
    ['synthese' => "Vue d'ensemble", 'locations' => 'Locations', 'voitures' => 'Voitures', 'clients' => 'Clients', 'equipe' => 'Équipe'],
    ['name' => $admin['first_name'] . ' ' . $admin['last_name'], 'detail' => role_label('admin'), 'logout' => 'logout']
);
?>
<div class="stats" id="synthese">
    <?= stat_tile('Votre commission', money($profitRentals + $profitMaintenance), (int) (ADMIN_COMMISSION * 100) . ' % des locations payées et des maintenances', 'brass') ?>
    <?= stat_tile('Encaissé par les agences', money($paidRevenue), 'paiements confirmés') ?>
    <?= stat_tile('Locations en cours', (string) $counts['active'], $counts['rentals'] . ' au total') ?>
    <?= stat_tile('Clients', (string) $counts['clients']) ?>
    <?= stat_tile('Voitures en service', (string) $counts['cars']) ?>
</div>

<section class="panel">
    <header class="panel__head">
        <h2>Agences</h2>
        <p>Chiffre d'affaires encaissé, flotte et frais de maintenance débités à chaque agence.</p>
    </header>
    <div class="agency-bars">
        <?php foreach ($agencies as $agency): ?>
            <div class="agency-bar">
                <div class="agency-bar__label">
                    <strong><?= e($agency['agency_name'] ?: 'Agence sans nom') ?></strong>
                    <small><?= e($agency['address'] ?? '') ?> · <?= (int) $agency['cars'] ?> voitures<?= $agency['status'] === 'blocked' ? ' · bloquée' : '' ?></small>
                </div>
                <div class="agency-bar__track" aria-hidden="true"><span style="width: <?= round((float) $agency['revenue'] / $bestRevenue * 100, 1) ?>%"></span></div>
                <div class="agency-bar__values">
                    <span class="price"><?= money($agency['revenue']) ?></span>
                    <small>maintenance : <?= money($agency['total_debits']) ?></small>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$agencies): ?><p class="empty">Aucune agence. Ajoutez-en une dans la section Équipe.</p><?php endif; ?>
    </div>
    <dl class="split">
        <div><dt>Commission sur les locations</dt><dd><?= money($profitRentals) ?></dd></div>
        <div><dt>Commission sur les maintenances</dt><dd><?= money($profitMaintenance) ?></dd></div>
    </dl>
</section>

<section class="panel" id="locations">
    <header class="panel__head">
        <h2>Locations</h2>
        <p>Les 50 dernières. Chaque agence confirme et clôture ses propres locations.</p>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>N°</th><th>Client</th><th>Voiture</th><th>Agence</th><th>Dates</th><th class="num">Total</th><th>Statut</th><th>Paiement</th></tr></thead>
            <tbody>
            <?php foreach ($rentals as $r): ?>
                <tr>
                    <td class="muted">#<?= (int) $r['rental_id'] ?></td>
                    <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong></td>
                    <td><?= e($r['brand'] . ' ' . $r['model']) ?><br><?= plate($r['license_plate']) ?></td>
                    <td><?= e($r['agency_name'] ?? '—') ?></td>
                    <td><?= fdate($r['start_date']) ?><br><small class="muted">au <?= fdate($r['end_date']) ?></small></td>
                    <td class="num"><?= money($r['total_price']) ?></td>
                    <td><?= rental_status_badge($r['status']) ?></td>
                    <td><?= payment_badge($r['status'], $r['payment_status']) ?><br><small class="muted"><?= e(payment_method_label($r['payment_method'])) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rentals) echo empty_row(8, 'Aucune location pour le moment.'); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="voitures">
    <header class="panel__head panel__head--action">
        <div><h2>Voitures</h2><p>Toutes les voitures, y compris celles retirées de la flotte.</p></div>
        <label class="search"><?= icon('search') ?><input type="search" placeholder="Rechercher une voiture" data-table-filter="table-cars" aria-label="Rechercher une voiture"></label>
    </header>
    <div class="table-wrap">
        <table class="table" id="table-cars">
            <thead><tr><th>Voiture</th><th>Immatriculation</th><th>Agence</th><th>Année</th><th class="num">Kilométrage</th><th class="num">Prix/jour</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($cars as $car): ?>
                <tr>
                    <td><div class="cell-car"><img src="<?= e(car_image($car['image_url'])) ?>" alt="" loading="lazy" data-fallback><strong><?= e($car['brand'] . ' ' . $car['model']) ?></strong></div></td>
                    <td><?= plate($car['license_plate']) ?></td>
                    <td><?= e($car['agency_name'] ?? '—') ?></td>
                    <td><?= (int) $car['year'] ?></td>
                    <td class="num"><?= km($car['mileage']) ?></td>
                    <td class="num"><?= money($car['daily_price']) ?></td>
                    <td><?= car_status_badge($car['status'], (bool) $car['reserved_today']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cars) echo empty_row(7, 'Aucune voiture enregistrée.'); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="clients">
    <header class="panel__head panel__head--action">
        <div><h2>Clients</h2><p><?= count($clients) ?> compte<?= count($clients) > 1 ? 's' : '' ?> client.</p></div>
        <label class="search"><?= icon('search') ?><input type="search" placeholder="Rechercher un client" data-table-filter="table-clients" aria-label="Rechercher un client"></label>
    </header>
    <div class="table-wrap">
        <table class="table" id="table-clients">
            <thead><tr><th>Client</th><th>E-mail</th><th>Téléphone</th><th>Permis</th><th class="num">Locations</th><th>Inscription</th></tr></thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
                <tr>
                    <td><strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?></strong><br><small class="muted"><?= e($c['address'] ?? '') ?></small></td>
                    <td><?= e($c['email']) ?></td>
                    <td><?= e($c['phone'] ?? '—') ?></td>
                    <td><?= e($c['driver_license']) ?></td>
                    <td class="num"><?= (int) $c['rentals'] ?></td>
                    <td><?= fdate($c['registration_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$clients) echo empty_row(6, 'Aucun client inscrit.'); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="equipe">
    <header class="panel__head panel__head--action">
        <div><h2>Équipe</h2><p>Un compte bloqué ne peut plus se connecter ; il est déconnecté immédiatement.</p></div>
        <button class="btn btn--brass" type="button" data-open="dialog-staff"><?= icon('plus') ?>Ajouter un membre</button>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Nom</th><th>Rôle</th><th>Agence</th><th>Contact</th><th>Arrivée</th><th>Statut</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($team as $member): $blocked = $member['status'] === 'blocked'; ?>
                <tr>
                    <td><strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong></td>
                    <td><?= e(role_label($member['role'])) ?></td>
                    <td><?= e($member['agency_name'] ?? '—') ?><br><small class="muted"><?= e($member['address'] ?? '') ?></small></td>
                    <td><?= e($member['email']) ?><br><small class="muted"><?= e($member['phone'] ?? '') ?></small></td>
                    <td><?= fdate($member['hire_date']) ?></td>
                    <td><?= $blocked ? badge('bad', 'Bloqué') : badge('ok', 'Actif') ?></td>
                    <td class="actions">
                        <?php if ($member['role'] !== 'admin'): ?>
                        <form method="post" action="<?= e(url($page)) ?>" data-confirm="<?= $blocked ? 'Réactiver' : 'Bloquer' ?> le compte de <?= e($member['first_name'] . ' ' . $member['last_name']) ?> ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_staff">
                            <input type="hidden" name="staff_id" value="<?= (int) $member['staff_id'] ?>">
                            <button class="btn btn--small <?= $blocked ? 'btn--line' : 'btn--danger' ?>" type="submit"><?= $blocked ? 'Réactiver' : 'Bloquer' ?></button>
                        </form>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<dialog class="dialog" id="dialog-staff">
    <form class="form" method="post" action="<?= e(url($page)) ?>" data-role-form>
        <header class="dialog__head">
            <h2>Ajouter un membre</h2>
            <button class="icon-btn" type="button" data-close aria-label="Fermer"><?= icon('close') ?></button>
        </header>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_staff">
        <fieldset class="choice choice--inline">
            <legend>Rôle</legend>
            <label class="choice__item"><input type="radio" name="role" value="agent" checked><span><strong>Agence</strong><small>Gère sa flotte et ses réservations</small></span></label>
            <label class="choice__item"><input type="radio" name="role" value="mechanic"><span><strong>Mécanicien</strong><small>Enregistre les interventions</small></span></label>
        </fieldset>
        <div class="form__row">
            <label class="field"><span>Prénom</span><input name="first_name" required></label>
            <label class="field"><span>Nom</span><input name="last_name" required></label>
        </div>
        <div class="form__row">
            <label class="field"><span>E-mail</span><input type="email" name="email" required></label>
            <label class="field"><span>Téléphone</span><input type="tel" name="phone"></label>
        </div>
        <div data-agent-only>
            <div class="form__row">
                <label class="field"><span>Nom de l'agence</span><input name="agency_name" placeholder="Agence Bir El Djir"></label>
                <label class="field"><span>Adresse</span><input name="address" placeholder="Bir El Djir, Oran"></label>
            </div>
        </div>
        <label class="field"><span>Mot de passe provisoire</span><input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
        <div class="form__actions">
            <button class="btn btn--ghost" type="button" data-close>Annuler</button>
            <button class="btn btn--brass" type="submit">Ajouter le membre</button>
        </div>
    </form>
</dialog>
<?php
dashboard_end();
