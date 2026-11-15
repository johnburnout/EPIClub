<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Epiclub\Engine\Traducteur::langue()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= setup_t('Epiclub - Installation') ?></title>
    <link rel="shortcut icon" href="/assets/img/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="/assets/css/cosmo.min.css">
</head>
<body>
    <header class="container mt-3">
        <div class="text-center">
            <hr>
            <img src="/assets/img/EPIClub.png" width="64" alt="<?= setup_t('Logo EPIClub') ?>">
            <span class="h1 text-primary align-middle ps-3">
                <strong><?= setup_t('Gestionnaire EPI') ?></strong>
            </span>
            <hr>
        </div>
        <!-- Pas de navbar pour le setup, mais on peut afficher un message -->
        <?php // [Localisation] Sélecteur de langue (lien GET, sans effet de bord) ?>
        <div class="text-end small mb-2">
            🌐
            <?php foreach (\Epiclub\Engine\Langue::DISPONIBLES as $codeLangue => $nomLangue): ?>
                <?php $parametresLangue = http_build_query(['step' => $step ?? '1', 'lang' => $codeLangue]); ?>
                <?php if ($codeLangue === \Epiclub\Engine\Traducteur::langue()): ?>
                    <strong lang="<?= $codeLangue ?>"><?= htmlspecialchars($nomLangue) ?></strong>
                <?php else: ?>
                    <a href="?<?= htmlspecialchars($parametresLangue) ?>" lang="<?= $codeLangue ?>"><?= htmlspecialchars($nomLangue) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="text-center mb-3">
            <span class="badge bg-warning text-dark"><?= setup_t('Installation en cours') ?></span>
        </div>
    </header>
    <main class="container mt-2">