<?php
/** Espace agence : réservations, flotte, maintenance, clients et mot de passe. */
declare(strict_types=1);

$agent = require_staff('agent');
$agentId = (int) $agent['id'];
$page = 'dashboard_agent';

/** Réservation appartenant à cette agence, ou null. */
function agent_rental(int $rentalId, int $agentId): ?array
{
    return q_one(
        "SELECT r.*, p.payment_id, p.status AS payment_status FROM rentals r
         LEFT JOIN payments p ON p.rental_id = r.rental_id
         WHERE r.rental_id = ? AND r.staff_id = ?",
        [$rentalId, $agentId]
    );
}

if (is_post()) {
    $action = post('action');

    if (in_array($action, ['confirm_payment', 'complete_rental', 'cancel_rental'], true)) {
        $rental = agent_rental(post_int('rental_id'), $agentId);
        $id = post_int('rental_id');

        if ($rental === null || $rental['status'] !== 'ongoing') {
            flash('error', "Cette réservation n'est plus modifiable.");
        } elseif ($action === 'confirm_payment') {
            if ($rental['payment_id']) {
                q("UPDATE payments SET status = 'paid', payment_date = NOW() WHERE payment_id = ?", [(int) $rental['payment_id']]);
            } else {
                q("INSERT INTO payments (rental_id, amount, method, status) VALUES (?, ?, 'cash', 'paid')",
                    [$id, (float) $rental['total_price']]);
            }
            flash('success', "Paiement de la réservation n° $id confirmé.");
        } elseif ($action === 'complete_rental') {
            if ($rental['payment_status'] !== 'paid') {
                flash('error', 'Confirmez le paiement avant de clôturer la location.');
            } else {
                q("UPDATE rentals SET status = 'completed' WHERE rental_id = ?", [$id]);
                flash('success', "Location n° $id clôturée. La voiture est de nouveau libre.");
            }
        } else {
            db()->begin_transaction();
            q("UPDATE rentals SET status = 'cancelled' WHERE rental_id = ?", [$id]);
            q("UPDATE payments SET status = 'refunded' WHERE rental_id = ? AND status = 'paid'", [$id]);
            db()->commit();
            flash('success', $rental['payment_status'] === 'paid'
                ? "Réservation n° $id annulée. Le paiement est marqué comme remboursé."
                : "Réservation n° $id annulée.");
        }
        redirect($page, [], 'reservations');
    }

    if ($action === 'add_car') {
        $data = [
            'brand'  => post('brand'),
            'model'  => post('model'),
            'year'   => post_int('year'),
            'plate'  => mb_strtoupper(post('license_plate')),
            'color'  => post('color'),
            'mileage'=> max(0, post_int('mileage')),
            'price'  => post_float('daily_price'),
        ];
        $maxYear = (int) date('Y') + 1;
        $error = match (true) {
            $data['brand'] === '' || $data['model'] === '' => 'Indiquez la marque et le modèle.',
            $data['year'] < 1990 || $data['year'] > $maxYear => "L'année doit être comprise entre 1990 et $maxYear.",
            $data['plate'] === ''                           => "Indiquez l'immatriculation.",
            $data['price'] <= 0                             => 'Le prix par jour doit être supérieur à 0.',
            (bool) q_value('SELECT COUNT(*) FROM cars WHERE license_plate = ?', [$data['plate']])
                                                            => 'Cette immatriculation est déjà enregistrée.',
            default => null,
        };
        if ($error === null) {
            try {
                $image = save_car_image($_FILES['car_image'] ?? []);
                q('INSERT INTO cars (brand, model, year, license_plate, color, mileage, daily_price, image_url, agent_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$data['brand'], $data['model'], $data['year'], $data['plate'], $data['color'] ?: null,
                     $data['mileage'], $data['price'], $image, $agentId]);
                flash('success', "{$data['brand']} {$data['model']} ajoutée à votre flotte.");
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
        if ($error !== null) {
            flash('error', $error);
        }
        redirect($page, [], 'flotte');
    }

    if ($action === 'update_car') {
        $carId = post_int('car_id');
        $price = post_float('daily_price');
        $mileage = post_int('mileage');
        if ($price <= 0 || $mileage < 0) {
            flash('error', 'Le prix doit être supérieur à 0 et le kilométrage positif.');
        } else {
            $updated = q('UPDATE cars SET daily_price = ?, mileage = ? WHERE car_id = ? AND agent_id = ?',
                [$price, $mileage, $carId, $agentId])->affected_rows;
            flash($updated >= 0 ? 'success' : 'error', 'Voiture mise à jour.');
        }
        redirect($page, [], 'flotte');
    }

    if ($action === 'retire_car') {
        $carId = post_int('car_id');
        $active = (int) q_value("SELECT COUNT(*) FROM rentals WHERE car_id = ? AND status = 'ongoing'", [$carId]);
        if ($active > 0) {
            flash('error', 'Cette voiture a une réservation en cours : clôturez-la ou annulez-la avant de la retirer.');
        } else {
            q("UPDATE cars SET status = 'hors-service' WHERE car_id = ? AND agent_id = ?", [$carId, $agentId]);
            flash('success', "Voiture retirée de la flotte. Son historique est conservé.");
        }
        redirect($page, [], 'flotte');
    }

    if ($action === 'change_password') {
        $error = change_staff_password($agentId, (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''));
        flash($error ? 'error' : 'success', $error ?? 'Mot de passe modifié.');
        redirect($page, [], 'mot-de-passe');
    }
}

// ---------- Données ----------

$rentals = q_all(
    "SELECT r.*, c.first_name, c.last_name, c.email, c.phone,
            ca.brand, ca.model, ca.license_plate,
            p.status AS payment_status, p.method AS payment_method
     FROM rentals r
     JOIN clients c ON c.client_id = r.client_id
     JOIN cars ca ON ca.car_id = r.car_id
     LEFT JOIN payments p ON p.rental_id = r.rental_id
     WHERE r.staff_id = ?
     ORDER BY (r.status = 'ongoing') DESC, r.start_date DESC",
    [$agentId]
);

$cars = q_all(
    "SELECT c.*,
            EXISTS (SELECT 1 FROM rentals r WHERE r.car_id = c.car_id AND r.status = 'ongoing'
                    AND r.start_date <= CURDATE() AND r.end_date >= CURDATE()) AS reserved_today
     FROM cars c WHERE c.agent_id = ? AND c.status <> 'hors-service'
     ORDER BY c.brand, c.model",
    [$agentId]
);

$maintenance = q_all(
    "SELECT m.*, c.brand, c.model, c.license_plate FROM maintenance m
     JOIN cars c ON c.car_id = m.car_id
     WHERE c.agent_id = ?
     ORDER BY m.maintenance_date DESC, m.maintenance_id DESC",
    [$agentId]
);

$clients = q_all(
    "SELECT c.client_id, c.first_name, c.last_name, c.email, c.phone,
            COUNT(r.rental_id) AS rentals, MAX(r.start_date) AS last_rental
     FROM clients c JOIN rentals r ON r.client_id = c.client_id
     WHERE r.staff_id = ?
     GROUP BY c.client_id, c.first_name, c.last_name, c.email, c.phone
     ORDER BY last_rental DESC",
    [$agentId]
);

$revenue = (float) q_value(
    "SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN rentals r ON r.rental_id = p.rental_id
     WHERE r.staff_id = ? AND p.status = 'paid'", [$agentId]);
$maintenanceCost = array_sum(array_column($maintenance, 'cost'));
$active = count(array_filter($rentals, fn ($r) => $r['status'] === 'ongoing'));
$toConfirm = count(array_filter($rentals, fn ($r) => $r['status'] === 'ongoing' && $r['payment_status'] !== 'paid'));
$completed = count(array_filter($rentals, fn ($r) => $r['status'] === 'completed'));

dashboard_start(
    $agent['agency'] ?: 'Mon agence',
    $toConfirm > 0
        ? "$toConfirm réservation" . ($toConfirm > 1 ? 's attendent' : ' attend') . ' votre confirmation.'
        : 'Toutes vos réservations sont à jour.',
    'Espace agence',
    ['reservations' => 'Réservations', 'flotte' => 'Ma flotte', 'maintenance' => 'Maintenance', 'clients' => 'Clients', 'mot-de-passe' => 'Mot de passe'],
    ['name' => $agent['first_name'] . ' ' . $agent['last_name'], 'detail' => role_label('agent'), 'logout' => 'logout']
);
?>
<div class="stats">
    <?= stat_tile('À confirmer', (string) $toConfirm, 'paiements en attente', $toConfirm > 0 ? 'warn' : '') ?>
    <?= stat_tile('Locations en cours', (string) $active) ?>
    <?= stat_tile('Terminées', (string) $completed) ?>
    <?= stat_tile('Encaissé', money($revenue), 'paiements confirmés', 'brass') ?>
    <?= stat_tile('Maintenance', money($maintenanceCost), 'débitée à votre agence') ?>
</div>

<section class="panel" id="reservations">
    <header class="panel__head">
        <h2>Réservations</h2>
        <p>Confirmez le paiement à la remise des clés, puis clôturez la location au retour de la voiture.</p>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>N°</th><th>Client</th><th>Voiture</th><th>Dates</th><th class="num">Total</th><th>Statut</th><th>Paiement</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rentals as $r): ?>
                <tr>
                    <td class="muted">#<?= (int) $r['rental_id'] ?></td>
                    <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><br><small class="muted"><?= e($r['phone']) ?></small></td>
                    <td><?= e($r['brand'] . ' ' . $r['model']) ?><br><?= plate($r['license_plate']) ?></td>
                    <td><?= fdate($r['start_date']) ?><br><small class="muted">au <?= fdate($r['end_date']) ?></small></td>
                    <td class="num"><?= money($r['total_price']) ?></td>
                    <td><?= rental_status_badge($r['status']) ?></td>
                    <td><?= payment_badge($r['status'], $r['payment_status']) ?><br><small class="muted"><?= e(payment_method_label($r['payment_method'])) ?></small></td>
                    <td class="actions">
                        <?php if ($r['status'] === 'ongoing'): ?>
                            <form method="post" action="<?= e(url($page)) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="rental_id" value="<?= (int) $r['rental_id'] ?>">
                                <?php if ($r['payment_status'] !== 'paid'): ?>
                                    <button class="btn btn--small btn--brass" name="action" value="confirm_payment">Confirmer le paiement</button>
                                <?php else: ?>
                                    <button class="btn btn--small btn--line" name="action" value="complete_rental">Clôturer</button>
                                <?php endif; ?>
                                <button class="btn btn--small btn--danger" name="action" value="cancel_rental"
                                        data-confirm="Annuler la réservation n° <?= (int) $r['rental_id'] ?> ?">Annuler</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Aucune action</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rentals) echo empty_row(8, 'Aucune réservation pour vos voitures.'); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="flotte">
    <header class="panel__head panel__head--action">
        <div>
            <h2>Ma flotte</h2>
            <p><?= count($cars) ?> voiture<?= count($cars) > 1 ? 's' : '' ?> en service. Seules vos voitures apparaissent ici.</p>
        </div>
        <button class="btn btn--brass" type="button" data-open="dialog-add-car"><?= icon('plus') ?>Ajouter une voiture</button>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Voiture</th><th>Immatriculation</th><th>Année</th><th class="num">Kilométrage</th><th class="num">Prix/jour</th><th>Statut</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($cars as $car): ?>
                <tr>
                    <td><div class="cell-car"><img src="<?= e(car_image($car['image_url'])) ?>" alt="" loading="lazy" data-fallback><strong><?= e($car['brand'] . ' ' . $car['model']) ?></strong></div></td>
                    <td><?= plate($car['license_plate']) ?></td>
                    <td><?= (int) $car['year'] ?></td>
                    <td class="num"><?= km($car['mileage']) ?></td>
                    <td class="num"><?= money($car['daily_price']) ?></td>
                    <td><?= car_status_badge($car['status'], (bool) $car['reserved_today']) ?></td>
                    <td class="actions">
                        <button class="btn btn--small btn--line" type="button" data-open="dialog-edit-car"
                                data-fill='<?= e(json_encode(['car_id' => (int) $car['car_id'], 'daily_price' => (float) $car['daily_price'], 'mileage' => (int) $car['mileage'], 'title' => $car['brand'] . ' ' . $car['model']])) ?>'>Modifier</button>
                        <form method="post" action="<?= e(url($page)) ?>" data-confirm="Retirer <?= e($car['brand'] . ' ' . $car['model']) ?> de la flotte ? Elle ne sera plus proposée aux clients.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="retire_car">
                            <input type="hidden" name="car_id" value="<?= (int) $car['car_id'] ?>">
                            <button class="btn btn--small btn--danger" type="submit">Retirer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cars) echo empty_row(7, 'Aucune voiture : ajoutez la première avec le bouton ci-dessus.'); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="maintenance">
    <header class="panel__head">
        <h2>Maintenance</h2>
        <p>Interventions de l'atelier sur vos voitures. Total débité : <strong><?= money($maintenanceCost) ?></strong>.</p>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Voiture</th><th>Intervention</th><th>Mécanicien</th><th class="num">Coût</th></tr></thead>
            <tbody>
            <?php foreach ($maintenance as $m): ?>
                <tr>
                    <td><?= fdate($m['maintenance_date']) ?></td>
                    <td><?= e($m['brand'] . ' ' . $m['model']) ?><br><?= plate($m['license_plate']) ?></td>
                    <td class="wrap-text"><?= e($m['description']) ?></td>
                    <td><?= e($m['performed_by'] ?? '—') ?></td>
                    <td class="num"><?= money($m['cost']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$maintenance) echo empty_row(5, "Aucune intervention de l'atelier sur vos voitures."); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="clients">
    <header class="panel__head">
        <h2>Clients</h2>
        <p>Les clients qui ont déjà loué chez vous.</p>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Client</th><th>E-mail</th><th>Téléphone</th><th class="num">Locations</th><th>Dernière location</th></tr></thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
                <tr>
                    <td><strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?></strong></td>
                    <td><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></td>
                    <td><a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a></td>
                    <td class="num"><?= (int) $c['rentals'] ?></td>
                    <td><?= fdate($c['last_rental']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$clients) echo empty_row(5, "Aucun client n'a encore loué chez vous."); ?>
            </tbody>
        </table>
    </div>
</section>

<?php password_section($page); ?>

<dialog class="dialog" id="dialog-add-car">
    <form class="form" method="post" action="<?= e(url($page)) ?>" enctype="multipart/form-data">
        <header class="dialog__head">
            <h2>Ajouter une voiture</h2>
            <button class="icon-btn" type="button" data-close aria-label="Fermer"><?= icon('close') ?></button>
        </header>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_car">
        <div class="form__row">
            <label class="field"><span>Marque</span><input name="brand" required placeholder="Renault"></label>
            <label class="field"><span>Modèle</span><input name="model" required placeholder="Clio"></label>
        </div>
        <div class="form__row">
            <label class="field"><span>Année</span><input type="number" name="year" min="1990" max="<?= (int) date('Y') + 1 ?>" value="<?= date('Y') ?>" required></label>
            <label class="field"><span>Immatriculation</span><input name="license_plate" required placeholder="12345-124-31"></label>
        </div>
        <div class="form__row">
            <label class="field"><span>Couleur</span><input name="color" placeholder="Blanc"></label>
            <label class="field"><span>Kilométrage</span><input type="number" name="mileage" min="0" value="0"></label>
        </div>
        <label class="field"><span>Prix par jour (DA)</span><input type="number" name="daily_price" min="100" step="50" required></label>
        <label class="upload">
            <input type="file" name="car_image" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="car-preview">
            <img id="car-preview" alt="" hidden>
            <span class="upload__label"><?= icon('image') ?>Ajouter une photo <small>JPG, PNG ou WEBP, 5 Mo maximum</small></span>
        </label>
        <div class="form__actions">
            <button class="btn btn--ghost" type="button" data-close>Annuler</button>
            <button class="btn btn--brass" type="submit">Ajouter la voiture</button>
        </div>
    </form>
</dialog>

<dialog class="dialog" id="dialog-edit-car">
    <form class="form" method="post" action="<?= e(url($page)) ?>">
        <header class="dialog__head">
            <h2 data-fill-text="title">Modifier la voiture</h2>
            <button class="icon-btn" type="button" data-close aria-label="Fermer"><?= icon('close') ?></button>
        </header>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_car">
        <input type="hidden" name="car_id">
        <label class="field"><span>Prix par jour (DA)</span><input type="number" name="daily_price" min="100" step="50" required></label>
        <label class="field"><span>Kilométrage actuel</span><input type="number" name="mileage" min="0" required></label>
        <div class="form__actions">
            <button class="btn btn--ghost" type="button" data-close>Annuler</button>
            <button class="btn btn--brass" type="submit">Enregistrer</button>
        </div>
    </form>
</dialog>
<?php
dashboard_end();
