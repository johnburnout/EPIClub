<?php
// tests/public_routes.php
// Routes légitimement accessibles à un client anonyme.
// Toute route absente de cette liste DOIT rediriger un anonyme vers /se_connecter
// (ou renvoyer 401/403).

return [
    // Authentification
    '/se_connecter',
    '/se_deconnecter',

    // Réinitialisation de mot de passe
    '/mot_de_passe_oublie',
    '/mot_de_passe_oublie/confirmation',
    '/regenerer_mot_de_passe',
    // Note (#104) : /guide est désormais réservé aux utilisateurs connectés
    // (décision produit 2026-10-17) — volontairement absent de cette liste.
];