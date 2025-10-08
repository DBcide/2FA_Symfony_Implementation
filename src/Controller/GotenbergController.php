<?php

namespace App\Controller;

use Sensiolabs\GotenbergBundle\GotenbergPdfInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GotenbergController extends AbstractController
{
    #[Route('/gotenberg', name: 'app_gotenberg')]
    public function index(
        GotenbergPdfInterface $gotenberg,
    ): Response
    {
        return $gotenberg->html()->content('gotenberg/index.html.twig', [
            'controller_name' => 'GotenbergController',
        ])->generate()->stream();
    }
}
