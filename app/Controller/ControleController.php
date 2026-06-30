<?php

namespace Epiclub\Controller;

use Epiclub\Domain\ControleManager;
use Epiclub\Domain\ControleLigneManager;
use Epiclub\Domain\EquipementManager;
use Epiclub\Domain\UtilisateurManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ControleController extends AbstractController
{
    public function list(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $controleManager = new ControleManager();
        $controles = $controleManager->findAll();

        return $this->render('controle_list.twig', [
            'controles' => $controles
        ]);
    }

    public function create(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $form_errors = [];

        if ($request->getMethod() === 'POST') {
            $libelle = trim($request->request->get('libelle'));
            $date_debut = $request->request->get('date_debut');
            $controleur_id = $request->request->get('controleur_id');

            if (empty($libelle)) $form_errors['libelle'] = 'Le libellé est obligatoire.';
            if (empty($date_debut)) $form_errors['date_debut'] = 'La date de début est obligatoire.';
            if (empty($controleur_id)) $form_errors['controleur_id'] = 'Le contrôleur est obligatoire.';

            if (empty($form_errors)) {
                $controle = [
                    'libelle' => $libelle,
                    'date_debut' => $date_debut,
                    'statut' => 'ouvert',
                    'controleur_id' => $controleur_id,
                    'cree_par' => $this->session->get('user')['id'],
                    'hash_remarques' => null
                ];
                $controleManager = new ControleManager();
                $id = $controleManager->save($controle);
                return $this->redirectTo("/admin/controles/edit/$id");
            }
        }

        $utilisateurManager = new UtilisateurManager();
        $controleurs = $utilisateurManager->findAll(); // à filtrer sur ROLE_CONTROLLEUR ou ROLE_ADMIN

        return $this->render('controle_form.twig', [
            'controleurs' => $controleurs,
            'form_errors' => $form_errors,
            'controle' => ['libelle' => '', 'date_debut' => date('Y-m-d\TH:i')]
        ]);
    }

    public function edit(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $id = $request->get('id');
        $controleManager = new ControleManager();
        $controle = $controleManager->findId($id);

        if (!$controle) {
            return $this->redirectTo('/admin/controles');
        }

        // Si le contrôle est clôturé, on bloque la modification
        if ($controle['statut'] === 'cloture') {
            $this->session->getFlashBag()->add('error', 'Ce contrôle est clôturé et ne peut plus être modifié.');
            return $this->redirectTo('/admin/controles');
        }

        $ligneManager = new ControleLigneManager();
        $lignes = $ligneManager->findByControle($id);

        return $this->render('controle_edit.twig', [
            'controle' => $controle,
            'lignes' => $lignes
        ]);
    }

    public function addEquipement(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $controle_id = $request->get('controle_id');
        $equipement_id = $request->request->get('equipement_id');

        $controleManager = new ControleManager();
        $controle = $controleManager->findId($controle_id);
        if (!$controle || $controle['statut'] === 'cloture') {
            return $this->redirectTo("/admin/controles/edit/$controle_id");
        }

        $ligneManager = new ControleLigneManager();
        $ligneManager->save([
            'controle_id' => $controle_id,
            'equipement_id' => $equipement_id,
            'remarque' => null,
            'date_controle' => null,
            'statut' => 'a_controler'
        ]);

        return $this->redirectTo("/admin/controles/edit/$controle_id");
    }

    public function updateLigne(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $ligne_id = $request->get('id');
        $ligneManager = new ControleLigneManager();
        $ligne = $ligneManager->findId($ligne_id);

        if (!$ligne) {
            return $this->redirectTo('/admin/controles');
        }

        $controleManager = new ControleManager();
        $controle = $controleManager->findId($ligne['controle_id']);
        if ($controle['statut'] === 'cloture') {
            return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
        }

        if ($request->getMethod() === 'POST') {
            $ligne['remarque'] = $request->request->get('remarque');
            $ligne['date_controle'] = $request->request->get('date_controle');
            $ligne['statut'] = $request->request->get('statut');
            $ligneManager->save($ligne);
            return $this->redirectTo("/admin/controles/edit/{$controle['id']}");
        }

        return $this->render('controle_ligne_form.twig', [
            'ligne' => $ligne
        ]);
    }

    public function cloturer(Request $request)
    {
        $this->deniAccessUnlessGranted('ROLE_CONTROLLEUR');

        $id = $request->get('id');
        $controleManager = new ControleManager();
        $controle = $controleManager->findId($id);

        if (!$controle || $controle['statut'] === 'cloture') {
            return $this->redirectTo('/admin/controles');
        }

        // Récupérer toutes les remarques pour les hasher
        $ligneManager = new ControleLigneManager();
        $lignes = $ligneManager->findByControle($id);
        $remarques = '';
        foreach ($lignes as $ligne) {
            $remarques .= $ligne['remarque'] . '|';
        }
        $hash = hash('sha256', $remarques);

        $controle['statut'] = 'cloture';
        $controle['date_fin'] = date('Y-m-d H:i:s');
        $controle['hash_remarques'] = $hash;
        $controleManager->save($controle);

        $this->session->getFlashBag()->add('success', 'Contrôle clôturé avec succès.');
        return $this->redirectTo('/admin/controles');
    }
}