<?php

namespace App\Repository;

use App\Entity\Client;
use App\Service\PhoneNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    public function findByPhone(?string $phone): ?Client
    {
        $phone = PhoneNormalizer::normalize($phone);

        return null === $phone ? null : $this->findOneBy(['phone' => $phone]);
    }

    public function findByEmail(?string $email): ?Client
    {
        return $email ? $this->findOneBy(['email' => mb_strtolower(trim($email))]) : null;
    }

    /** @return Client[] */
    public function search(?string $term): array
    {
        $query = $this->createQueryBuilder('c')
            ->leftJoin('c.conversations', 'v')
            ->addSelect('v')
            ->orderBy('v.lastMessageAt', 'DESC');

        if ($term) {
            $like = '%'.addcslashes($term, '%_').'%';
            $query->andWhere('c.name LIKE :term OR c.email LIKE :term OR c.phone LIKE :term OR c.notes LIKE :term')
                ->setParameter('term', $like);
        }

        return $query->getQuery()->getResult();
    }
}
