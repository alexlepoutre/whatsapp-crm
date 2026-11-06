<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Conversation;
use App\Entity\Organization;
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
    public function findForInbox(Organization $organization, ?ConversationStatus $status): array
    {
        $query = $this->createQueryBuilder('v')
            ->join('v.client', 'c')
            ->addSelect('c')
            ->leftJoin('v.messages', 'm')
            ->addSelect('m')
            ->andWhere('c.organization = :organization')
            ->setParameter('organization', $organization)
            ->orderBy('v.lastMessageAt', 'DESC');

        if ($status) {
            $query->andWhere('v.status = :status')->setParameter('status', $status);
        }

        return $query->getQuery()->getResult();
    }

    /** @return array<int, int> nombre de conversations à traiter, par identifiant d'organisation */
    public function countOpenByOrganization(): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(c.organization) AS organization, COUNT(v.id) AS total')
            ->join('v.client', 'c')
            ->andWhere('v.status = :status')
            ->setParameter('status', ConversationStatus::OPEN)
            ->groupBy('c.organization')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $row) => [(int) $row['organization'], (int) $row['total']], $rows), 1, 0);
    }

    public function countOpen(Organization $organization): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->join('v.client', 'c')
            ->andWhere('c.organization = :organization')
            ->andWhere('v.status = :status')
            ->setParameter('organization', $organization)
            ->setParameter('status', ConversationStatus::OPEN)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
