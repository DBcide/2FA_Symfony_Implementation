<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $age = $user->getAge();
        $isSenior = $user->isSenior();
        $category = $user->getSeniorityCategory();

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'age' => $age,
            'isSenior' => $isSenior,
            'category' => $category,
        ]);
    }
}
