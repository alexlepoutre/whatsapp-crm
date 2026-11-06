<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Organization;
use App\Enum\ConversationStatus;
use App\Enum\DeliveryStatus;
use App\Form\ClientType;
use App\Form\MessageType;
use App\Form\SettingsType;
use App\Repository\ClientRepository;
use App\Repository\ConversationRepository;
use App\Service\CurrentOrganization;
use App\Service\Inbox;
use App\WhatsApp\WhatsAppClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CurrentOrganization $current,
    ) {
    }

    #[Route('', name: 'admin_inbox')]
    public function inbox(Request $request, ConversationRepository $conversations): Response
    {
        $filter = $request->query->getString('filtre', ConversationStatus::OPEN->value);

        return $this->render('admin/inbox.html.twig', [
            'conversations' => $conversations->findForInbox($this->current->get(), ConversationStatus::tryFrom($filter)),
            'filter' => $filter,
            'statuses' => ConversationStatus::cases(),
        ]);
    }

    #[Route('/conversations/{id}', name: 'admin_conversation', requirements: ['id' => '\d+'])]
    public function conversation(Conversation $conversation, Request $request, Inbox $inbox, WhatsAppClient $whatsApp): Response
    {
        $this->current->assertOwns($conversation->getClient());

        if ($request->isMethod('GET')) {
            $inbox->markAsRead($conversation);
        }

        $form = $this->createForm(MessageType::class, null, ['label_body' => 'Votre réponse']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($conversation->isWhatsApp() && !$conversation->isWhatsAppWindowOpen()) {
                $this->addFlash('error', 'Réponse non envoyée : le dernier message du client date de plus de 24 heures.');
            } else {
                $message = $inbox->replyFromOwner($conversation, $form->get('body')->getData(), $request->request->has('final'));

                if (DeliveryStatus::FAILED === $message->getDeliveryStatus()) {
                    $this->addFlash('error', 'Réponse enregistrée mais non envoyée : '.$message->getFailureReason());
                }
            }

            return $this->redirectToRoute('admin_conversation', ['id' => $conversation->getId()]);
        }

        return $this->render('admin/conversation.html.twig', [
            'conversation' => $conversation,
            'form' => $form,
            'whatsappConfigured' => $whatsApp->isConfigured(),
        ]);
    }

    #[Route('/conversations/{id}/cloturer', name: 'admin_conversation_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(Conversation $conversation, Request $request): Response
    {
        $this->current->assertOwns($conversation->getClient());

        if (!$this->isCsrfTokenValid('close-conversation', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $conversation->close();
        $this->entityManager->flush();
        $this->addFlash('success', 'Conversation clôturée. Elle se rouvrira si le client réécrit.');

        return $this->redirectToRoute('admin_inbox');
    }

    #[Route('/clients', name: 'admin_clients')]
    public function clients(Request $request, ClientRepository $clients): Response
    {
        $term = trim($request->query->getString('q'));

        return $this->render('admin/clients.html.twig', [
            'clients' => $clients->search($this->current->get(), $term),
            'term' => $term,
        ]);
    }

    #[Route('/clients/{id}', name: 'admin_client', requirements: ['id' => '\d+'])]
    public function client(Client $client, Request $request): Response
    {
        $this->current->assertOwns($client);

        $form = $this->createForm(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Fiche client enregistrée.');

            return $this->redirectToRoute('admin_client', ['id' => $client->getId()]);
        }

        return $this->render('admin/client.html.twig', ['client' => $client, 'form' => $form]);
    }

    /** Droit à l'effacement : le client part avec tous ses échanges. */
    #[Route('/clients/{id}/supprimer', name: 'admin_client_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteClient(Client $client, Request $request): Response
    {
        $this->current->assertOwns($client);

        if (!$this->isCsrfTokenValid('delete-client', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($client);
        $this->entityManager->flush();
        $this->addFlash('success', 'Client et conversations supprimés.');

        return $this->redirectToRoute('admin_clients');
    }

    #[Route('/organisation/{id}', name: 'admin_organization_switch', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function switchOrganization(Organization $organization, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('switch-organization', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->current->switchTo($organization);

        return $this->redirectToRoute('admin_inbox');
    }

    #[Route('/reglages', name: 'admin_settings')]
    public function settings(Request $request, WhatsAppClient $whatsApp): Response
    {
        $form = $this->createForm(SettingsType::class, $this->current->get());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Réglages enregistrés.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
            'whatsappConfigured' => $whatsApp->isConfigured(),
        ]);
    }
}
