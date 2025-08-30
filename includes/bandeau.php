<?php if ($isLoggedIn): ?>
    <form action="logout.php" method="post" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
        <button type="submit" class="btn btn-link">Déconnexion</button>
    </form>
<?php endif; ?>