<?php
    declare(strict_types=1);

/**
 * Écrit dans un fichier en le créant automatiquement si nécessaire
 * 
 * @param array $fichier Tableau contenant:
 *              - 'chemin' : Chemin complet du fichier (obligatoire)
 *              - 'texte'  : Contenu à écrire (obligatoire)
 *              - 'mode'   : Mode d'ouverture (optionnel, défaut: 'ab')
 * @param bool $ajouterRetourLigne Ajoute un saut de ligne à la fin si true
 * @return string Message de succès
 * @throws InvalidArgumentException|RuntimeException En cas d'erreur
 */

function fichier_ecrire(array $fichier, bool $ajouterRetourLigne = true): string
{
    // Validation des paramètres
    if (!isset($fichier['chemin'], $fichier['texte'])) {
        throw new InvalidArgumentException('Paramètres requis: "chemin" et "texte"');
    }

    $chemin = trim($fichier['chemin']);
    $mode = $fichier['mode'] ?? 'ab'; // Mode binaire par défaut
    $texte = $fichier['texte'];

    if (empty($chemin)) {
        throw new InvalidArgumentException('Le chemin du fichier ne peut être vide');
    }

    // Préparation du contenu
    $contenu = $texte . ($ajouterRetourLigne ? PHP_EOL : '');

    // Vérification/création du répertoire parent
    $repertoire = dirname($chemin);
    if (!is_dir($repertoire)) {
        if (!mkdir($repertoire, 0755, true)) {
            throw new RuntimeException("Impossible de créer le répertoire: $repertoire");
        }
    }
    // Opération d'écriture atomique
    $handle = null;
    $verrouObtenu = false;

    try {
        // Mode 'c+' pour créer le fichier s'il n'existe pas sans tronquer
        $handle = fopen($chemin, 'cb+');
        if ($handle === false) {
            throw new RuntimeException("Impossible de créer/ouvrir le fichier: $chemin");
        }

        // Verrouillage exclusif avec timeout
        $timeout = 5; // secondes
        $debut = time();
        while (!flock($handle, LOCK_EX | LOCK_NB, $wouldblock)) {
            if ($wouldblock && (time() - $debut) < $timeout) {
                usleep(100000); // 100ms
                continue;
            }
            throw new RuntimeException("Timeout de verrouillage pour: $chemin");
        }
        $verrouObtenu = true;

        // Positionnement en fin de fichier pour append
        if (fseek($handle, 0, SEEK_END) === -1) {
            throw new RuntimeException("Imposition de se positionner en fin de fichier");
        }

        // Écriture
        $bytesWritten = fwrite($handle, $contenu);
        if ($bytesWritten === false || $bytesWritten !== strlen($contenu)) {
            throw new RuntimeException("Écriture incomplète dans: $chemin");
        }

        // Synchronisation sur disque
        if (!fflush($handle)) {
            throw new RuntimeException("Échec de synchronisation sur disque");
        }

        return "Écriture réussie ($bytesWritten octets)";

    } catch (Throwable $e) {
        throw new RuntimeException("Erreur d'écriture: " . $e->getMessage(), 0, $e);
    } finally {
        // Libération garantie des ressources
        if ($verrouObtenu && $handle) {
            flock($handle, LOCK_UN);
        }
        if ($handle) {
            fclose($handle);
        }
    }
}
    
/**
 * Lit le contenu d'un fichier de manière sécurisée et remplace les <br> par des retours à la ligne
 * 
 * @param array $fichier Doit contenir ['chemin' => string]
 * @param int $tailleMax Taille maximale autorisée (en octets)
 * @param bool $remplacerBr Remplacer les <br> par des retours chariot (true par défaut)
 * @return string Contenu du fichier traité
 * @throws InvalidArgumentException Si les paramètres sont invalides
 * @throws RuntimeException Si l'opération de lecture échoue
 */

function fichier_lire(array $fichier, int $tailleMax = 1048576, bool $remplacerBr = true): string
{
    // Validation des paramètres
    if (!isset($fichier['chemin'])) {
        throw new InvalidArgumentException('Le tableau doit contenir la clé "chemin"');
    }
    
    $chemin = trim($fichier['chemin']);
    
    // Vérification du fichier
    if (!file_exists($chemin)) {
        throw new RuntimeException("Le fichier $chemin n'existe pas");
    }
    
    if (!is_readable($chemin)) {
        throw new RuntimeException("Le fichier $chemin n'est pas accessible en lecture");
    }
    
    // Vérification de la taille
    $taille = filesize($chemin);
    if ($taille > $tailleMax) {
        throw new RuntimeException("Le fichier $chemin dépasse la taille maximale autorisée ($tailleMax octets)");
    }
    
    // Lecture du contenu
    try {
        $contenu = file_get_contents($chemin, false, null, 0, $tailleMax);
        if ($contenu === false) {
            throw new RuntimeException("Échec de la lecture du fichier $chemin");
        }
        
        // Remplacement des <br> et <br /> par des retours à la ligne si demandé
        if ($remplacerBr) {
            $contenu = preg_replace('/<br\s*\/?>/i', "\n", $contenu);
        }
        
        return $contenu;
        
    } catch (Throwable $e) {
        throw new RuntimeException("Erreur lors de la lecture: " . $e->getMessage(), 0, $e);
    }
}