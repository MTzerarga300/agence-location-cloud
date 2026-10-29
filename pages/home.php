<?php
/** Page d'accueil publique : voiture vedette, flotte filtrable par agence, étapes de réservation. */
declare(strict_types=1);

$cars = q_all(
    "SELECT c.*, s.agency_name, s.address AS agency_address,
            EXISTS (SELECT 1 FROM rentals r
                    WHERE r.car_id = c.car_id AND r.status = 'ongoing'
                      AND r.start_date <= CURDATE() AND r.end_date >= CURDATE()) AS reserved_today
     FROM cars c
     LEFT JOIN staff s ON s.staff_id = c.agent_id
     WHERE c.status <> 'hors-service'
     ORDER BY s.staff_id, c.daily_price"
);

$agencies = [];
foreach ($cars as $car) {
    $id = (int) ($car['agent_id'] ?? 0);
    $agencies[$id] ??= [
        'name'    => $car['agency_name'] ?: 'Agence partenaire',
        'address' => $car['agency_address'] ?: 'Oran',
        'count'   => 0,
    ];
    $agencies[$id]['count']++;
}

// Voiture vedette : la plus prestigieuse actuellement libre
$featured = null;
foreach ($cars as $car) {
    if ($car['status'] === 'available' && !$car['reserved_today']
        && ($featured === null || $car['daily_price'] > $featured['daily_price'])) {
        $featured = $car;
    }
}
$bookUrl = fn (int $carId): string => current_client()
    ? url('dashboard_client', ['car' => $carId]) . '#reserver'
    : url('login_client', ['car' => $carId]);

render_head('Location de voitures à Oran', 'page-home');
public_header();
?>
<main>
    <section class="hero">
        <div class="wrap hero__grid">
            <div class="hero__text">
                <h1 class="hero__title">Des voitures prêtes à&nbsp;partir, à&nbsp;Oran.</h1>
                <p class="hero__lead">Citadines, berlines et SUV de prestige proposés par nos agences d'Es Senia et d'El Akid Lotfi. Réservez en ligne, puis réglez en espèces à la remise des clés ou par CCP.</p>
                <div class="hero__actions">
                    <a class="btn btn--brass btn--lg" href="<?= e(current_client() ? url('dashboard_client') : url('login_client')) ?>">Réserver une voiture</a>
                    <a class="btn btn--ghost btn--lg" href="#flotte">Voir la flotte</a>
                </div>
                <dl class="hero__facts">
                    <div><dt>Voitures</dt><dd><?= count($cars) ?></dd></div>
                    <div><dt>Agences</dt><dd><?= count($agencies) ?></dd></div>
                    <div><dt>À partir de</dt><dd><?= $cars ? money(min(array_column($cars, 'daily_price'))) : '—' ?><small>/jour</small></dd></div>
                </dl>
            </div>

            <?php if ($featured): ?>
            <figure class="feature">
                <div class="feature__photo">
                    <img src="<?= e(car_image($featured['image_url'])) ?>" alt="<?= e($featured['brand'] . ' ' . $featured['model']) ?>" data-fallback>
                </div>
                <figcaption class="feature__caption">
                    <span class="feature__name"><?= e($featured['brand']) ?> <?= e($featured['model']) ?></span>
                    <?= plate($featured['license_plate']) ?>
                    <span class="feature__price"><?= money($featured['daily_price']) ?><small>/jour</small></span>
                    <a class="btn btn--brass" href="<?= e($bookUrl((int) $featured['car_id'])) ?>">Réserver</a>
                </figcaption>
            </figure>
            <?php endif; ?>
        </div>
    </section>

    <section class="fleet" id="flotte">
        <div class="wrap">
            <header class="section-head">
                <h2>La flotte</h2>
                <p>Chaque voiture appartient à l'une de nos agences, qui vous remet les clés.</p>
            </header>

            <div class="filters" role="group" aria-label="Filtrer par agence" data-filter-group="fleet">
                <button type="button" class="chip is-active" data-filter="all">Toutes les agences <span><?= count($cars) ?></span></button>
                <?php foreach ($agencies as $id => $agency): ?>
                    <button type="button" class="chip" data-filter="agence-<?= (int) $id ?>">
                        <?= e($agency['name']) ?> <span><?= (int) $agency['count'] ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="car-grid" data-filter-target="fleet">
                <?php foreach ($cars as $car):
                    $available = $car['status'] === 'available' && !$car['reserved_today']; ?>
                <article class="car" data-filter-key="agence-<?= (int) ($car['agent_id'] ?? 0) ?>">
                    <div class="car__photo">
                        <img src="<?= e(car_image($car['image_url'])) ?>" alt="<?= e($car['brand'] . ' ' . $car['model']) ?>" loading="lazy" data-fallback>
                        <?= plate($car['license_plate']) ?>
                    </div>
                    <div class="car__body">
                        <div class="car__title">
                            <h3><?= e($car['brand']) ?> <?= e($car['model']) ?></h3>
                            <?= car_status_badge($car['status'], (bool) $car['reserved_today']) ?>
                        </div>
                        <p class="car__meta"><?= (int) $car['year'] ?>, <?= e(mb_strtolower((string) $car['color'])) ?>, <?= km($car['mileage']) ?></p>
                        <p class="car__agency"><?= icon('pin') ?><?= e($car['agency_name'] ?: 'Agence partenaire') ?>, <?= e($car['agency_address'] ?: 'Oran') ?></p>
                        <div class="car__foot">
                            <span class="price"><?= money($car['daily_price']) ?><small>/jour</small></span>
                            <?php if ($available): ?>
                                <a class="btn btn--line" href="<?= e($bookUrl((int) $car['car_id'])) ?>">Réserver</a>
                            <?php else: ?>
                                <a class="btn btn--line" href="<?= e($bookUrl((int) $car['car_id'])) ?>">Choisir d'autres dates</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="steps" id="reserver">
        <div class="wrap">
            <header class="section-head">
                <h2>Réserver en trois étapes</h2>
                <p>Comptez quelques minutes, sans paiement en ligne.</p>
            </header>
            <ol class="steps__list">
                <li>
                    <h3>Créez votre compte client</h3>
                    <p>Nom, téléphone et numéro de permis : l'agence en a besoin pour préparer le contrat.</p>
                </li>
                <li>
                    <h3>Choisissez la voiture et les dates</h3>
                    <p>Le prix total s'affiche avant la confirmation. Une voiture déjà réservée sur vos dates est refusée.</p>
                </li>
                <li>
                    <h3>L'agence confirme et vous remet les clés</h3>
                    <p>Payez en espèces au retrait ou par CCP. Vous suivez l'état de chaque réservation dans votre espace.</p>
                </li>
            </ol>
        </div>
    </section>
</main>
<?php
public_footer();
render_foot();
