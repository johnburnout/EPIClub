<?php
    
    // Inclusion des fichiers de configuration
    require __DIR__.'/config.php';
    require __DIR__."/includes/debug.php";
    require __DIR__."/includes/session.php";
    
    // Initialisation
    $error_message = "";
    $isLoggedIn = isset($_SESSION['user_id']);
    $message = "";
    
    // Traitement de la déconnexion
    if (isset($_POST['deconnexion'])) {
        // Validation CSRF (doit venir avant session_destroy)
        if (!isset($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
            throw new Exception('Erreur de sécurité: Token CSRF invalide');
        }
        
        // Nettoyage complet de la session
        $_SESSION = [];
        
        // Destruction et redirection
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    
    // Traitement du formulaire de connexion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['connexion'])) {
        // Validation des champs
        if (empty($_POST['pseudo']) || empty($_POST['mdp'])) {
            $error_message = "Tous les champs doivent être remplis";
        } else {
            // Nettoyage des entrées
            $pseudo = trim($_POST['pseudo']);
            $mdp = $_POST['mdp'];
            
            try {
                // Connexion sécurisée à la base
                $mysqli = new mysqli($host, $username, $password, $dbname);
                $mysqli->set_charset("utf8mb4");
                
                // Variables pour stocker les IDs max
                $max_facture_id = 0;
                $max_controle_id = 0;
                
                // Recherche de l'ID max avec facture_en_saisie = 1 pour cet utilisateur
                $max_id_stmt = $mysqli->prepare("
    SELECT MAX(id) as max_id 
    FROM facture 
    WHERE en_saisie = 1 
    AND utilisateur = ?
    ");
                $max_id_stmt->bind_param("s", $pseudo);
                $max_id_stmt->execute();
                $max_id_result = $max_id_stmt->get_result();
                
                if ($max_id_result->num_rows === 1) {
                    $max_id_data = $max_id_result->fetch_assoc();
                    $max_facture_id = $max_id_data['max_id'] ?? 0;
                    
                    if ($max_facture_id !== null) {
                        // Mettre à jour les factures avec un ID inférieur
                        $update_stmt = $mysqli->prepare("
    UPDATE facture 
    SET en_saisie = 0 
    WHERE utilisateur = ?
    AND id < ?
    AND en_saisie = 1
    ");
                        $update_stmt->bind_param("si", $pseudo, $max_facture_id);
                        $update_stmt->execute();
                        $update_stmt->close();
                    }
                }
                $max_id_stmt->close();
                
                // Recherche de l'ID max avec controle_en_cours = 1 pour cet utilisateur
                $max_controle_stmt = $mysqli->prepare("
    SELECT MAX(id) as max_id 
    FROM controle 
    WHERE en_cours = 1 
    AND utilisateur = ?
    ");
                $max_controle_stmt->bind_param("s", $pseudo);
                $max_controle_stmt->execute();
                $max_controle_result = $max_controle_stmt->get_result();
                
                if ($max_controle_result->num_rows === 1) {
                    $max_controle_data = $max_controle_result->fetch_assoc();
                    $max_controle_id = $max_controle_data['max_id'] ?? 0;
                    
                    if ($max_controle_id !== null) {
                        // Mettre à jour les contrôles avec un ID inférieur
                        $update_controle_stmt = $mysqli->prepare("
    UPDATE controle 
    SET en_cours = 0 
    WHERE utilisateur = ?
    AND id < ?
    AND en_cours = 1
    ");
                        $update_controle_stmt->bind_param("si", $pseudo, $max_controle_id);
                        $update_controle_stmt->execute();
                        $update_controle_stmt->close();
                    }
                }
                $max_controle_stmt->close();
                
                // Mettre à jour la table utilisateur avec les IDs max trouvés (ou 0 si aucun)
                $update_user_stmt = $mysqli->prepare("
    UPDATE utilisateur 
    SET facture_en_saisie = ?, 
    controle_en_cours = ? 
    WHERE username = ?
    ");
                $update_user_stmt->bind_param("iis", $max_facture_id, $max_controle_id, $pseudo);
                $update_user_stmt->execute();
                $update_user_stmt->close();
                
                // Vérification de l'utilisateur
                $stmt = $mysqli->prepare("
    SELECT id, username, password, role, controle_en_cours, facture_en_saisie, dev 
    FROM utilisateur 
    WHERE username = ? 
    AND est_actif = 1
    LIMIT 1
    ");
                $stmt->bind_param("s", $pseudo);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // Vérification du mot de passe
                    if (password_verify($mdp, $user['password'])) {
                        // Authentification réussie
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['pseudo'] = $user['username'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['last_login'] = time();
                        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                        $_SESSION['controle_en_cours'] = $user['controle_en_cours'];
                        $_SESSION['facture_en_saisie'] = $user['facture_en_saisie'];
                        $_SESSION['dev'] = $user['dev'];
                        $_SESSION['debug'] = 0;
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        
                        // Mise à jour last_login
                        $update_stmt = $mysqli->prepare("UPDATE utilisateur SET last_login = NOW() WHERE id = ?");
                        $update_stmt->bind_param("i", $user['id']);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        // Gestion des rôles admin
                        foreach ($admins as $admin) {
                            if ($_SESSION['pseudo'] === $admin) {
                                $_SESSION['role'] = 'admin';
                                break;
                            }
                        }
                        
                        // Message d'information
                        $avis = [];
                        if ($user['controle_en_cours'] > 0) {
                            $avis[] = "un contrôle en cours";
                        }
                        if ($user['facture_en_saisie'] > 0) {
                            $avis[] = "une facture en saisie";
                        }
                        if (!empty($avis)) {
                            $message = "<br>Vous avez ".implode(" et ", $avis).".";
                        }
                        
                        // Régénération de l'ID de session
                        session_regenerate_id(true);
                        // Redirection
                        header('Location: index.php');
                        exit;
                    }
                }
                
                $error_message = "Identifiants incorrects";
                usleep(random_int(1000000, 3000000));
                
            } catch (mysqli_sql_exception $e) {
                error_log("Login error: ".$e->getMessage());
                $error_message = "Erreur système. Veuillez réessayer.".$e->getMessage();
            } finally {
                if (isset($stmt)) $stmt->close();
                if (isset($mysqli)) $mysqli->close();
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="fr">
    <head>
        <?php include __DIR__.'/includes/head.php';?>
    </head>
    <body>
        <header style="text-align: right; padding: 10px;">
            <?php include __DIR__.'/includes/bandeau.php';?>
        </header>
        <main>
            <?php include __DIR__.'/includes/en_tete.php';?>
            <div class="login-container">
                <?php if (!$isLoggedIn): ?>
                <h3 style="text-align: left;">Connexion à l'espace gestion</h3>
                <p style="text-align: left;">Accès réservé aux membres habilités du club.</p>
                
                <?php if (!empty($error_message)): ?>
                <div class="error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                
                <form action="login.php" method="post">
                    <div>
                        <label for="pseudo">Identifiant :</label>
                        <input type="text" id="pseudo" name="pseudo" required autofocus>
                    </div>
                    
                    <div>
                        <label for="mdp">Mot de passe :</label>
                        <input type="password" id="mdp" name="mdp" required>
                    </div>
                    
                    <div style="text-align: left; margin-top: 20px;">
                        <input class="btn btn-primary" type="submit" name="connexion" value="Se connecter">
                    </div>
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>" />
                </form>
                <div style="margin-top: 20px; text-align: left;">
                    <p>Oubli ou changement de <a href="oubli_mdp.php">mot de passe</a>.</p>
                </div>
                <div style="margin-top: 20px; text-align: left;">
                    <p>Pour obtenir un accès, contactez <a href="mailto:contact@perigord-escalade.fr">l'administrateur</a>.</p>
                </div>
                <?php else: ?>
                <div style="text-align: left;">
                    <p>Vous êtes déjà connecté.</p>
                    <p><a href="index.php">Accéder à l'interface</a></p>
                </div>
                <?php endif; ?>
            </div>
            <div style="text-align: left;">
                <form>			
                    <a href="index.php">
                        <input type="button" class="btn return-btn btn-block"value="Revenir à l'accueil">
                    </a>
                </form>
            </div>
        </main>
        <footer>
            <?php include __DIR__.'/includes/bandeau_bas.php'; ?>
        </footer>
    </body>
</html>