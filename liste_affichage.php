<?php

	require __DIR__ . '/config.php';		  // Fichier de configuration principal
	require __DIR__.'/includes/communs.php';  // Fonctions communes
	
	// Vérification des permissions
	// Validation CSRF
	if (!$csrf_token || $csrf_token !== $_SESSION['csrf_token']) {
    	throw new Exception('Erreur de sécurité: Token CSRF invalide');
	}

	if (!$isLoggedIn) {
    	header('Location: index.php?');
    	exit();
	}
	
	// ##############################################
	// CONNEXION À LA BASE DE DONNÉES AVEC GESTION D'ERREURS
	// ##############################################
	
	// Configuration du rapport d'erreurs MySQLi
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	
	try {
		// Création de la connexion MySQLi
		$connection = new mysqli($host, $username, $password, $dbname);
		
		// Définition du charset pour supporter tous les caractères (y compris émojis)
		$connection->set_charset("utf8mb4");
	} catch (mysqli_sql_exception $e) {
		// En production, vous pourriez logger cette erreur et afficher un message générique
		die("Erreur de connexion à la base de données: " . $e->getMessage());
	}
		
	// ##############################################
	// GESTION DE LA PAGINATION ET DES FILTRES
	// ##############################################
	// Valeurs par défaut pour les paramètres
	$defaults = [
		'debut' => (int)$_POST['debut'] ?? 1,			// Première ligne à afficher
		'long' => (int)$_POST['long'] ?? 10,			// Nombre de lignes par page
		'lieu_id' => (int)$_POST['lieu_id'] ?? 0,		  // Filtre lieu (0 = tous)
		'cat_id' => (int)$_POST['cat_id'] ?? 0,		   // Filtre catégorie (0 = tous)
		'tri' => (string)$_POST['tri'] ?? 'id',		   // Champ de tri par défaut
		'est_en_service' => 1  // Filtre "en service" par défaut (1 = oui)
	];
	
	// Traitement des paramètres de requête
	$params = [];
	foreach ($defaults as $key => $default) {
		if (isset($_POST[$key])) {
			// Nettoie l'entrée selon son type (int ou string)
			$params[$key] = sanitizeInput($_POST[$key], is_numeric($default) ? 'int' : 'string');
		} else {
				// Utilise la valeur par défaut si le paramètre n'est pas fourni
				$params[$key] = $default;
		}
	}
	// Gestion des cookies pour conserver les préférences utilisateur
	// Récupère les préférences depuis les cookies ou utilise les valeurs par défaut
		$params['debut'] = $_SESSION['debut'] ?? $defaults['debut'];
		$params['long'] = $_SESSION['long'] ?? $defaults['long'];
		$params['nblignes'] = $_SESSION['nblignes'] ?? $defaults['nblignes'];
	
	// ##############################################
	// CONSTRUCTION DE LA REQUÊTE SQL SÉCURISÉE
	// ##############################################
	
	$whereClauses = [];  // Conditions WHERE
	$queryParams = [];   // Paramètres pour la requête préparée
	$types = '';		 // Types des paramètres (i = integer, s = string)
	
	// Construction dynamique de la clause WHERE
	if ($params['lieu_id'] > 0) {
		$whereClauses[] = "lieu_id = ?";
		$queryParams[] = $params['lieu_id'];
		$types .= 'i';  // Type integer
	}
	
	if ($params['cat_id'] > 0) {
		$whereClauses[] = "categorie_id = ?";
		$queryParams[] = $params['cat_id'];
		$types .= 'i';  // Type integer
	}
	
	// Filtre "en service" (toujours présent)
	$whereClauses[] = "en_service = 1";
	
	$types .= 'ii';
	$queryParams[] = (int)$params['debut'] - 1;
	$queryParams[] = (int)$params['long'];
	
	// Combinaison des conditions WHERE
	$where = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);
	
	// Validation du champ de tri (whitelist)
	$allowedSort = ['id', 'ref', 'lieu_id', 'date_verification', 'fabricant'];
	$sort = in_array($params['tri'], $allowedSort) ? $params['tri'] : 'id';
	
	// Exécution de la requête principale
	try {
		$sql = "SELECT id, ref, libelle, fabricant, categorie, categorie_id, 
		lieu, lieu_id, nb_elements, date_verification, date_max
		FROM liste $where ORDER BY $sort LIMIT ?, ?";
		
		$stmt = $connection->prepare($sql);
		
		// Liaison des paramètres si nécessaire
		if (!empty($queryParams)) {
			$stmt->bind_param($types, ...$queryParams);
		}
		
		// Exécution et récupération des résultats
		$stmt->execute();
		$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

	} catch (mysqli_sql_exception $e) {
		die("Erreur lors de l'exécution de la requête: " . $e->getMessage());
	}
	$connection->close();
?>
<!DOCTYPE html>
<html lang="fr">
	<head>
		<?php include __DIR__.'/includes/head.php';?>
	</head>
	<body>
		<main>
			
			
			<!-- Affichage des résultats -->
			<?php if ($result): ?>
				<h3><?=$site_name?></h3>
			<table width="100%" style="table-layout: fixed; border-collapse: collapse;">
			    <tbody>
			        <?php foreach ($result as $key => $value): ?>
			            <tr style="border-top: 1px solid #000000;">
			                <td colspan="2">Référence : <?= htmlspecialchars($value['ref']) ?></td>
			                <td rowspan="5" style="width: 150px; padding: 5px; vertical-align: middle; text-align: center;">
			                    <?php if (file_exists(__DIR__.'/utilisateur/qrcodes/qrcode'.$value['id'].'_300.png')): ?>
			                        <img src="qrcodes/qrcode<?= htmlspecialchars($value['id']) ?>_300.png" 
			                             style="max-width: 100%; max-height: 150px; display: inline-block;"
			                             alt="QR Code <?= htmlspecialchars($value['ref']) ?>"
			                             title="QR Code pour <?= htmlspecialchars($value['libelle']) ?>">
			                    <?php else: ?>
			                        <div style="width: 150px; height: 150px; background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
			                            QR Code manquant
			                        </div>
			                    <?php endif; ?>
			                </td>
			            </tr>
			            <tr>
			                <td style="vertical-align: middle;"><b><?= htmlspecialchars($value['libelle']) ?></b></td>
			                <td style="vertical-align: middle;">Lieu : <?= htmlspecialchars($value['lieu']) ?></td>
			            </tr>
			            <tr>
			                <td style="vertical-align: middle;"><?= htmlspecialchars($value['fabricant']) ?></td>
			                <td style="vertical-align: middle;"><?= htmlspecialchars($value['categorie']) ?></td>
			            </tr>
			            <tr>
			                <td style="vertical-align: middle;">Nombre d'éléments :</td>
			                <td style="vertical-align: middle;"><?= htmlspecialchars($value['nb_elements']) ?></td>
			            </tr>
			            <tr>
			                <td colspan="2" style="vertical-align: middle;">Date max : <?= htmlspecialchars($value['date_max']) ?></td>
			            </tr>
			        <?php endforeach; ?>
			    </tbody>
			</table>
			<?php else: ?>
			<p>Aucune fiche trouvée !</p>
		<?php endif; ?>
		</main>
		 <footer>
			<?php include __DIR__.'/includes/bandeau_bas.php'; ?>
		 </footer>
	</body>
</html>