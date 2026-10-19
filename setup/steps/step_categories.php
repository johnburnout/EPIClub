<?php

use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;

/**
 * Étape « Catégories » de l'installeur (décision produit 2026-10-19) :
 * propose une liste préparée de catégories selon l'activité du club
 * (saisie à l'étape Club). Chaque ligne peut être décochée ; tout reste
 * modifiable ensuite dans Administration → Catégories.
 */

$presets = require __DIR__ . '/../includes/categories_presets.php';

// Activité du club saisie à l'étape précédente
$clubManager = new ClubManager();
$club = $clubManager->findParameters();
$activite = $club['activite'] ?? '';

// Liste adaptée à l'activité (Escalade par défaut si inconnue)
$categories = $presets[$activite] ?? $presets['Escalade'];

$form_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [#107] CSRF : rejette les POST forgés cross-site.
    if (!setup_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $form_errors[] = 'Erreur de sécurité : jeton CSRF invalide ou expiré. Rechargez la page et réessayez.';
    }

    if (empty($form_errors)) {
        $selection = $_POST['sel'] ?? [];
        if (is_array($selection)) {
            $categorieManager = new CategorieManager();
            foreach ($selection as $index => $on) {
                $index = (int) $index;
                if (!isset($categories[$index])) {
                    continue; // index forgé → ignoré
                }
                $categorie = $categories[$index];
                // Idempotent : ne pas créer de doublon si la catégorie
                // existe déjà (ex. ré-exécution de l'étape).
                if ($categorieManager->findOneByCriteria(['libelle' => $categorie['libelle']])) {
                    continue;
                }
                $categorieManager->save($categorie);
            }
        }

        header('Location: ?step=final');
        exit();
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Catégories d'équipements</h1>
<hr>

<?php if (!empty($form_errors)): ?>
    <div class="alert alert-danger">
        <ul>
            <?php foreach ($form_errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p>
    Voici une liste de catégories préparée pour l'activité
    <strong><?= htmlspecialchars($activite !== '' ? $activite : 'Escalade') ?></strong>.
    Décochez celles dont vous n'avez pas besoin — vous pourrez toutes les modifier
    ou en ajouter ensuite dans <em>Administration → Catégories</em>.
</p>

<form method="post">
    <table class="table table-striped" style="max-width: 60rem;">
        <thead>
            <tr>
                <th></th>
                <th>Libellé</th>
                <th>Code</th>
                <th>EPI ?</th>
                <th>Durée max ?</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $i => $categorie): ?>
                <tr>
                    <td>
                        <input type="checkbox" class="form-check-input"
                               name="sel[<?= $i ?>]" id="sel_<?= $i ?>" checked>
                    </td>
                    <td><label for="sel_<?= $i ?>" class="form-check-label"><?= htmlspecialchars($categorie['libelle']) ?></label></td>
                    <td><code><?= htmlspecialchars($categorie['code']) ?></code></td>
                    <td><?= $categorie['est_epi'] ? 'Oui' : 'Non' ?></td>
                    <td><?= $categorie['duree_max_utilisation'] ? 'Oui' : 'Non' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <button type="submit" class="btn btn-primary">Créer les catégories sélectionnées</button>
    <a href="?step=final" class="btn btn-secondary">Passer cette étape</a>
    <?= setup_csrf_field() ?>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
