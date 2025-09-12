<?php 
namespace Epiclub\Controller;

use Epiclub\Domain\ClubManager;
use Epiclub\Engine\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class ClubController extends AbstractController
{
    public function show(Request $request)
    {
        $clubManager = new ClubManager();
        $club = $clubManager->findParameters();

        return $this->render('club_show.twig', [
            'club' => $club
        ]);
    }
}