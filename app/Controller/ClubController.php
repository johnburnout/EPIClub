<?php 
namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ClubController extends AbstractController
{
    // Liste des activités (identique à step_club.php)
    const ACTIVITES = [
        'Alpinisme',
        'Escalade',
        'Spéléologie',
        'Canoé-Kayac',
        'Multi-activités'
    ];

    // ==================================================================
    // [REFACTOR VAGUE 11 — issue #63] Factory manager — mockable en
    // test Unit.
    //
    // Retourne une nouvelle instance concrète. Les sous-classes de test
    // (TestableClubController) surchargent cette factory pour injecter
    // un mock et éviter l'ouverture PDO déclenchée par
    // AbstractManager::__construct().
    // ==================================================================

    protected function clubManager(): ClubManager
    {
        return new ClubManager();
    }
    
    public function show(Request $request)
    {
        // ⚠️ Réglages du club : réservé au SUPER_ADMIN
        $this->deniAccessUnlessGranted('ROLE_SUPER_ADMIN');
        
        $clubManager = $this->clubManager();
        $club = $clubManager->findParameters();
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            // [SÉCURITÉ] Vérification CSRF avant tout traitement
            $this->validateCsrf($request);
            
            // Récupération des données du formulaire
            // [SÉCURITÉ #53] Troncature défensive (nom : varchar(64)).
            $club['nom']         = mb_substr(trim($request->request->get('nom')), 0, 64);
            $club['activite']    = trim($request->request->get('activite'));
            $club['description'] = trim($request->request->get('description'));
            $club['email']       = trim($request->request->get('email'));
            $club['phone']       = trim($request->request->get('phone'));
            // [Prêt de matériel — migration 016] Module facultatif.
            $club['pret_actif']  = $request->request->has('pret_actif') ? 1 : 0;
            // [migration 017] Délai avant suppression d'un équipement égaré.
            // Champ absent du POST (ancien formulaire) : valeur inchangée.
            $delai = trim((string) $request->request->get('delai_suppression_egare_mois', ''));
            if (!$request->request->has('delai_suppression_egare_mois')) {
                // rien
            } elseif (!ctype_digit($delai)
                || (int) $delai < \Epiclub\Domain\SuppressionEgare::DELAI_MIN
                || (int) $delai > \Epiclub\Domain\SuppressionEgare::DELAI_MAX
            ) {
                $club['delai_suppression_egare_mois'] = $delai;
                $form_errors['delai_suppression_egare_mois'] = sprintf(
                    'Le délai doit être un nombre de mois entre %d et %d.',
                    \Epiclub\Domain\SuppressionEgare::DELAI_MIN,
                    \Epiclub\Domain\SuppressionEgare::DELAI_MAX
                );
            } else {
                $club['delai_suppression_egare_mois'] = (int) $delai;
            }
            
            // Validation
            if (empty($club['nom'])) {
                $form_errors['nom'] = "Le nom du club est obligatoire.";
            }
            
            if (!empty($club['email']) && !filter_var($club['email'], FILTER_VALIDATE_EMAIL)) {
                $form_errors['email'] = "L'email n'est pas valide.";
            }
            
            // Validation de l'activité (doit être dans la liste)
            if (!empty($club['activite']) && !in_array($club['activite'], self::ACTIVITES)) {
                $form_errors['activite'] = "L'activité sélectionnée n'est pas valide.";
            }
            
            if (empty($form_errors)) {
                $clubManager->save($club);
                
                $this->session->getFlashBag()->add('success', "Les informations du club ont été mises à jour.");
                
                return new RedirectResponse("/admin/club");
            }
        }
        
        return $this->render('club_show.twig', [
            'club' => $club,
            'activites' => self::ACTIVITES,
            'form_errors' => $form_errors
        ]);
    }
}