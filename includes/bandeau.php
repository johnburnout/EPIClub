<?php
    if ($isLoggedIn) { 
        $select = [];
        if ($_SESSION['dev']) {
            
            $_SESSION['dev'] = (!isset($_SESSION['dev']) || empty($_SESSION['dev'])) ? 1 : 0;
            
            $select[] = '<form method="post">';
            $select[] = '<input type="hidden" name="dev" value="'.($_SESSION['dev'] == "1" ? "0" : "1").'">';
            $select[] = '<input type="submit" name="but" class="btn btn-secondary" value="'.($_SESSION['dev'] == "1" ? "Utilisation" : "Développement").'">';
            $select[] = '<input type="hidden" name="csrf_token" value="'.$_SESSION['csrf_token'].'">';
            $select[] = '</form>';
            $choix_select = implode('', $select);
            
            if (isset($_POST['dev'])) {
                $_SESSION['dev'] = $_POST['dev'] ?? '1';
                header("Refresh:0"); // Recharge la page pour prendre en compte le nouveau cookie
                exit;
            }
        }
    }
?>

<?php if ($isLoggedIn): ?>
<form action="index.php" method="post" style="display: inline;">
    <?= $connect ?? '' ?>
    <button type="submit" name="deconnexion" class="btn btn-link">Déconnexion</button>
	<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
</form>
<?= $choix_select ?? '' ?>
<?php else: ?>
<form action="login.php" method="post" style="display: inline;">
<a href="login.php" ><input type="button" name="connexion" value="Connexion" class="btn btn-primary" /></a>
</form>
<?php endif; ?>