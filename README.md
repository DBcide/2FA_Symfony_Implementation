# Documentation 2FA - Symfony

---

<div style="display: flex; align-items: center; gap: 10px;">

  <a href="https://github.com/DBcide">
    <img src="https://avatars.githubusercontent.com/DBcide?s=100" alt="Profil GitHub" style="border-radius:50%; width:60px; height:60px;">
  </a>

  <h2 style="margin: 0;">
    Make by <a href="https://github.com/DBcide">DBcide</a>
  </h2>

</div>

![Framework](https://img.shields.io/badge/Framework-Symfony-000000?logo=symfony&logoColor=white)
![Symfony CLI](https://img.shields.io/badge/Symfony_CLI-5.12.0-brightgreen?logo=symfony)
![PHP CLI](https://img.shields.io/badge/PHP_CLI-8.4.8-777bb4?logo=php&logoColor=white)
![Last commit](https://img.shields.io/github/last-commit/DBcide/2FA_Symfony_Implementation?logo=github&logoColor=white)

---

## Installation

### 1.1 Installer le bundle 2FA

``` bash
composer require scheb/2fa-bundle
composer require scheb/2fa-google-authenticator
```

### 1.2 Installer qr code bundle

``` bash
composer require endroid/qr-code-bundle
```

### 2. Configuration de la base de données

Ajouter à l'entité User (`src/Entity/User.php`):

``` php
use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;

class User implements UserInterface, TwoFactorInterface
{
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $googleAuthenticatorSecret;

    public function isGoogleAuthenticatorEnabled(): bool
    {
        return null !== $this->googleAuthenticatorSecret;
    }

    public function getGoogleAuthenticatorUsername(): string
    {
        return $this->email;
    }

    public function getGoogleAuthenticatorSecret(): ?string
    {
        return $this->googleAuthenticatorSecret;
    }

    public function setGoogleAuthenticatorSecret(?string $secret): void
    {
        $this->googleAuthenticatorSecret = $secret;
    }
}
```

Créer la migration:

``` bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

### 3. Configuration du bundle

`config/packages/scheb_2fa.yaml`:

``` yaml
scheb_two_factor:
    security_tokens:
        - Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken
        - Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken
    google:
        enabled: true
        server_name: Votre_App
        issuer: Nom_Émetteur
        leeway: 15
```

### 4. Configuration des routes

`config/routes/scheb_2fa.yaml`:

``` yaml
2fa_login:
    path: /2fa
    defaults:
        _controller: "scheb_two_factor.form_controller::form"

2fa_login_check:
    path: /2fa_check
```

## Code source

### SecurityController

`src/Controller/SecurityController.php`:

``` php
#[Route(path: '/enable_2fa', name: 'app_enable_2fa')]
public function enable_2fa(
    Request $request,
    EntityManagerInterface $em,
    GoogleAuthenticatorInterface $googleAuthenticator,
    SessionInterface $session
): Response {
    // Récupérer l'utilisateur
    $user = $this->getUser();
    
    // Vérifier si 2FA déjà activé
    if ($user->isGoogleAuthenticatorEnabled()) {
        return $this->redirectToRoute('app_login');
    }

    // Générer le secret
    $secret = $session->get('2fa_temp_secret');
    if (!$secret) {
        $secret = $googleAuthenticator->generateSecret();
        $session->set('2fa_temp_secret', $secret);
    }

    // Traitement du formulaire
    $form = $this->createForm(Enable2faFormType::class);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $code = $form->getData()['code'];
        
        // Valider le code
        $tempUser = clone $user;
        $tempUser->setGoogleAuthenticatorSecret($secret);
        
        if ($googleAuthenticator->checkCode($tempUser, $code)) {
            $user->setGoogleAuthenticatorSecret($secret);
            $em->persist($user);
            $em->flush();
            $session->remove('2fa_temp_secret');
            return $this->redirectToRoute('app_login');
        }
    }

    // Générer QR Code
    $qrCodeContent = sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s',
        urlencode('Mon App'),
        urlencode($user->getEmail()),
        $secret,
        urlencode('Mon App')
    );

    return $this->render('security/enable_2fa.html.twig', [
        'form' => $form->createView(),
        'secret' => $secret,
        'qr_code_content' => $qrCodeContent
    ]);
}
```

### Templates

`templates/security/enable_2fa.html.twig`:

``` twig
{% extends 'base.html.twig' %}

{% block body %}
    <img src="{{ qr_code_data_uri(qr_code_content) }}" alt="QR Code 2FA" />
    <p>Secret: {{ secret }}</p>

    {{ form_start(form) }}
        {{ form_widget(form) }}
        <button type="submit">Activer 2FA</button>
    {{ form_end(form) }}
{% endblock %}
```

## Utilisation

1.  L'utilisateur se connecte normalement\
2.  Il accède à `/enable_2fa`\
3.  Il scanne le QR code avec Google Authenticator\
4.  Il entre le code généré pour activer le 2FA\
5.  À la prochaine connexion, le 2FA sera requis

## Sécurité

-   Le secret temporaire est stocké en session\
-   Validation du code avant activation\
-   Le secret final est stocké de manière sécurisée en base de données\
-   Protection CSRF activée

---

# Documentation : Système de réinitialisation de mot de passe (Symfony)

Ce projet utilise le **Symfony ResetPasswordBundle** pour gérer le processus complet de réinitialisation des mots de passe.

---

## 📌 Entité : `ResetPasswordRequest`

Cette entité représente une **demande de réinitialisation de mot de passe**.  
Elle stocke :
- L’utilisateur associé à la demande (`user`)
- Le **token** de réinitialisation généré (unique et temporaire)
- La date/heure d’expiration du token
- La date/heure de création de la demande

👉 En résumé : elle permet de lier **un utilisateur** et **une demande de reset de mot de passe** valide dans le temps.

---

## 📌 Contrôleur : `ResetPasswordController`

Le contrôleur gère le flux complet :

### 1. `request()`
- Affiche le formulaire `ForgotPasswordRequestForm`.
- L’utilisateur entre son adresse e-mail.
- Si elle existe dans la base :
    - Génère un `ResetPasswordRequest` (token + date d’expiration).
    - Envoie un **email de réinitialisation** contenant un lien avec le token.

👉 Le lien ressemble à :  
`https://monsite/reset-password/{token}`

---

### 2. `checkEmail()`
- Affiche une page confirmant que **si l’email existe**, un message a été envoyé.
- Cette étape permet d’éviter de révéler si une adresse est enregistrée ou non.

---

### 3. `reset()`
- Vérifie que le **token** est valide et non expiré.
- Affiche le formulaire `ChangePasswordForm` (champs : nouveau mot de passe + confirmation).
- Une fois soumis et validé :
    - Met à jour le mot de passe de l’utilisateur (hashé).
    - Supprime la demande de reset (`ResetPasswordRequest`).
    - Redirige l’utilisateur (ex. vers la page de connexion).

---

## 📌 Formulaires

### `ForgotPasswordRequestForm`
- Contient **un seul champ email**.
- Validé par Symfony Validator (`NotBlank`, `Email`).
- Déclenche la génération du token.

### `ChangePasswordForm`
- Contient **deux champs mot de passe** (nouveau mot de passe + confirmation).
- Validation :
    - Les deux mots de passe doivent correspondre.
    - Contrôles supplémentaires via `PasswordHasher` et les contraintes définies.

---

## 📌 Validation de la robustesse du mot de passe

La vérification de la robustesse du mot de passe se fait via :

- **Symfony Validator** sur le champ `plainPassword` dans l’entité `User`.  
  Exemple courant dans `User.php` :
  ```php
  #[Assert\NotBlank]
  #[Assert\Length(min: 12)]
  #[PasswordStrength]
  ```

- Le bundle peut aussi intégrer **zxcvbn** (bibliothèque de calcul de force de mot de passe).
- Des messages d’erreur clairs sont affichés dans le formulaire si :
    - Le mot de passe est trop court.
    - Il est trop faible.
    - Il a été compromis (détection via HaveIBeenPwned).

---

## 🔑 Résumé du flux utilisateur

1. L’utilisateur demande une réinitialisation → saisit son email.
2. Le système génère un token et envoie un email avec un lien.
3. L’utilisateur clique sur le lien → saisit un nouveau mot de passe.
4. Symfony valide le mot de passe (longueur, force, sécurité).
5. Le mot de passe est enregistré (haché) et l’utilisateur peut se reconnecter.

---

## 📂 Où regarder dans le code ?

- **Entity** → `src/Entity/ResetPasswordRequest.php`
- **Controller** → `src/Controller/ResetPasswordController.php`
- **Forms** →
    - `src/Form/ForgotPasswordRequestFormType.php`
    - `src/Form/ChangePasswordFormType.php`
- **Validation mot de passe** → `src/Entity/User.php` (contraintes `Assert`)  
