<?php

namespace App\Controller;

use App\Entity\MailSettings;
use App\Entity\Organization;
use App\Entity\OrganizationLogo;
use App\Entity\User;
use App\Form\AccountEditType;
use App\Form\AccountType;
use App\Form\MailSettingsType;
use App\Form\OrganizationCreateType;
use App\Form\OrganizationEditType;
use App\Form\OrganizationMailType;
use App\Repository\MailSettingsRepository;
use App\Repository\OrganizationLogoRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\CurrentOrganization;
use App\Service\Notifier;
use App\Service\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des organisations et de leurs comptes, réservée aux super-admins.
 */
#[Route('/admin/organisations')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationLogoRepository $logos,
        private readonly CurrentOrganization $current,
        private readonly SecretBox $secrets,
    ) {
    }

    #[Route('', name: 'admin_organizations')]
    public function index(Request $request, OrganizationRepository $organizations, MailSettingsRepository $mailSettings, Notifier $notifier): Response
    {
        $sender = $notifier->defaultSender();
        $settings = $mailSettings->current() ?? new MailSettings($sender->getName(), $sender->getAddress());

        $senderForm = $this->createForm(MailSettingsType::class, $settings);
        $senderForm->handleRequest($request);

        if ($senderForm->isSubmitted() && $senderForm->isValid()) {
            $this->entityManager->persist($settings);
            $this->entityManager->flush();
            $this->addFlash('success', 'Expéditeur enregistré.');

            return $this->redirectToRoute('admin_organizations');
        }

        $form = $this->createForm(OrganizationCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if (\in_array($data['slug'], Organization::RESERVED_SLUGS, true)) {
                $form->get('slug')->addError(new FormError('Cette adresse est réservée par l\'application.'));
            } elseif (null !== $organizations->findBySlug($data['slug'])) {
                $form->get('slug')->addError(new FormError('Une organisation utilise déjà cette adresse.'));
            } else {
                $organization = (new Organization($data['slug'], $data['name']))->setNotificationEmail($data['notificationEmail']);
                $this->entityManager->persist($organization);
                $this->entityManager->flush();
                $this->addFlash('success', \sprintf('Organisation %s créée.', $organization->getName()));

                return $this->redirectToRoute('admin_organization', ['id' => $organization->getId()]);
            }
        }

        return $this->render('admin/organizations.html.twig', [
            'organizations' => $organizations->findAllByName(),
            'form' => $form,
            'senderForm' => $senderForm,
        ]);
    }

    #[Route('/{id}', name: 'admin_organization', requirements: ['id' => '\d+'])]
    public function show(
        Organization $organization,
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
    ): Response {
        // L'en-tête et les couleurs de l'admin doivent montrer l'organisation qu'on modifie.
        $this->current->switchTo($organization);

        $edit = $this->createForm(OrganizationEditType::class, $organization);
        $edit->handleRequest($request);

        if ($edit->isSubmitted() && $edit->isValid()) {
            $this->applyLogo($organization, $edit);
            $this->entityManager->flush();
            $this->addFlash('success', 'Organisation enregistrée.');

            return $this->redirectToRoute('admin_organization', ['id' => $organization->getId()]);
        }

        $mail = $this->createForm(OrganizationMailType::class, $organization);
        $mail->handleRequest($request);

        if ($mail->isSubmitted() && $mail->isValid() && $this->applyMailSettings($organization, $mail)) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Messagerie enregistrée.');

            return $this->redirectToRoute('admin_organization', ['id' => $organization->getId()]);
        }

        $account = $this->createForm(AccountType::class);
        $account->handleRequest($request);

        if ($account->isSubmitted() && $account->isValid()) {
            $data = $account->getData();
            $user = new User($data['email']);

            if (null !== $users->findOneBy(['email' => $user->getEmail()])) {
                $account->get('email')->addError(new FormError('Un compte utilise déjà cette adresse.'));
            } else {
                $user->setOrganization($organization)->setPassword($hasher->hashPassword($user, $data['password']));
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                $this->addFlash('success', \sprintf('Compte %s créé.', $user->getEmail()));

                return $this->redirectToRoute('admin_organization', ['id' => $organization->getId()]);
            }
        }

        return $this->render('admin/organization.html.twig', [
            'edited' => $organization,
            'accounts' => $users->findBy(['organization' => $organization], ['email' => 'ASC']),
            'edit' => $edit,
            'mail' => $mail,
            'account' => $account,
        ]);
    }

    private function applyMailSettings(Organization $organization, FormInterface $form): bool
    {
        $password = $form->get('smtpPassword')->getData();

        if (null === $organization->getSmtpUsername()) {
            $organization->setSmtpPassword(null);

            return true;
        }

        if ($password) {
            $organization->setSmtpPassword($this->secrets->encrypt($password));

            return true;
        }

        if (null === $organization->getSmtpPassword()) {
            $form->get('smtpPassword')->addError(new FormError('Indiquez le mot de passe de la boîte.'));

            return false;
        }

        return true;
    }

    #[Route('/{id}/courriel-test', name: 'admin_organization_test_mail', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function testMail(Organization $organization, Request $request, Notifier $notifier): Response
    {
        if (!$this->isCsrfTokenValid('test-mail', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        \assert($user instanceof User);
        $error = $notifier->sendTest($organization, $user->getEmail());

        if (null === $error) {
            $this->addFlash('mail_success', \sprintf('Courriel de test envoyé à %s. Vérifiez qu\'il arrive bien, spams compris.', $user->getEmail()));
        } else {
            $this->addFlash('mail_error', 'Échec de l\'envoi : '.$error);
        }

        // Le bouton est bas dans la page : on y revient pour que le résultat soit sous les yeux.
        return $this->redirect($this->generateUrl('admin_organization', ['id' => $organization->getId()]).'#messagerie');
    }

    private function applyLogo(Organization $organization, FormInterface $form): void
    {
        $logo = $this->logos->findFor($organization);
        $file = $form->get('logo')->getData();

        if ($file instanceof UploadedFile) {
            $content = (string) file_get_contents($file->getPathname());
            $mimeType = (string) $file->getMimeType();

            if ($logo) {
                $logo->replace($mimeType, $content);
            } else {
                $this->entityManager->persist(new OrganizationLogo($organization, $mimeType, $content));
            }

            $organization->setLogoVersion(time());
        } elseif ($form->get('removeLogo')->getData() && $logo) {
            $this->entityManager->remove($logo);
            $organization->setLogoVersion(null);
        }
    }

    #[Route('/comptes/{id}', name: 'admin_account', requirements: ['id' => '\d+'])]
    public function editAccount(User $user, Request $request, UserRepository $users, UserPasswordHasherInterface $hasher): Response
    {
        $organization = $user->getOrganization();

        // Comme pour la suppression : cet écran ne doit pas pouvoir couper l'accès d'un super-admin.
        if ($user->isSuperAdmin() || null === $organization) {
            throw $this->createNotFoundException();
        }

        $this->current->switchTo($organization);

        $form = $this->createForm(AccountEditType::class, ['email' => $user->getEmail()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $email = mb_strtolower($data['email']);
            $other = $users->findOneBy(['email' => $email]);

            if (null !== $other && $other !== $user) {
                $form->get('email')->addError(new FormError('Un compte utilise déjà cette adresse.'));
            } else {
                $user->setEmail($email);

                if ($data['password']) {
                    $user->setPassword($hasher->hashPassword($user, $data['password']));
                }

                $this->entityManager->flush();
                $this->addFlash('success', \sprintf('Compte %s enregistré.', $user->getEmail()));

                return $this->redirectToRoute('admin_organization', ['id' => $organization->getId()]);
            }
        }

        return $this->render('admin/account.html.twig', ['account' => $user, 'form' => $form]);
    }

    #[Route('/comptes/{id}/supprimer', name: 'admin_account_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteAccount(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-account', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Un super-admin ne se retire pas par cet écran : on pourrait perdre le dernier accès.
        $organization = $user->getOrganization();

        if ($user->isSuperAdmin() || null === $organization) {
            throw $this->createNotFoundException();
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Compte supprimé.');

        return $this->redirectToRoute('admin_organization', ['id' => $organization->getId()]);
    }
}
