# Mohamed Tayeb Agency : version premium

Site de location de voitures à Oran, en PHP 8.2 et MySQL, sans framework.
Cette version reprend toutes les fonctionnalités du site d'origine avec un nouveau design et un code réorganisé et sécurisé.

## Installation avec XAMPP

1. Copiez le dossier `agence-premium` dans `C:\xampp\htdocs\`.
2. Dans le panneau XAMPP, démarrez **Apache** et **MySQL**.
3. Ouvrez <http://localhost/agence-premium/public/>.

Au premier affichage, le site crée tout seul la base `mohamed_tayeb_agency_premium` et ses données de démonstration.
L'ancienne base `mohamed_tayeb_zerarga_agency` n'est pas touchée : les deux versions peuvent coexister.

Pour repartir de zéro, supprimez la base `mohamed_tayeb_agency_premium` dans phpMyAdmin, puis rechargez la page.

## Comptes de démonstration

| Rôle | E-mail | Mot de passe |
| --- | --- | --- |
| Administrateur | admin@mohamedtayeb.com | admin123 |
| Agence 1 | agent1@mohamedtayeb.com | agent123 |
| Agence 2 | agent2@mohamedtayeb.com | agent456 |
| Mécanicien | mechanic@mohamedtayeb.com | mech123 |
| Client | client@mohamedtayeb.com | client123 |

## Organisation du code

```
agence-premium/
├── public/                 seul dossier exposé par le serveur web
│   ├── index.php           point d'entrée : session, sécurité, choix de la page
│   ├── assets/             app.css, app.js, images
│   └── uploads/cars/       photos envoyées par les agences
├── src/
│   ├── config.php          configuration (variables d'environnement)
│   ├── database.php        connexion, requêtes préparées, installation
│   ├── helpers.php         formats, messages, CSRF, authentification, photos
│   └── layout.php          gabarits HTML et composants partagés
└── pages/                  une page par fichier
    ├── home.php            accueil public
    ├── staff-login.php     connexion du personnel
    ├── client-login.php    connexion et inscription des clients
    ├── client.php          espace client
    ├── agent.php           espace agence
    ├── mechanic.php        espace atelier
    └── admin.php           espace administrateur
```

## Ce qui a changé par rapport à la version d'origine

**Design** : thème sombre « pétrole et laiton », plaques d'immatriculation dessinées, flotte filtrable par agence, récapitulatif de réservation calculé en direct, tableaux de bord avec barre latérale, affichage adapté au téléphone.

**Corrections de sécurité**
- Toutes les requêtes SQL sont préparées : plus de risque d'injection SQL.
- Les mots de passe ne sont plus écrits dans les journaux (`error_log`).
- La connexion vérifie toujours le mot de passe en base : plus de connexion « en dur » pour l'admin et les comptes de test.
- Protection CSRF sur tous les formulaires.
- Identifiant de session renouvelé à chaque connexion ; un compte bloqué est déconnecté immédiatement.
- Les photos envoyées sont contrôlées (vrai fichier image, 5 Mo maximum).

**Corrections de fonctionnement**
- La base n'est plus recréée ni modifiée à chaque visite : un changement de mot de passe est désormais conservé.
- Le mode de paiement choisi par le client (espèces ou CCP) est enregistré.
- Une voiture ne peut plus être réservée deux fois sur les mêmes dates.
- Chaque agence ne voit que ses propres clients.
- Le mécanicien peut changer son mot de passe (la section manquait).
- Le client peut annuler une réservation tant qu'elle n'est pas payée.

## Utilisation avec Docker

La configuration se lit dans les variables d'environnement `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD` et `DB_NAME`, avec les valeurs de XAMPP par défaut.
La page `?page=health` renvoie `{"status":"ok"}` quand la base répond : elle peut servir de sonde de santé pour Docker ou Kubernetes.
