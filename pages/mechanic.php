<?php
/** Espace atelier : voitures en révision, nouvelle intervention, historique. */
declare(strict_types=1);

$mechanic = require_staff('mechanic');
$mechanicId = (int) $mechanic['id'];
$mechanicName = $mechanic['first_name'] . ' ' . $mechanic['last_name'];
$page = 'dashboard_mechanic';

$interventions = [
    'vidange'      => ['Vidange', "Vidange d'huile moteur et remplacement du filtre à huile."],
    'freins'       => ['Plaquettes de frein', 'Remplacement des plaquettes de frein avant et arrière, contrôle du circuit de freinage.'],
    'pneus'        => ['Pneus', 'Remplacement des pneus, contrôle de la pression et de l\'équilibrage.'],
    'revision'     => ['Révision moteur', 'Révision complète du moteur et contrôle des éléments essentiels.'],
    'autre'        => ['Autre intervention', ''],
];

if (is_post()) {
    $action = post('action');

    if ($action === 'add_maintenance') {
        $carId = post_int('car_id');
        $description = post('description');
        $cost = max(0.0, post_float('cost'));
        $car = q_one("SELECT car_id, brand, model, agent_id FROM cars WHERE car_id = ? AND status <> 'hors-service'", [$carId]);

        if ($car === null) {
            flash('error', 'Choisissez une voiture de la flotte.');
        } elseif ($description === '') {
            flash('error', "Décrivez l'intervention réalisée.");
        } else {
            $agentId = $car['agent_id'] !== null ? (int) $car['agent_id'] : null;
            db()->begin_transaction();
            q("UPDATE cars SET status = 'maintenance' WHERE car_id = ?", [$carId]);
            q('INSERT INTO maintenance (car_id, agent_id, description, cost, maintenance_date, performed_by)
               VALUES (?, ?, ?, ?, CURDATE(), ?)', [$carId, $agentId, $description, $cost, $mechanicName]);
            if ($agentId !== null && $cost > 0) {
                q('UPDATE staff SET total_debits = total_debits + ? WHERE staff_id = ?', [$cost, $agentId]);
            }
            db()->commit();
            flash('success', "{$car['brand']} {$car['model']} passée en révision"
                . ($cost > 0 ? ', ' . money($cost) . " débités à l'agence propriétaire." : '.'));
        }
        redirect($page, [], 'atelier');
    }

    if ($action === 'complete_maintenance') {
        $carId = post_int('car_id');
        q("UPDATE cars SET status = 'available' WHERE car_id = ? AND status = 'maintenance'", [$carId]);
        flash('success', 'Voiture remise en service : elle est de nouveau proposée aux clients.');
        redirect($page, [], 'atelier');
    }

    if ($action === 'change_password') {
        $error = change_staff_password($mechanicId, (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''));
        flash($error ? 'error' : 'success', $error ?? 'Mot de passe modifié.');
        redirect($page, [], 'mot-de-passe');
    }
}

// ---------- Données ----------

$cars = q_all(
    "SELECT c.*, s.agency_name,
            EXISTS (SELECT 1 FROM rentals r WHERE r.car_id = c.car_id AND r.status = 'ongoing'
                    AND r.start_date <= CURDATE() AND r.end_date >= CURDATE()) AS reserved_today,
            (SELECT m.description FROM maintenance m WHERE m.car_id = c.car_id
             ORDER BY m.maintenance_date DESC, m.maintenance_id DESC LIMIT 1) AS last_intervention,
            (SELECT COUNT(*) FROM rentals r WHERE r.car_id = c.car_id) AS rental_count
     FROM cars c LEFT JOIN staff s ON s.staff_id = c.agent_id
     WHERE c.status <> 'hors-service'
     ORDER BY (c.status = 'maintenance') DESC, c.brand, c.model"
);
$inWorkshop = array_values(array_filter($cars, fn ($c) => $c['status'] === 'maintenance'));
$onRoad = count(array_filter($cars, fn ($c) => $c['status'] !== 'maintenance' && $c['reserved_today']));

$history = q_all(
    "SELECT m.*, c.brand, c.model, c.license_plate, s.agency_name FROM maintenance m
     JOIN cars c ON c.car_id = m.car_id
     LEFT JOIN staff s ON s.staff_id = m.agent_id
     ORDER BY m.maintenance_date DESC, m.maintenance_id DESC
     LIMIT 100"
);
$totalCost = (float) q_value('SELECT COALESCE(SUM(cost), 0) FROM maintenance');

dashboard_start(
    'Atelier',
    count($inWorkshop) > 0
        ? count($inWorkshop) . ' voiture' . (count($inWorkshop) > 1 ? 's' : '') . ' en révision.'
        : "Aucune voiture à l'atelier.",
    'Espace atelier',
    ['atelier' => "À l'atelier", 'flotte' => 'Toute la flotte', 'historique' => 'Historique', 'mot-de-passe' => 'Mot de passe'],
    ['name' => $mechanicName, 'detail' => role_label('mechanic'), 'logout' => 'logout']
);
?>
<div class="stats">
    <?= stat_tile("À l'atelier", (string) count($inWorkshop), 'en révision', count($inWorkshop) ? 'warn' : '') ?>
    <?= stat_tile('Sur la route', (string) $onRoad, "louées aujourd'hui") ?>
    <?= stat_tile('Flotte', (string) count($cars), 'voitures en service') ?>
    <?= stat_tile('Interventions', money($totalCost), 'coût total facturé') ?>
    <?= stat_tile('Votre part', money($totalCost * MECHANIC_SHARE), (int) (MECHANIC_SHARE * 100) . ' % du coût total', 'brass') ?>
</div>

<section class="panel" id="atelier">
    <header class="panel__head panel__head--action">
        <div>
            <h2>À l'atelier</h2>
            <p>Remettez une voiture en service une fois l'intervention terminée.</p>
        </div>
        <button class="btn btn--brass" type="button" data-open="dialog-maintenance"><?= icon('plus') ?>Nouvelle intervention</button>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Voiture</th><th>Immatriculation</th><th>Agence</th><th class="num">Kilométrage</th><th>Dernière intervention</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($inWorkshop as $car): ?>
                <tr>
                    <td><div class="cell-car"><img src="<?= e(car_image($car['image_url'])) ?>" alt="" loading="lazy" data-fallback><strong><?= e($car['brand'] . ' ' . $car['model']) ?></strong></div></td>
                    <td><?= plate($car['license_plate']) ?></td>
                    <td><?= e($car['agency_name'] ?? '—') ?></td>
                    <td class="num"><?= km($car['mileage']) ?></td>
                    <td class="wrap-text"><?= e($car['last_intervention'] ?? '—') ?></td>
                    <td class="actions">
                        <form method="post" action="<?= e(url($page)) ?>" data-confirm="Remettre <?= e($car['brand'] . ' ' . $car['model']) ?> en service ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="complete_maintenance">
                            <input type="hidden" name="car_id" value="<?= (int) $car['car_id'] ?>">
                            <button class="btn btn--small btn--brass" type="submit">Remettre en service</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$inWorkshop) echo empty_row(6, "Aucune voiture en révision. Enregistrez une intervention pour en ajouter une."); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="flotte">
    <header class="panel__head panel__head--action">
        <div>
            <h2>Toute la flotte</h2>
            <p>Les voitures de toutes les agences.</p>
        </div>
        <label class="search"><?= icon('search') ?><input type="search" placeholder="Rechercher une voiture" data-table-filter="table-fleet" aria-label="Rechercher une voiture"></label>
    </header>
    <div class="table-wrap">
        <table class="table" id="table-fleet">
            <thead><tr><th>Voiture</th><th>Immatriculation</th><th>Agence</th><th>Année</th><th class="num">Kilométrage</th><th class="num">Locations</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($cars as $car): ?>
                <tr>
                    <td><strong><?= e($car['brand'] . ' ' . $car['model']) ?></strong></td>
                    <td><?= plate($car['license_plate']) ?></td>
                    <td><?= e($car['agency_name'] ?? '—') ?></td>
                    <td><?= (int) $car['year'] ?></td>
                    <td class="num"><?= km($car['mileage']) ?></td>
                    <td class="num"><?= (int) $car['rental_count'] ?></td>
                    <td><?= car_status_badge($car['status'], (bool) $car['reserved_today']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cars) echo empty_row(7, 'Aucune voiture dans la flotte.'); ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="historique">
    <header class="panel__head">
        <h2>Historique des interventions</h2>
        <p>Les 100 dernières interventions, toutes agences confondues.</p>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Voiture</th><th>Agence facturée</th><th>Intervention</th><th>Mécanicien</th><th class="num">Coût</th></tr></thead>
            <tbody>
            <?php foreach ($history as $m): ?>
                <tr>
                    <td><?= fdate($m['maintenance_date']) ?></td>
                    <td><?= e($m['brand'] . ' ' . $m['model']) ?><br><?= plate($m['license_plate']) ?></td>
                    <td><?= e($m['agency_name'] ?? '—') ?></td>
                    <td class="wrap-text"><?= e($m['description']) ?></td>
                    <td><?= e($m['performed_by'] ?? '—') ?></td>
                    <td class="num"><?= money($m['cost']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history) echo empty_row(6, 'Aucune intervention enregistrée.'); ?>
            </tbody>
        </table>
    </div>
</section>

<?php password_section($page); ?>

<dialog class="dialog" id="dialog-maintenance">
    <form class="form" method="post" action="<?= e(url($page)) ?>">
        <header class="dialog__head">
            <h2>Nouvelle intervention</h2>
            <button class="icon-btn" type="button" data-close aria-label="Fermer"><?= icon('close') ?></button>
        </header>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_maintenance">
        <label class="field"><span>Voiture</span>
            <select name="car_id" required>
                <option value="">Choisir une voiture</option>
                <?php foreach ($cars as $car): if ($car['status'] === 'maintenance') continue; ?>
                    <option value="<?= (int) $car['car_id'] ?>"><?= e($car['brand'] . ' ' . $car['model'] . ' · ' . $car['license_plate'] . ' · ' . ($car['agency_name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Type d'intervention</span>
            <select data-description-source>
                <?php foreach ($interventions as $key => [$label, $text]): ?>
                    <option value="<?= e($key) ?>" data-text="<?= e($text) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Description</span>
            <textarea name="description" rows="3" required data-description-target><?= e($interventions['vidange'][1]) ?></textarea></label>
        <label class="field"><span>Coût (DA)</span>
            <input type="number" name="cost" min="0" step="100" value="0"></label>
        <p class="form__note">La voiture passe « en révision » et n'est plus proposée aux clients. Le coût est débité à son agence.</p>
        <div class="form__actions">
            <button class="btn btn--ghost" type="button" data-close>Annuler</button>
            <button class="btn btn--brass" type="submit">Enregistrer l'intervention</button>
        </div>
    </form>
</dialog>
<?php
dashboard_end();
