<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Enum\Channel;
use App\Enum\ConversationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /** Un client n'a qu'un fil WhatsApp : c'est aussi comme ça qu'il le voit sur son téléphone. */
    public function findWhatsAppFor(Client $client): ?Conversation
    {
        return $this->findOneBy(['client' => $client, 'channel' => Channel::WHATSAPP]);
    }

    /** @return Conversation[] */
    public function findForInbox(?ConversationStatus $status): array
    {
        $query = $this->createQueryBuilder('v')
            ->join('v.client', 'c')
            ->addSelect('c')
            ->leftJoin('v.messages', 'm')
            ->addSelect('m')
            ->orderBy('v.lastMessageAt', 'DESC');

        if ($status) {
            $query->andWhere('v.status = :status')->setParameter('status', $status);
        }

        return $query->getQuery()->getResult();
    }

    public function countOpen(): int
    {
        return $this->count(['status' => ConversationStatus::OPEN]);
    }
}
