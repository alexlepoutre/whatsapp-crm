<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Form\AccountType;
use App\Form\OrganizationCreateType;
use App\Form\OrganizationEditType;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'admin_organizations')]
    public function index(Request $request, OrganizationRepository $organizations): Response
    {
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
        ]);
    }

    #[Route('/{id}', name: 'admin_organization', requirements: ['id' => '\d+'])]
    public function show(
        Organization $organization,
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $edit = $this->createForm(OrganizationEditType::class, $organization);
        $edit->handleRequest($request);

        if ($edit->isSubmitted() && $edit->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Organisation enregistrée.');

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
            'account' => $account,
        ]);
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
