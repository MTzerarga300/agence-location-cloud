<?php
/** Espace client : nouvelle réservation, annulation et historique. */
declare(strict_types=1);

$client = require_client();
$clientId = (int) $client['id'];

if (is_post() && post('action') === 'reserve') {
    $carId = post_int('car_id');
    $start = post('start_date');
    $end = post('end_date');
    $method = post('payment_method');
    $today = date('Y-m-d');

    $car = q_one("SELECT * FROM cars WHERE car_id = ? AND status = 'available'", [$carId]);
    $validDates = (bool) DateTime::createFromFormat('Y-m-d', $start) && (bool) DateTime::createFromFormat('Y-m-d', $end);

    $error = match (true) {
        $car === null                       => "Cette voiture n'est plus disponible à la réservation.",
        empty($car['agent_id'])             => "Cette voiture n'est rattachée à aucune agence pour le moment.",
        !$validDates                        => 'Indiquez une date de début et une date de fin.',
        $start < $today                     => "La date de début ne peut pas être passée.",
        $end <= $start                      => 'La date de fin doit être après la date de début.',
        !in_array($method, ['cash', 'ccp'], true) => 'Choisissez un mode de paiement.',
        default                             => null,
    };

    if ($error === null) {
        $conflict = q_one(
            "SELECT start_date, end_date FROM rentals
             WHERE car_id = ? AND status = 'ongoing' AND start_date <= ? AND end_date >= ?
             LIMIT 1",
            [$carId, $end, $start]
        );
        if ($conflict) {
            $error = sprintf('Cette voiture est déjà réservée du %s au %s. Choisissez d\'autres dates.',
                fdate($conflict['start_date']), fdate($conflict['end_date']));
        }
    }

    if ($error !== null) {
        flash('error', $error);
        redirect('dashboard_client', ['car' => $carId], 'reserver');
    }

    $days = days_between($start, $end);
    $total = round($days * (float) $car['daily_price'], 2);

    db()->begin_transaction();
    q('INSERT INTO rentals (client_id, car_id, staff_id, start_date, end_date, total_price, status)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$clientId, $carId, (int) $car['agent_id'], $start, $end, $total, 'ongoing']);
    q("INSERT INTO payments (rental_id, amount, method, status) VALUES (?, ?, ?, 'pending')",
        [db()->insert_id, $total, $method]);
    db()->commit();

    flash('success', sprintf('Réservation envoyée : %s %s, %d jour%s, %s. L\'agence va la confirmer.',
        $car['brand'], $car['model'], $days, $days > 1 ? 's' : '', money($total)));
    redirect('dashboard_client', [], 'historique');
}

if (is_post() && post('action') === 'cancel') {
    $rentalId = post_int('rental_id');
    $rental = q_one(
        "SELECT r.rental_id, p.status AS payment_status FROM rentals r
         LEFT JOIN payments p ON p.rental_id = r.rental_id
         WHERE r.rental_id = ? AND r.client_id = ? AND r.status = 'ongoing'",
        [$rentalId, $clientId]
    );
    if ($rental === null) {
        flash('error', 'Cette réservation ne peut plus être annulée.');
    } elseif ($rental['payment_status'] === 'paid') {
        flash('error', "Cette réservation est déjà payée. Contactez l'agence pour l'annuler.");
    } else {
        q("UPDATE rentals SET status = 'cancelled' WHERE rental_id = ?", [$rentalId]);
        flash('success', "Réservation n° $rentalId annulée.");
    }
    redirect('dashboard_client', [], 'historique');
}

$cars = q_all(
    "SELECT c.car_id, c.brand, c.model, c.year, c.license_plate, c.daily_price, c.image_url,
            s.agency_name, s.address AS agency_address, s.phone AS agency_phone
     FROM cars c
     JOIN staff s ON s.staff_id = c.agent_id
     WHERE c.status = 'available'
     ORDER BY c.daily_price"
);
$selectedCar = (int) ($_GET['car'] ?? 0);

$history = q_all(
    "SELECT r.*, c.brand, c.model, c.license_plate, s.agency_name, s.phone AS agency_phone,
            p.status AS payment_status, p.method AS payment_method
     FROM rentals r
     JOIN cars c ON c.car_id = r.car_id
     LEFT JOIN staff s ON s.staff_id = r.staff_id
     LEFT JOIN payments p ON p.rental_id = r.rental_id
     WHERE r.client_id = ?
     ORDER BY r.created_at DESC, r.rental_id DESC",
    [$clientId]
);
$ongoing = count(array_filter($history, fn ($r) => $r['status'] === 'ongoing'));

dashboard_start(
    'Bonjour ' . $client['first_name'],
    $ongoing > 0
        ? "Vous avez $ongoing réservation" . ($ongoing > 1 ? 's' : '') . ' en cours.'
        : 'Aucune réservation en cours. Choisissez une voiture ci-dessous.',
    'Espace client',
    ['reserver' => 'Nouvelle réservation', 'historique' => 'Mes réservations'],
    ['name' => $client['first_name'] . ' ' . $client['last_name'], 'detail' => $client['email'], 'logout' => 'logout_client']
);
?>
<section class="panel" id="reserver">
    <header class="panel__head">
        <h2>Nouvelle réservation</h2>
        <p>Le prix est calculé par nuit : du lundi au mercredi, vous payez 2 jours.</p>
    </header>

    <?php if (!$cars): ?>
        <p class="empty">Aucune voiture n'est disponible pour le moment. Revenez un peu plus tard.</p>
    <?php else: ?>
    <form class="booking" method="post" action="<?= e(url('dashboard_client')) ?>" data-booking>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reserve">

        <div class="booking__fields">
            <label class="field"><span>Voiture</span>
                <select name="car_id" required data-booking-car>
                    <option value="">Choisir une voiture</option>
                    <?php foreach ($cars as $car): ?>
                        <option value="<?= (int) $car['car_id'] ?>"
                                data-price="<?= e($car['daily_price']) ?>"
                                data-name="<?= e($car['brand'] . ' ' . $car['model']) ?>"
                                data-plate="<?= e($car['license_plate']) ?>"
                                data-image="<?= e(car_image($car['image_url'])) ?>"
                                data-agency="<?= e(($car['agency_name'] ?? '') . ', ' . ($car['agency_address'] ?? '')) ?>"
                                <?= $selectedCar === (int) $car['car_id'] ? 'selected' : '' ?>>
                            <?= e($car['brand'] . ' ' . $car['model']) ?> · <?= money($car['daily_price']) ?>/jour
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="form__row">
                <label class="field"><span>Début</span>
                    <input type="date" name="start_date" required min="<?= date('Y-m-d') ?>" data-booking-start></label>
                <label class="field"><span>Fin</span>
                    <input type="date" name="end_date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" data-booking-end></label>
            </div>

            <fieldset class="choice">
                <legend>Paiement</legend>
                <label class="choice__item">
                    <input type="radio" name="payment_method" value="cash" required checked>
                    <span><strong>Espèces</strong><small>À la remise des clés, en agence</small></span>
                </label>
                <label class="choice__item">
                    <input type="radio" name="payment_method" value="ccp">
                    <span><strong>CCP</strong><small>Virement postal avant le retrait</small></span>
                </label>
            </fieldset>
        </div>

        <aside class="summary" aria-live="polite">
            <div class="summary__photo"><img src="assets/car-placeholder.svg" alt="" data-booking-image data-fallback></div>
            <div class="summary__body">
                <h3 data-booking-name>Aucune voiture choisie</h3>
                <p class="summary__agency" data-booking-agency>Choisissez une voiture pour voir l'agence et le prix.</p>
                <span class="plate" data-booking-plate hidden></span>
                <dl class="summary__lines">
                    <div><dt>Prix par jour</dt><dd data-booking-price>—</dd></div>
                    <div><dt>Durée</dt><dd data-booking-days>—</dd></div>
                    <div class="summary__total"><dt>Total</dt><dd data-booking-total>—</dd></div>
                </dl>
                <button class="btn btn--brass btn--block" type="submit">Envoyer la réservation</button>
            </div>
        </aside>
    </form>
    <?php endif; ?>
</section>

<section class="panel" id="historique">
    <header class="panel__head">
        <h2>Mes réservations</h2>
        <p>Une réservation reste « en attente » jusqu'à ce que l'agence confirme le paiement.</p>
    </header>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>N°</th><th>Voiture</th><th>Agence</th><th>Dates</th><th class="num">Total</th><th>Statut</th><th>Paiement</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($history as $rental): ?>
                <tr>
                    <td class="muted">#<?= (int) $rental['rental_id'] ?></td>
                    <td><strong><?= e($rental['brand'] . ' ' . $rental['model']) ?></strong><br><?= plate($rental['license_plate']) ?></td>
                    <td><?= e($rental['agency_name'] ?? '—') ?><br><small class="muted"><?= e($rental['agency_phone'] ?? '') ?></small></td>
                    <td><?= fdate($rental['start_date']) ?><br><small class="muted">au <?= fdate($rental['end_date']) ?></small></td>
                    <td class="num"><?= money($rental['total_price']) ?></td>
                    <td><?= rental_status_badge($rental['status']) ?></td>
                    <td><?= payment_badge($rental['status'], $rental['payment_status']) ?><br><small class="muted"><?= e(payment_method_label($rental['payment_method'])) ?></small></td>
                    <td class="actions">
                        <?php if ($rental['status'] === 'ongoing' && $rental['payment_status'] !== 'paid'): ?>
                        <form method="post" action="<?= e(url('dashboard_client')) ?>" data-confirm="Annuler la réservation n° <?= (int) $rental['rental_id'] ?> ?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="rental_id" value="<?= (int) $rental['rental_id'] ?>">
                            <button class="btn btn--small btn--danger" type="submit">Annuler</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history) echo empty_row(8, "Vous n'avez encore rien réservé."); ?>
            </tbody>
        </table>
    </div>
</section>
<?php
dashboard_end();
