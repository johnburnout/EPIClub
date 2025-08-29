<?php

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . "/includes/fonctions_fichiers.php";
require __DIR__ . "/includes/fonctions_edition.php";

if (!$isLoggedIn) {
    header('Location: /');
    exit();
}

if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
    throw new \Exception('Erreur de sécurité: Token CSRF invalide');
}

if (!isset($_GET['url'])) {
    die("Paramètre 'url' manquant dans la requête");
}

// Nettoyer et valider le chemin du fichier
$cheminDemande = filter_var($_GET['url'], FILTER_SANITIZE_URL);

// Vérifications de sécurité
if (!preg_match('/\.txt$/i', $cheminDemande)) {
    die("Seuls les fichiers .txt sont autorisés");
}

// Normalisation des chemins
$baseDir = realpath(__DIR__ . '/utilisateur/enregistrements/');
$cheminComplet = realpath($cheminDemande);

// Vérifier que le fichier est bien dans le répertoire autorisé
if ($cheminComplet === false || strpos($cheminComplet, $baseDir) !== 0) {
    die("Accès non autorisé à ce fichier");
}

// Vérification supplémentaire de sécurité
if (!is_readable($cheminComplet)) {
    die("Fichier inaccessible en lecture");
}

// Utilisation de la fonction fichier_lire pour lire le contenu
try {
    $contenu = fichier_lire(['chemin' => $cheminComplet]);
    $contenu = htmlspecialchars($contenu);
    $contenuAffichage = nl2br($contenu);
} catch (\RuntimeException $e) {
    die("Erreur lors de la lecture du fichier: " . $e->getMessage());
}

// Récupérer l'URL de retour si elle existe
$urlRetour = isset($_GET['retour']) ? filter_var($_GET['retour'], FILTER_SANITIZE_URL) : '/';

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <?php include __DIR__ . '/includes/head.php'; ?>
</head>

<body>
    <header style="text-align: right; padding: 10px;">
        <?php include __DIR__ . '/includes/bandeau.php'; ?>
    </header>
    <main class="container">
        <?php include __DIR__ . '/includes/en_tete.php'; ?>
        <h3>Contenu du fichier <?= htmlspecialchars(basename($cheminComplet)) ?></h3>

        <div>
            <table width="100%">
                <tr>
                    <form method="post">
                        <td>
                            <!-- Bouton de téléchargement -->
                            <a href="download.php?file=<?= urlencode($cheminComplet) ?>&csrf_token=<?= $csrf_token ?>" target="_blank">
                                <input type="button" name="telecharger" value="Télécharger le fichier" class="btn download-btn" />
                            </a>
                        </td>
                        <td>
                            <a href="<?= htmlspecialchars($urlRetour) ?>?csrf_token=<?= $csrf_token ?>&id=<?= $_GET['id'] ?>">
                                <input type="button" name="retour" value="Retour" class="btn return-btn" />
                            </a>
                        </td>
                    </form>
                </tr>
            </table>
        </div>
        <div class="card card-bodycontenu-texte"><?= $contenuAffichage ?></div>
    </main>
    <footer>
        <?php include __DIR__ . '/includes/bandeau_bas.php'; ?>
    </footer>
</body>

</html>