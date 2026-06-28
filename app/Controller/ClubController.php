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
    
    public function show(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_ADMIN');
        
        $clubManager = new ClubManager();
        $club = $clubManager->findParameters();
        $form_errors = [];
        
        if ($request->getMethod() === 'POST') {
            // Récupération des données du formulaire
            $club['nom'] = trim($request->request->get('nom'));
            $club['activite'] = trim($request->request->get('activite'));
            $club['description'] = trim($request->request->get('description'));
            $club['email'] = trim($request->request->get('email'));
            $club['phone'] = trim($request->request->get('phone'));
            
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
                
                // Message flash de succès (à adapter si votre système en utilise)
                // $this->addFlash('success', 'Les informations du club ont été mises à jour.');
                
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