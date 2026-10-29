<?php
/**
 * Connexion à MySQL, requêtes préparées et installation de la base.
 *
 * La base et les tables sont créées une seule fois, au premier accès :
 * les requêtes suivantes ne font qu'un contrôle rapide.
 */
declare(strict_types=1);

function db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, '', DB_PORT);
    $db->set_charset('utf8mb4');

    try {
        $db->select_db(DB_NAME);
    } catch (mysqli_sql_exception) {
        $db->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $db->select_db(DB_NAME);
    }

    $installed = $db->query("SHOW TABLES LIKE 'staff'")->num_rows > 0;
    if (!$installed) {
        db_install($db);
    }

    return $db;
}

/** Exécute une requête préparée et renvoie l'instruction. */
function q(string $sql, array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($params !== []) {
        $types = '';
        foreach ($params as $param) {
            $types .= is_int($param) ? 'i' : (is_float($param) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function q_all(string $sql, array $params = []): array
{
    $result = q($sql, $params)->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function q_one(string $sql, array $params = []): ?array
{
    $result = q($sql, $params)->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    return $row ?: null;
}

function q_value(string $sql, array $params = []): mixed
{
    $row = q_one($sql, $params);
    return $row === null ? null : array_values($row)[0];
}

function db_install(mysqli $db): void
{
    $schema = [
        "CREATE TABLE IF NOT EXISTS staff (
            staff_id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            phone VARCHAR(20),
            role ENUM('admin','agent','mechanic') NOT NULL DEFAULT 'agent',
            password_hash VARCHAR(255) NOT NULL,
            agency_name VARCHAR(100),
            address VARCHAR(255),
            status ENUM('active','blocked') NOT NULL DEFAULT 'active',
            total_debits DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            hire_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS clients (
            client_id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            phone VARCHAR(20),
            password_hash VARCHAR(255) NOT NULL,
            driver_license VARCHAR(50) NOT NULL UNIQUE,
            address VARCHAR(255),
            registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS cars (
            car_id INT AUTO_INCREMENT PRIMARY KEY,
            brand VARCHAR(50) NOT NULL,
            model VARCHAR(50) NOT NULL,
            year INT NOT NULL,
            license_plate VARCHAR(20) NOT NULL UNIQUE,
            color VARCHAR(30),
            mileage INT NOT NULL DEFAULT 0,
            status ENUM('available','rented','maintenance','hors-service') NOT NULL DEFAULT 'available',
            daily_price DECIMAL(10,2) NOT NULL,
            image_url VARCHAR(255),
            agent_id INT,
            CONSTRAINT chk_year CHECK (year >= 1990),
            FOREIGN KEY (agent_id) REFERENCES staff(staff_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS rentals (
            rental_id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            car_id INT NOT NULL,
            staff_id INT,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            total_price DECIMAL(10,2) NOT NULL,
            status ENUM('ongoing','completed','cancelled') NOT NULL DEFAULT 'ongoing',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(client_id) ON DELETE CASCADE,
            FOREIGN KEY (car_id) REFERENCES cars(car_id) ON DELETE CASCADE,
            FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            rental_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            method ENUM('cash','credit_card','debit_card','bank_transfer','ccp') NOT NULL,
            payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('paid','pending','refunded') NOT NULL DEFAULT 'pending',
            FOREIGN KEY (rental_id) REFERENCES rentals(rental_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS maintenance (
            maintenance_id INT AUTO_INCREMENT PRIMARY KEY,
            car_id INT NOT NULL,
            agent_id INT,
            description TEXT NOT NULL,
            cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            maintenance_date DATE NOT NULL,
            performed_by VARCHAR(100),
            FOREIGN KEY (car_id) REFERENCES cars(car_id) ON DELETE CASCADE,
            FOREIGN KEY (agent_id) REFERENCES staff(staff_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($schema as $sql) {
        $db->query($sql);
    }

    db_seed();
}

/** Données de démonstration, insérées une seule fois à l'installation. */
function db_seed(): void
{
    $staff = [
        ['Mohamed Tayeb', 'Zerarga', 'admin@mohamedtayeb.com', '0657140089', 'admin', 'admin123', null, null],
        ['Abderrahmane', 'Zerarga', 'agent1@mohamedtayeb.com', '0657140088', 'agent', 'agent123', 'Agence Abderrahmane Zerarga', 'Es Senia, Oran'],
        ['Adem', 'Zerarga', 'mechanic@mohamedtayeb.com', '0657140087', 'mechanic', 'mech123', null, null],
        ['Karim', 'Zerarga', 'agent2@mohamedtayeb.com', '0657140086', 'agent', 'agent456', 'Agence Karim Zerarga', 'El Akid Lotfi, Oran'],
    ];
    $ids = [];
    foreach ($staff as [$first, $last, $email, $phone, $role, $password, $agency, $address]) {
        q('INSERT INTO staff (first_name, last_name, email, phone, role, password_hash, agency_name, address)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$first, $last, $email, $phone, $role, password_hash($password, PASSWORD_DEFAULT), $agency, $address]);
        $ids[$email] = db()->insert_id;
    }
    $agent1 = $ids['agent1@mohamedtayeb.com'];
    $agent2 = $ids['agent2@mohamedtayeb.com'];

    q('INSERT INTO clients (first_name, last_name, email, phone, password_hash, driver_license, address)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
        ['Client', 'Test', 'client@mohamedtayeb.com', '0657140085', password_hash('client123', PASSWORD_DEFAULT), 'DZ-TEST-0001', 'Oran']);
    $clientId = db()->insert_id;

    $cars = [
        ['Renault', 'Clio', 2023, '115598-123-31', 'Blanc', 5000, 8000, 'https://img.leboncoin.fr/api/v1/lbcpb1/images/d6/f5/c1/d6f5c1c45bebcd82cdb519252590e72a09bd4a7e.jpg?rule=ad-image', $agent1],
        ['Peugeot', '208', 2022, '93208-122-31', 'Bleu', 8000, 7500, 'https://upload.wikimedia.org/wikipedia/commons/f/f3/2020_Peugeot_208_GT_Line_PureTech_1.2_Front.jpg', $agent1],
        ['Dacia', 'Sandero', 2017, '04311-117-06', 'Rouge', 9000, 8500, 'https://images.garage-gros.com/md/sngwqfo-dacia-sandero-2017-diesel-boite-manuelle-rouge-1.webp', $agent1],
        ['Citroën', 'C3', 2022, '88888-122-31', 'Gris', 12000, 4000, 'https://caroutlet.es/wp-content/uploads/thememakers/cardealer/1/4843/main/68a5efb2b627b.jpg', $agent1],
        ['Mercedes', 'C220d', 2024, '12346-124-31', 'Noir', 10000, 42000, 'https://steerwellauto.com/wp-content/uploads/2025/05/1-2-1024x700.jpg', $agent2],
        ['Lamborghini', 'Urus', 2025, '12340-124-31', 'Jaune', 10000, 150000, 'https://mario-carrental.com/program/images/products/1734871553imageFile.jpg', $agent2],
        ['Mercedes', 'Brabus 800', 2025, '12345-124-31', 'Gris', 10000, 140000, 'https://cloud.leparking.fr/2024/08/20/07/47/brabus-800-mercedes-benz-g-63-amg-brabus-g800-24-leder-orange-starlight-grau_9153342036.jpg', $agent2],
        ['Jaguar', 'F-Type', 2023, '00025-123-31', 'Blanc', 4200, 120000, 'https://www.classiccar25.com/imageRender/imageVehicule/330/1728054875_7630bb02ebc18388216d.jpg', $agent2],
    ];
    $carIds = [];
    foreach ($cars as [$brand, $model, $year, $plate, $color, $mileage, $price, $image, $agent]) {
        q('INSERT INTO cars (brand, model, year, license_plate, color, mileage, daily_price, image_url, agent_id)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$brand, $model, $year, $plate, $color, $mileage, (float) $price, $image, $agent]);
        $carIds["$brand $model"] = db()->insert_id;
    }

    // Quatre réservations du client de test, datées par rapport à aujourd'hui
    $rentals = [
        ['Renault Clio', $agent1, -30, -25, 8000, 'completed', 'paid', 'ccp'],
        ['Peugeot 208', $agent1, -5, 2, 7500, 'ongoing', 'pending', 'cash'],
        ['Mercedes C220d', $agent2, -20, -15, 42000, 'completed', 'paid', 'ccp'],
        ['Lamborghini Urus', $agent2, -3, 4, 150000, 'ongoing', 'pending', 'cash'],
    ];
    foreach ($rentals as [$car, $agent, $from, $to, $price, $status, $paymentStatus, $method]) {
        $total = (float) (($to - $from) * $price);
        q('INSERT INTO rentals (client_id, car_id, staff_id, start_date, end_date, total_price, status)
           VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, $carIds[$car], $agent, date('Y-m-d', strtotime("$from days")), date('Y-m-d', strtotime("$to days")), $total, $status]);
        q('INSERT INTO payments (rental_id, amount, method, status) VALUES (?, ?, ?, ?)',
            [db()->insert_id, $total, $method, $paymentStatus]);
    }

    // Historique de maintenance, débité aux agences propriétaires
    $mechanic = 'Adem Zerarga';
    $maintenance = [
        ['Peugeot 208', $agent1, "Vidange d'huile moteur et remplacement du filtre à huile.", 7000],
        ['Renault Clio', $agent1, 'Remplacement des plaquettes de frein avant et arrière, contrôle du circuit de freinage.', 12000],
        ['Mercedes Brabus 800', $agent2, 'Vidange complète, remplacement des filtres à huile et à air, contrôle des niveaux.', 42000],
    ];
    foreach ($maintenance as [$car, $agent, $description, $cost]) {
        q('INSERT INTO maintenance (car_id, agent_id, description, cost, maintenance_date, performed_by)
           VALUES (?, ?, ?, ?, CURDATE(), ?)',
            [$carIds[$car], $agent, $description, (float) $cost, $mechanic]);
        q('UPDATE staff SET total_debits = total_debits + ? WHERE staff_id = ?', [(float) $cost, $agent]);
    }
}
