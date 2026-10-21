<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Epiclub\Engine\MigrationManager;
use Epiclub\Engine\MySqlDatabaseDumper;
use Epiclub\Engine\SauvegardeService;
use Epiclub\Exception\SauvegardeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Sauvegarde / restauration complète (base + fichiers) — SUPER_ADMIN.
 *
 * Parcours :
 *  - « Exporter » : archive générée puis téléchargée (non conservée) ;
 *  - « Restaurer » : téléversement de l'archive → page de vérification
 *    (analyse complète, rien n'est modifié) → confirmation saisie
 *    (« RESTAURER ») → restauration avec filet de sécurité.
 *  Une archive déposée à la main (FTP) dans var/backups/ apparaît aussi
 *  dans la liste : contournement des limites de téléversement de PHP.
 */
class SauvegardeController extends AbstractController
{
    public const MOT_CONFIRMATION = 'RESTAURER';

    // ==================================================================
    // Factories — surchargées en test Unit.
    // ==================================================================

    protected function racineProjet(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function sauvegardeService(): SauvegardeService
    {
        $racine = $this->racineProjet();
        $config = $this->configProvider();

        return new SauvegardeService(
            new MySqlDatabaseDumper($this->databasePdo()),
            [
                'images'  => $this->getPublicPath('images'),
                'uploads' => $racine . '/_storage/uploads',
            ],
            $racine . '/var/backups',
            trim((string) @file_get_contents($racine . '/version.txt')) ?: '0.0.0',
            SauvegardeService::empreinte((string) $config->get('SECRET_KEY', '')),
            $this->migrationsDisponibles(),
        );
    }

    /**
     * Applique les migrations manquantes après la restauration d'une
     * archive provenant d'une version antérieure.
     *
     * @return string[] Migrations jouées.
     */
    protected function migrerBase(): array
    {
        $manager = new MigrationManager($this->databasePdo(), $this->racineProjet() . '/migrations');
        return $manager->migrate();
    }

    protected function databasePdo(): \PDO
    {
        $config = $this->configProvider();
        $hote = (string) $config->get('DB_HOST', '');
        $dsn = 'mysql:dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4';
        if (str_contains($hote, ':')) {
            [$hote, $port] = explode(':', $hote, 2);
            $dsn .= ';port=' . $port;
        }
        $dsn .= ';host=' . $hote;

        return new \PDO($dsn, (string) $config->get('DB_USER'), (string) $config->get('DB_PASS'), [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /** @return string[] */
    private function migrationsDisponibles(): array
    {
        $versions = array_map(
            static fn (string $f) => basename($f, '.sql'),
            glob($this->racineProjet() . '/migrations/*.sql') ?: []
        );
        sort($versions, SORT_STRING);
        return $versions;
    }

    /** Opérations longues : pas de coupure par le temps d'exécution ni par le client. */
    protected function prolongerExecution(): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
    }

    // ==================================================================
    // ACTIONS
    // ==================================================================

    public function index(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $service = $this->sauvegardeService();
        $erreurDossier = null;
        try {
            $service->preparerDossier();
            $service->purgerTeleversements();
        } catch (SauvegardeException $e) {
            $erreurDossier = $e->getMessage();
        }

        return $this->render('sauvegarde.twig', [
            'archives'         => $service->lister(),
            'erreur_dossier'   => $erreurDossier,
            'limite_upload'    => $this->limiteTeleversement(),
            'mot_confirmation' => self::MOT_CONFIRMATION,
        ]);
    }

    public function exporter(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $this->validateCsrf($request);
        $this->prolongerExecution();

        try {
            $chemin = $this->sauvegardeService()->creer(SauvegardeService::TYPE_MANUELLE);
        } catch (SauvegardeException $e) {
            $this->session->getFlashBag()->add('danger', $e->getMessage());
            return $this->redirectTo('/admin/sauvegarde');
        }

        $reponse = new BinaryFileResponse($chemin, 200, ['Content-Type' => 'application/zip']);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($chemin));
        // Export manuel : téléchargé, pas conservé sur le serveur.
        $reponse->deleteFileAfterSend(true);
        return $reponse;
    }

    public function telecharger(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        try {
            $chemin = $this->sauvegardeService()->chemin((string) $request->attributes->get('nom'));
        } catch (SauvegardeException $e) {
            $this->session->getFlashBag()->add('danger', $e->getMessage());
            return $this->redirectTo('/admin/sauvegarde');
        }

        $reponse = new BinaryFileResponse($chemin, 200, ['Content-Type' => 'application/zip']);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($chemin));
        return $reponse;
    }

    public function televerser(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isPostTruncated($request)) {
            $this->session->getFlashBag()->add('danger', sprintf(
                'Archive trop volumineuse pour le serveur (limite : %s). Déposez-la par FTP dans le dossier var/backups/ : elle apparaîtra dans la liste.',
                $this->limiteTeleversement()
            ));
            return $this->redirectTo('/admin/sauvegarde');
        }
        $this->validateCsrf($request);

        $fichier = $request->files->get('archive');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            $message = $fichier instanceof UploadedFile && in_array($fichier->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? sprintf('Archive trop volumineuse pour le serveur (limite : %s). Déposez-la par FTP dans le dossier var/backups/ : elle apparaîtra dans la liste.', $this->limiteTeleversement())
                : 'Aucune archive reçue. Sélectionnez un fichier .zip.';
            $this->session->getFlashBag()->add('danger', $message);
            return $this->redirectTo('/admin/sauvegarde');
        }
        if (strtolower($fichier->getClientOriginalExtension()) !== 'zip') {
            $this->session->getFlashBag()->add('danger', 'Le fichier doit être une archive .zip produite par EPIClub.');
            return $this->redirectTo('/admin/sauvegarde');
        }

        $service = $this->sauvegardeService();
        try {
            $service->preparerDossier();
            $nom = $service->nomTeleversement();
            $fichier->move($service->dossierSauvegardes(), $nom);
        } catch (\Throwable $e) {
            error_log('[SauvegardeController] televerser : ' . $e->getMessage());
            $this->session->getFlashBag()->add('danger', 'L\'archive n\'a pas pu être enregistrée sur le serveur.');
            return $this->redirectTo('/admin/sauvegarde');
        }

        return $this->redirectTo('/admin/sauvegarde/verifier/' . rawurlencode($nom));
    }

    public function verifier(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $this->prolongerExecution();

        $nom = (string) $request->attributes->get('nom');
        $service = $this->sauvegardeService();
        $manifeste = null;
        $erreur = null;
        try {
            $manifeste = $service->analyser($service->chemin($nom));
        } catch (SauvegardeException $e) {
            $erreur = $e->getMessage();
        }

        return $this->render('sauvegarde_verifier.twig', [
            'nom'              => $nom,
            'manifeste'        => $manifeste,
            'erreur'           => $erreur,
            'mot_confirmation' => self::MOT_CONFIRMATION,
        ]);
    }

    public function restaurer(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $this->validateCsrf($request);

        $nom = (string) $request->attributes->get('nom');
        $retour = '/admin/sauvegarde/verifier/' . rawurlencode($nom);

        if (trim((string) $request->request->get('confirmation', '')) !== self::MOT_CONFIRMATION) {
            $this->session->getFlashBag()->add('danger', sprintf(
                'Restauration non lancée : saisissez %s pour confirmer.',
                self::MOT_CONFIRMATION
            ));
            return $this->redirectTo($retour);
        }

        $this->prolongerExecution();
        $service = $this->sauvegardeService();
        try {
            $chemin = $service->chemin($nom);
            $resultat = $service->restaurer($chemin, fn () => $this->migrerBase());
        } catch (SauvegardeException $e) {
            $this->session->getFlashBag()->add('danger', $e->getMessage());
            return $this->redirectTo('/admin/sauvegarde');
        }

        // Une archive téléversée a servi : inutile de la garder.
        if (str_starts_with($nom, 'televersee-')) {
            try {
                $service->supprimer($nom);
            } catch (SauvegardeException) {
                // sans gravité : purgée automatiquement sous 24 h
            }
        }

        $m = $resultat['manifeste'];
        $message = sprintf(
            'Restauration terminée : sauvegarde du %s (version %s), %d fichier(s). L\'état précédent est conservé dans « %s ».',
            date('d/m/Y à H:i', strtotime((string) $m['cree_le']) ?: time()),
            $m['version'],
            $m['nb_fichiers'],
            $resultat['filet']
        );
        if ($resultat['migrations'] !== []) {
            $message .= ' Base mise à niveau (' . implode(', ', $resultat['migrations']) . ').';
        }
        $this->session->getFlashBag()->add('success', $message);

        // Les comptes ont pu changer : si celui de l'utilisateur courant
        // n'existe plus, la session sera fermée à la requête suivante.
        return $this->redirectTo('/admin/sauvegarde');
    }

    public function supprimer(Request $request): Response
    {
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $this->validateCsrf($request);

        $nom = (string) $request->attributes->get('nom');
        try {
            $this->sauvegardeService()->supprimer($nom);
            $this->session->getFlashBag()->add('success', 'Sauvegarde « ' . $nom . ' » supprimée.');
        } catch (SauvegardeException $e) {
            $this->session->getFlashBag()->add('danger', $e->getMessage());
        }
        return $this->redirectTo('/admin/sauvegarde');
    }

    // ------------------------------------------------------------------

    /** Plus petite des deux limites PHP, telle qu'affichée à l'utilisateur. */
    protected function limiteTeleversement(): string
    {
        $upload = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');
        $o = $this->parseIniSize($upload);
        $p = $this->parseIniSize($post);
        if ($p > 0 && ($o <= 0 || $p < $o)) {
            return $post;
        }
        return $upload;
    }
}
