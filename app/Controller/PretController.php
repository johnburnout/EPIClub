<?php

declare(strict_types=1);

namespace Epiclub\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use Epiclub\Domain\CategorieManager;
use Epiclub\Domain\ClubManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\PretLigneManager;
use Epiclub\Domain\PretManager;
use Epiclub\Domain\PretRegles;
use Epiclub\Engine\AbstractController;
use Epiclub\Engine\Pagination;
use Epiclub\Enum\EquipementStatut;
use Epiclub\Enum\PretEtatRetour;
use Epiclub\Enum\PretStatut;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestionnaire de prêt de matériel (module facultatif — migration 016,
 * activé sur la page Club).
 *
 * Cycle d'une fiche :
 *   saisie   → emprunteur modifiable, ajout / retrait de matériel (liste,
 *              scan QR, liste ou fiche des équipements) ;
 *   en_cours → validation : matériel « Prêté », signature de
 *              l'emprunteur (écran du téléphone ou papier, sur le PDF) ;
 *              seul le téléphone reste modifiable ;
 *   termine  → retour : chaque matériel est vérifié et repasse
 *              Disponible, En maintenance ou Égaré (définitif).
 *
 * Accès : ROLE_CONTROLLEUR (et au-dessus).
 */
class PretController extends AbstractController
{

    // ==================================================================
    // Factories — mockables en test Unit (pattern Vague 11)
    // ==================================================================

    protected function pretManager(): PretManager
    {
        return new PretManager();
    }

    protected function pretLigneManager(): PretLigneManager
    {
        return new PretLigneManager();
    }

    protected function equipementManager(): EquipementManager
    {
        return new EquipementManager();
    }

    protected function categorieManager(): CategorieManager
    {
        return new CategorieManager();
    }

    protected function clubManager(): ClubManager
    {
        return new ClubManager();
    }

    protected function aujourdhui(): string
    {
        return date('Y-m-d');
    }

    // ==================================================================
    // Garde commune
    // ==================================================================

    /**
     * Rôle contrôleur + module activé. Retourne une redirection si le
     * module est désactivé, null sinon.
     */
    private function garde(): ?Response
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        if (!$this->pretActif()) {
            $this->session->getFlashBag()->add('note', "Le gestionnaire de prêt n'est pas activé (Administration › Club).");
            return $this->redirectTo('/tableau_de_bord');
        }
        return null;
    }

    /**
     * Charge la fiche de l'id de route, ou null (flash posé).
     *
     * @return array<string, mixed>|null
     */
    private function chargerPret(Request $request, string $cle = 'id'): ?array
    {
        $id = $this->getValidId($request, $cle);
        $pret = $id === null ? null : $this->pretManager()->findId($id);
        if (!$pret) {
            $this->session->getFlashBag()->add('error', 'Fiche de prêt introuvable.');
            return null;
        }
        return $pret;
    }

    private function utilisateurId(): ?int
    {
        $user = $this->session->get('user');
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private static function urlFiche(array $pret): string
    {
        return '/prets/pret-' . (int) $pret['id'];
    }

    /**
     * URL de retour interne (évite les redirections ouvertes).
     */
    public static function retourSur(mixed $retour, string $defaut): string
    {
        if (!is_string($retour) || $retour === '' || str_starts_with($retour, '//')
            || !preg_match('#^/[A-Za-z0-9/_\-?=&%.\[\]+]*$#', $retour)
        ) {
            return $defaut;
        }
        return $retour;
    }

    // ==================================================================
    // Liste
    // ==================================================================

    public function list(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }

        $statut = PretStatut::tryFrom((string) $request->query->get('statut', ''));
        $prets = $this->pretManager()->findAllAvecCompteurs($statut?->value);
        $jour = $this->aujourdhui();
        $retardSeul = $request->query->get('retard') === '1';

        foreach ($prets as &$pret) {
            $pret['en_retard'] = PretRegles::estEnRetard($pret, $jour);
        }
        unset($pret);
        if ($retardSeul) {
            $prets = array_values(array_filter($prets, static fn(array $p) => $p['en_retard']));
        }

        // [2026-10-25] Pagination au-delà de 10 éléments
        [$prets, $pagination] = Pagination::paginer($prets, $request);

        return $this->render('pret_list.twig', [
            'pagination' => $pagination,
            'prets' => $prets,
            'statuts' => PretStatut::cases(),
            'filtre_statut' => $statut?->value,
            'filtre_retard' => $retardSeul,
        ]);
    }

    // ==================================================================
    // Création
    // ==================================================================

    public function create(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }

        $jour = $this->aujourdhui();
        $pret = [
            'emprunteur_nom' => '',
            'emprunteur_prenom' => '',
            'emprunteur_telephone' => '',
            'emprunteur_licence' => '',
            'date_pret' => $jour,
            'date_retour_prevue' => date('Y-m-d', strtotime($jour . ' +7 days')),
            'remarques' => null,
            'statut' => PretStatut::SAISIE->value,
        ];
        $form_errors = [];

        if ($request->isMethod('POST')) {
            $this->validateCsrf($request);
            [$data, $form_errors] = PretRegles::lireEmprunteur($request->request->all(), PretStatut::SAISIE);
            $pret = array_merge($pret, $data);
            if ($form_errors === []) {
                $data['statut'] = PretStatut::SAISIE->value;
                $data['cree_par'] = $this->utilisateurId();
                $id = $this->pretManager()->save($data);
                $this->session->getFlashBag()->add('success', 'Fiche de prêt créée : ajoutez maintenant le matériel.');
                return $this->redirectTo('/prets/pret-' . (int) $id);
            }
        }

        return $this->render('pret_form.twig', [
            'pret' => $pret,
            'form_errors' => $form_errors,
        ]);
    }

    // ==================================================================
    // Fiche
    // ==================================================================

    public function show(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $pret = $this->chargerPret($request);
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        $statut = PretStatut::from($pret['statut']);
        $form_errors = [];
        $saisie = $pret;

        // Mise à jour des informations de l'emprunteur (selon le statut).
        if ($request->isMethod('POST') && $statut !== PretStatut::TERMINE) {
            $this->validateCsrf($request);
            [$data, $form_errors] = PretRegles::lireEmprunteur($request->request->all(), $statut);
            $saisie = array_merge($pret, $data);
            if ($form_errors === []) {
                $data['id'] = (int) $pret['id'];
                $this->pretManager()->save($data);
                $this->session->getFlashBag()->add('success', "Informations de l'emprunteur enregistrées.");
                return $this->redirectTo(self::urlFiche($pret));
            }
        }

        $jour = $this->aujourdhui();
        $lignes = $this->pretLigneManager()->findByPret((int) $pret['id']);

        // En saisie : un matériel peut être devenu indisponible depuis
        // son ajout (contrôle, autre prêt validé…).
        if ($statut === PretStatut::SAISIE) {
            foreach ($lignes as &$ligne) {
                $ligne['motif'] = PretRegles::motifIneligibilite(
                    self::equipementDeLigne($ligne),
                    $this->pretLigneManager()->findPretOuvertPourEquipement((int) $ligne['equipement_id'], (int) $pret['id']),
                    $jour
                );
            }
            unset($ligne);
        }

        $nbAVerifier = count(array_filter($lignes, static fn(array $l) => $l['etat_retour'] === null));

        $disponibles = [];
        $pagination = null;
        $recherche = trim((string) $request->query->get('q', ''));
        $categorieId = $request->query->get('categorie');
        $categorieId = is_string($categorieId) && ctype_digit($categorieId) ? (int) $categorieId : null;
        $categories = [];
        if ($statut === PretStatut::SAISIE) {
            $categories = $this->categorieManager()->findAll();
            [$disponibles, $pagination] = $this->equipementsPretables(
                $request,
                array_column($categories, null, 'id'),
                $recherche,
                $categorieId,
                $jour
            );
        }

        // [2026-10-25] Matériel prêté paginé au-delà de 10 lignes (les
        // contrôles ci-dessus portent sur toutes les lignes).
        [$lignesPage, $paginationLignes] = Pagination::paginer($lignes, $request, 'lignes_', '#retour');

        return $this->render('pret_show.twig', [
            'pret' => $saisie,
            'statut' => $statut,
            'lignes' => $lignesPage,
            'nb_lignes' => count($lignes),
            'pagination_lignes' => $paginationLignes,
            'nb_a_verifier' => $nbAVerifier,
            'en_retard' => PretRegles::estEnRetard($pret, $jour),
            'erreurs_validation' => $statut === PretStatut::SAISIE ? self::erreursAffichees($pret, $lignes) : [],
            'disponibles' => $disponibles,
            'pagination' => $pagination,
            'recherche' => $recherche,
            'categorie_id' => $categorieId,
            'categories' => $categories,
            'etats_retour' => PretEtatRetour::cases(),
            'form_errors' => $form_errors,
            'aujourdhui' => $jour,
        ]);
    }

    /**
     * Erreurs de validation + matériel devenu indisponible (le bouton
     * « Valider » est désactivé tant qu'il en reste).
     *
     * @return list<string>
     */
    private static function erreursAffichees(array $pret, array $lignes): array
    {
        $erreurs = PretRegles::erreursValidation($pret, $lignes);
        if (array_filter($lignes, static fn(array $l) => !empty($l['motif'])) !== []) {
            $erreurs[] = 'Retirez le matériel signalé comme indisponible.';
        }
        return $erreurs;
    }

    /**
     * Équipements prêtables (disponibles, hors contrôle, non périmés, sur
     * aucun prêt ouvert), filtrés et paginés.
     *
     * @param array<int, array<string, mixed>> $categories Map id => catégorie
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function equipementsPretables(Request $request, array $categories, string $recherche, ?int $categorieId, string $jour): array
    {
        $dejaPretes = array_flip($this->pretLigneManager()->equipementsDansUnPretOuvert());
        $aiguille = mb_strtolower($recherche);

        $liste = array_values(array_filter(
            $this->equipementManager()->findAll('reference ASC'),
            static function (array $e) use ($dejaPretes, $aiguille, $categorieId, $jour): bool {
                if (isset($dejaPretes[(int) $e['id']]) || PretRegles::motifIneligibilite($e, null, $jour) !== null) {
                    return false;
                }
                if ($categorieId !== null && (int) ($e['categorie_id'] ?? 0) !== $categorieId) {
                    return false;
                }
                return $aiguille === ''
                    || str_contains(mb_strtolower((string) $e['reference']), $aiguille)
                    || str_contains(mb_strtolower((string) $e['libelle']), $aiguille);
            }
        ));

        // [2026-10-25] Pagination commune (au-delà de 10 éléments)
        [$liste, $pagination] = Pagination::paginer($liste, $request, '', '#ajout');
        foreach ($liste as &$e) {
            $e['categorie_libelle'] = $categories[(int) ($e['categorie_id'] ?? 0)]['libelle'] ?? '';
        }
        unset($e);

        return [$liste, $pagination];
    }

    /**
     * Vue « équipement » d'une ligne (champs joints par findByPret).
     *
     * @param array<string, mixed> $ligne
     * @return array<string, mixed>
     */
    private static function equipementDeLigne(array $ligne): array
    {
        return [
            'id' => $ligne['equipement_id'],
            'reference' => $ligne['reference'] ?? '',
            'statut' => $ligne['equipement_statut'] ?? null,
            'controle_en_cours' => $ligne['controle_en_cours'] ?? 0,
            'date_fin_utilisation' => $ligne['date_fin_utilisation'] ?? null,
            'deleted_at' => $ligne['deleted_at'] ?? null,
        ];
    }

    // ==================================================================
    // Ajout / retrait de matériel (prêt en saisie)
    // ==================================================================

    /**
     * Ajoute un ou plusieurs équipements à un prêt en saisie.
     *
     * @return array{ajoutes: list<string>, refus: list<string>}
     */
    private function ajouterEquipements(array $pret, array $ids): array
    {
        $resultat = ['ajoutes' => [], 'refus' => []];
        $jour = $this->aujourdhui();
        $equipements = $this->equipementManager()->findIds($ids);
        $lignes = $this->pretLigneManager();

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $e = $equipements[$id] ?? null;
            if ($e === null) {
                $resultat['refus'][] = "Équipement #{$id} introuvable.";
                continue;
            }
            $autre = $lignes->findPretOuvertPourEquipement($id);
            if ($autre !== null && (int) $autre['id'] === (int) $pret['id']) {
                $resultat['refus'][] = "{$e['reference']} : déjà sur ce prêt.";
                continue;
            }
            $motif = PretRegles::motifIneligibilite($e, $autre, $jour);
            if ($motif !== null) {
                $resultat['refus'][] = $motif;
                continue;
            }
            $lignes->ajouter((int) $pret['id'], $id);
            $resultat['ajoutes'][] = (string) $e['reference'];
        }
        return $resultat;
    }

    public function ajouter(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $this->validateCsrf($request);

        $pret = $this->chargerPret($request, 'pret_id');
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        $retour = self::retourSur($request->request->get('retour'), self::urlFiche($pret));

        if ($pret['statut'] !== PretStatut::SAISIE->value) {
            $this->session->getFlashBag()->add('error', "Ce prêt n'est plus en saisie : plus d'ajout de matériel possible.");
            return $this->redirectTo($retour);
        }

        $ids = $request->request->all('ids');
        $unique = $request->request->get('equipement_id');
        if (is_string($unique) && ctype_digit($unique)) {
            $ids[] = $unique;
        }
        $ids = array_values(array_filter(array_map('strval', $ids), 'ctype_digit'));
        if ($ids === []) {
            $this->session->getFlashBag()->add('error', 'Aucun équipement sélectionné.');
            return $this->redirectTo($retour);
        }

        $resultat = $this->ajouterEquipements($pret, $ids);
        $libellePret = sprintf('prêt n° %d (%s %s)', $pret['id'], $pret['emprunteur_prenom'], $pret['emprunteur_nom']);
        if ($resultat['ajoutes'] !== []) {
            $this->session->getFlashBag()->add('success', sprintf(
                'Ajouté au %s : %s.', $libellePret, implode(', ', $resultat['ajoutes'])
            ));
        }
        foreach ($resultat['refus'] as $refus) {
            $this->session->getFlashBag()->add('error', $refus);
        }

        return $this->redirectTo($retour);
    }

    public function retirer(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $this->validateCsrf($request);

        $ligneId = $this->getValidId($request);
        $ligne = $ligneId === null ? null : $this->pretLigneManager()->findId($ligneId);
        $pret = $ligne ? $this->pretManager()->findId((int) $ligne['pret_id']) : null;
        if (!$ligne || !$pret) {
            return $this->redirectTo('/prets');
        }
        if ($pret['statut'] !== PretStatut::SAISIE->value) {
            $this->session->getFlashBag()->add('error', "Ce prêt n'est plus en saisie : le matériel ne peut plus être retiré.");
            return $this->redirectTo(self::urlFiche($pret));
        }
        $this->pretLigneManager()->delete((int) $ligne['id']);
        $this->session->getFlashBag()->add('success', 'Matériel retiré du prêt.');
        return $this->redirectTo(self::urlFiche($pret));
    }

    // ==================================================================
    // Scan QR (prêt en saisie)
    // ==================================================================

    public function scanner(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $pret = $this->chargerPret($request);
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        if ($pret['statut'] !== PretStatut::SAISIE->value) {
            $this->session->getFlashBag()->add('error', "Ce prêt n'est plus en saisie.");
            return $this->redirectTo(self::urlFiche($pret));
        }
        return $this->render('pret_scan.twig', [
            'pret' => $pret,
            'lignes' => $this->pretLigneManager()->findByPret((int) $pret['id']),
        ]);
    }

    public function scanAjouter(Request $request): JsonResponse
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');
        try {
            $this->validateCsrf($request);
        } catch (\Throwable) {
            return new JsonResponse(['statut' => 'erreur', 'message' => 'Jeton de sécurité invalide, rechargez la page.'], Response::HTTP_FORBIDDEN);
        }
        if (!$this->pretActif()) {
            return new JsonResponse(['statut' => 'erreur', 'message' => "Le gestionnaire de prêt n'est pas activé."], Response::HTTP_FORBIDDEN);
        }

        $pretId = $request->request->get('pret_id');
        $equipementId = $request->request->get('equipement_id');
        if (!is_string($pretId) || !ctype_digit($pretId) || !is_string($equipementId) || !ctype_digit($equipementId)) {
            return new JsonResponse(['statut' => 'erreur', 'message' => 'QR code invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $pret = $this->pretManager()->findId((int) $pretId);
        if (!$pret || $pret['statut'] !== PretStatut::SAISIE->value) {
            return new JsonResponse(['statut' => 'erreur', 'message' => "Ce prêt n'est plus en saisie."]);
        }

        $equipement = $this->equipementManager()->findId((int) $equipementId);
        if (!$equipement) {
            return new JsonResponse(['statut' => 'introuvable', 'message' => 'Équipement non trouvé.']);
        }
        $base = ['reference' => $equipement['reference'], 'libelle' => $equipement['libelle']];

        $resultat = $this->ajouterEquipements($pret, [(int) $equipementId]);
        if ($resultat['ajoutes'] !== []) {
            return new JsonResponse(['statut' => 'ajoute'] + $base);
        }
        $message = $resultat['refus'][0] ?? 'Ajout impossible.';
        return new JsonResponse([
            'statut' => str_contains($message, 'déjà sur ce prêt') ? 'deja' : 'refuse',
            'message' => $message,
        ] + $base);
    }

    // ==================================================================
    // Validation (saisie → en cours) et signature
    // ==================================================================

    /**
     * GET : récapitulatif + cadre de signature. POST : validation du prêt
     * (saisie → en cours), signature facultative ; pour un prêt en cours
     * non signé, enregistre seulement la signature.
     */
    public function valider(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $pret = $this->chargerPret($request);
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        $statut = PretStatut::from($pret['statut']);
        $signatureSeule = $statut === PretStatut::EN_COURS;

        if ($statut === PretStatut::TERMINE || ($signatureSeule && !empty($pret['signature']))) {
            return $this->redirectTo(self::urlFiche($pret));
        }

        $lignes = $this->pretLigneManager()->findByPret((int) $pret['id']);
        if (!$signatureSeule) {
            $erreurs = PretRegles::erreursValidation($pret, $lignes);
            if ($erreurs !== []) {
                foreach ($erreurs as $e) {
                    $this->session->getFlashBag()->add('error', $e);
                }
                return $this->redirectTo(self::urlFiche($pret));
            }
        }

        if ($request->isMethod('POST')) {
            $this->validateCsrf($request);
            [$signature, $erreur] = PretRegles::lireSignature($request->request->get('signature'));
            if ($erreur !== null) {
                $this->session->getFlashBag()->add('error', $erreur);
                return $this->redirectTo(self::urlFiche($pret) . '/valider');
            }

            if ($signatureSeule) {
                if ($signature === null) {
                    $this->session->getFlashBag()->add('error', "Faites signer l'emprunteur dans le cadre avant d'enregistrer.");
                    return $this->redirectTo(self::urlFiche($pret) . '/valider');
                }
                $this->pretManager()->save([
                    'id' => (int) $pret['id'],
                    'signature' => $signature,
                    'date_signature' => date('Y-m-d H:i:s'),
                ]);
                $this->session->getFlashBag()->add('success', "Signature de l'emprunteur enregistrée.");
                return $this->redirectTo(self::urlFiche($pret));
            }

            $refus = $this->validerPret($pret, $lignes, $signature);
            if ($refus !== []) {
                foreach ($refus as $e) {
                    $this->session->getFlashBag()->add('error', $e);
                }
                $this->session->getFlashBag()->add('error', "Le prêt n'a pas été validé : retirez ou remplacez le matériel concerné.");
                return $this->redirectTo(self::urlFiche($pret));
            }
            $this->session->getFlashBag()->add('success', sprintf(
                'Prêt validé : %d matériel(s) passé(s) en « Prêté ».%s',
                count($lignes),
                $signature === null ? " Sans signature à l'écran : imprimez la fiche PDF pour la faire signer." : ''
            ));
            return $this->redirectTo(self::urlFiche($pret));
        }

        return $this->render('pret_valider.twig', [
            'pret' => $pret,
            'lignes' => $lignes,
            'signature_seule' => $signatureSeule,
        ]);
    }

    /**
     * Passage en cours, en transaction : l'éligibilité de chaque matériel
     * est revérifiée sur les lignes verrouillées.
     *
     * @return list<string> Refus (vide = prêt validé)
     */
    private function validerPret(array $pret, array $lignes, ?string $signature): array
    {
        $manager = $this->pretManager();
        $jour = $this->aujourdhui();
        $ids = array_map(static fn(array $l) => (int) $l['equipement_id'], $lignes);

        try {
            $manager->beginTransaction();
            $equipements = $manager->equipementsPourMiseAJour($ids);
            $refus = [];
            foreach ($ids as $id) {
                $e = $equipements[$id] ?? ['id' => $id, 'deleted_at' => 'introuvable'];
                $motif = PretRegles::motifIneligibilite(
                    $e,
                    $this->pretLigneManager()->findPretOuvertPourEquipement($id, (int) $pret['id']),
                    $jour
                );
                if ($motif !== null) {
                    $refus[] = $motif;
                }
            }
            if ($refus !== []) {
                $manager->rollBack();
                return $refus;
            }

            $manager->changerStatutEquipements($ids, EquipementStatut::PRETE->value);
            $data = [
                'id' => (int) $pret['id'],
                'statut' => PretStatut::EN_COURS->value,
                'valide_par' => $this->utilisateurId(),
                'date_validation' => date('Y-m-d H:i:s'),
            ];
            if ($signature !== null) {
                $data['signature'] = $signature;
                $data['date_signature'] = date('Y-m-d H:i:s');
            }
            $manager->save($data);
            $manager->commit();
            return [];
        } catch (\Throwable $e) {
            $manager->rollBack();
            error_log(sprintf('[PretController] Validation du prêt id=%s impossible : %s', $pret['id'], $e->getMessage()));
            return ['Une erreur est survenue pendant la validation. Merci de réessayer.'];
        }
    }

    // ==================================================================
    // Fin du prêt et vérification du retour
    // ==================================================================

    public function terminer(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $this->validateCsrf($request);
        $pret = $this->chargerPret($request);
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        if ($pret['statut'] !== PretStatut::EN_COURS->value) {
            $this->session->getFlashBag()->add('error', 'Seul un prêt en cours peut être terminé.');
            return $this->redirectTo(self::urlFiche($pret));
        }

        $date = trim((string) $request->request->get('date_retour', ''));
        if ($date === '') {
            $date = $this->aujourdhui();
        }
        if (!PretRegles::dateValide($date) || $date < substr((string) $pret['date_pret'], 0, 10)) {
            $this->session->getFlashBag()->add('error', 'Date de retour invalide (elle ne peut précéder la date du prêt).');
            return $this->redirectTo(self::urlFiche($pret));
        }

        $this->pretManager()->save([
            'id' => (int) $pret['id'],
            'statut' => PretStatut::TERMINE->value,
            'date_retour' => $date,
            'termine_par' => $this->utilisateurId(),
            'date_fin' => date('Y-m-d H:i:s'),
        ]);
        $this->session->getFlashBag()->add('success', 'Prêt terminé : vérifiez maintenant chaque matériel rendu.');
        return $this->redirectTo(self::urlFiche($pret) . '#retour');
    }

    /**
     * Vérification du matériel rendu (prêt terminé) : chaque matériel
     * coché passe Disponible, En maintenance ou Égaré. Définitif.
     */
    public function retour(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $this->validateCsrf($request);
        $pret = $this->chargerPret($request);
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        if ($pret['statut'] !== PretStatut::TERMINE->value) {
            $this->session->getFlashBag()->add('error', 'Terminez le prêt avant de vérifier le matériel rendu.');
            return $this->redirectTo(self::urlFiche($pret));
        }

        $etats = $request->request->all('etats');
        $lignes = array_column($this->pretLigneManager()->findByPret((int) $pret['id']), null, 'id');
        $aTraiter = [];
        foreach ($etats as $ligneId => $valeur) {
            $etat = PretEtatRetour::tryFrom((string) $valeur);
            $ligne = $lignes[(int) $ligneId] ?? null;
            if ($etat !== null && $ligne !== null && $ligne['etat_retour'] === null) {
                $aTraiter[] = [$ligne, $etat];
            }
        }
        if ($aTraiter === []) {
            $this->session->getFlashBag()->add('error', 'Choisissez un état pour au moins un matériel.');
            return $this->redirectTo(self::urlFiche($pret) . '#retour');
        }

        $manager = $this->pretManager();
        $nonModifies = [];
        try {
            $manager->beginTransaction();
            $equipements = $manager->equipementsPourMiseAJour(
                array_map(static fn(array $t) => (int) $t[0]['equipement_id'], $aTraiter)
            );
            foreach ($aTraiter as [$ligne, $etat]) {
                $manager->enregistrerRetourLigne((int) $ligne['id'], $etat->value, $this->utilisateurId());
                $e = $equipements[(int) $ligne['equipement_id']] ?? null;
                // Le statut n'est modifié que si l'équipement est encore « Prêté ».
                if ($e !== null && (int) $e['statut'] === EquipementStatut::PRETE->value) {
                    $manager->changerStatutEquipements([(int) $e['id']], $etat->equipementStatut()->value);
                } else {
                    $nonModifies[] = $ligne['reference'];
                }
            }
            $manager->commit();
        } catch (\Throwable $e) {
            $manager->rollBack();
            error_log(sprintf('[PretController] Retour du prêt id=%s impossible : %s', $pret['id'], $e->getMessage()));
            $this->session->getFlashBag()->add('error', "Une erreur est survenue pendant l'enregistrement du retour. Merci de réessayer.");
            return $this->redirectTo(self::urlFiche($pret) . '#retour');
        }

        $restants = count(array_filter($lignes, static fn(array $l) => $l['etat_retour'] === null)) - count($aTraiter);
        $this->session->getFlashBag()->add('success', sprintf(
            '%d matériel(s) vérifié(s).%s',
            count($aTraiter),
            $restants > 0 ? " Reste {$restants} matériel(s) à vérifier." : ' Retour complet.'
        ));
        if ($nonModifies !== []) {
            $this->session->getFlashBag()->add('note', 'Statut inchangé (équipement plus « Prêté ») : ' . implode(', ', $nonModifies) . '.');
        }
        return $this->redirectTo(self::urlFiche($pret) . ($restants > 0 ? '#retour' : ''));
    }

    // ==================================================================
    // Suppression (prêt en saisie)
    // ==================================================================

    public function supprimer(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $this->validateCsrf($request);
        $pret = $this->chargerPret($request);
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        if ($pret['statut'] !== PretStatut::SAISIE->value) {
            $this->session->getFlashBag()->add('error', 'Seule une fiche en saisie peut être supprimée (historique des prêts).');
            return $this->redirectTo(self::urlFiche($pret));
        }
        $this->pretManager()->delete((int) $pret['id']);
        $this->session->getFlashBag()->add('success', 'Fiche de prêt supprimée.');
        return $this->redirectTo('/prets');
    }

    // ==================================================================
    // Export PDF (prêt en cours ou terminé)
    // ==================================================================

    public function pdf(Request $request): Response
    {
        if ($r = $this->garde()) {
            return $r;
        }
        $pret = $this->chargerPret($request);
        if ($pret === null) {
            return $this->redirectTo('/prets');
        }
        $statut = PretStatut::from($pret['statut']);
        if (!$statut->exportable()) {
            $this->session->getFlashBag()->add('error', 'La fiche est exportable en PDF une fois le prêt validé.');
            return $this->redirectTo(self::urlFiche($pret));
        }

        $html = (string) $this->render('pret_pdf.twig', [
            'pret' => $pret,
            'statut' => $statut,
            'lignes' => $this->pretLigneManager()->findByPret((int) $pret['id']),
            'club' => $this->clubManager()->findParameters() ?? [],
            'date_generation' => date('d/m/Y H:i'),
        ])->getContent();

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = (string) $dompdf->output();

        $nom = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $pret['emprunteur_nom']);
        return new Response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="pret_%d_%s.pdf"', $pret['id'], $nom),
            'Content-Length' => (string) strlen($output),
        ]);
    }
}
