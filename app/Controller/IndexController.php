<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class IndexController extends AbstractController
{
    public function index(Request $request): Response
    {
        return $this->redirectTo('/tableau_de_bord');
    }

    public function dashboard(Request $request): Response
    {
        if (!$this->isAuthenticated()) {
           return $this->redirectTo('/se_connecter');
        }

        return $this->render('tableau_de_bord.twig', []);
    }

}
