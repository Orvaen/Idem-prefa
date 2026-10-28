<?php
// Each of the four profiles answers to its three names: the journal's short form
// ("chef-atelier"), the trade name the client uses ("Chef d'atelier") and the label stored
// in the `role` table ("Gestionnaire"). Whoever creates an account knows one of the three,
// not the other two — a script that accepts only one of them asks them to translate.
//
// Reads nothing: no database, no session. Run from the PHP container.
$root = is_dir('/var/www/html/dashboard') ? '/var/www/html' : __DIR__ . '/../src';
require_once $root . '/modeles/autoload.php';

function checkRole(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// The three names of each profile lead back to that profile. A name that led to two
// profiles at once would fail here too: the second one would answer with the first.
foreach (Role::cases() as $cas) {
    foreach ($cas->noms() as $nom) {
        checkRole(Role::depuisLeTexte($nom) === $cas, '"' . $nom . '" does not give back ' . $cas->name);
    }
}

// Typed by hand, as it comes: accents, capitals, apostrophe, underscore, surrounding
// spaces, and the plural — "chargé d'affaires" is the usual spelling of the trade, which
// the database writes singular.
$variantes = [
    'charge-production' => Role::ChargeDeProduction,
    'Chargé de production' => Role::ChargeDeProduction,
    'CHARGE DE PRODUCTION' => Role::ChargeDeProduction,
    'administrateur' => Role::ChargeDeProduction,
    "chef d'atelier" => Role::ChefDAtelier,
    'chef_atelier' => Role::ChefDAtelier,
    '  Gestionnaire  ' => Role::ChefDAtelier,
    'personnel d atelier' => Role::PersonnelAtelier,
    'Utilisateur' => Role::PersonnelAtelier,
    "chargé d'affaires" => Role::ChargeDAffaire,
    'CHARGE-AFFAIRE' => Role::ChargeDAffaire,
    'Demandeur' => Role::ChargeDAffaire,
];
foreach ($variantes as $texte => $attendu) {
    checkRole(Role::depuisLeTexte($texte) === $attendu,
        '"' . $texte . '" should give ' . $attendu->name . ', gave ' . (Role::depuisLeTexte($texte)?->name ?? 'nothing'));
}

// What is not a profile does not become one. "Manssouri" is the real case: a two-word
// surname typed without quotes, whose second word lands in the next argument. "atelier"
// and "charge" are each half of two profiles, and "admin" is a shortening the project
// never writes — guessing between profiles is worse than showing the list.
foreach (['', '   ', '-', '0', '2', 'Manssouri', 'atelier', 'charge', 'admin', 'chef'] as $texte) {
    checkRole(Role::depuisLeTexte($texte) === null,
        '"' . $texte . '" should name no profile, gave ' . (Role::depuisLeTexte($texte)?->name ?? ''));
}

// Numbers still come from the database, where 4 was skipped and never reused.
checkRole(Role::depuisLaBase(null) === null, 'an account without a role must give no role');
checkRole(Role::depuisLaBase(4) === null, 'role 4 does not exist');
checkRole(Role::depuisLaBase(5) === Role::ChargeDAffaire, 'role 5 is the chargé d\'affaire');

echo 'Role names, in all three vocabularies: OK' . PHP_EOL;
