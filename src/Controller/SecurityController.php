<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\Enable2faFormType;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route(path: '/enable_2fa', name: 'app_enable_2fa')]
    public function enable_2fa(
        Request $request,
        EntityManagerInterface $em,
        GoogleAuthenticatorInterface $googleAuthenticator,
        SessionInterface $session
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isGoogleAuthenticatorEnabled()) {
            return $this->redirectToRoute('app_login');
        }

        // Get or generate secret from session (NOT database)
        $secret = $session->get('2fa_temp_secret');
        if (!$secret) {
            $secret = $googleAuthenticator->generateSecret();
            $session->set('2fa_temp_secret', $secret);
        }

        $error = null;
        $codeValidated = false;

        $form = $this->createForm(Enable2faFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $code = $data['code'];

            // Create a temporary user object with the session secret for validation
            $tempUser = clone $user;
            $tempUser->setGoogleAuthenticatorSecret($secret);

            if ($googleAuthenticator->checkCode($tempUser, $code)) {
                $codeValidated = true;

                // NOW we save the secret to database and enable 2FA
                $user->setGoogleAuthenticatorSecret($secret);
                $em->persist($user);
                $em->flush();

                // Clean up the session
                $session->remove('2fa_temp_secret');

                $this->addFlash('success', '2FA has been enabled on your account.');
                return $this->redirectToRoute('app_login');
            } else {
                $error = 'Code invalide, veuillez réessayer.';
            }
        }

        // Generate the Google Authenticator URL for QR code
        $appName = 'Mon Application'; // Remplacez par le nom de votre app
        $userEmail = $user->getEmail(); // ou $user->getUsername()

        $qrCodeContent = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            urlencode($appName),
            urlencode($userEmail),
            $secret,
            urlencode($appName)
        );

        return $this->render('security/enable_2fa.html.twig', [
            'form' => $form->createView(),
            'secret' => $secret,
            'qr_code_content' => $qrCodeContent,
            'error' => $error,
            'codeValidated' => $codeValidated,
        ]);
    }
}
