<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationLogo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationLogo>
 */
class OrganizationLogoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationLogo::class);
    }

    public function findFor(Organization $organization): ?OrganizationLogo
    {
        return $this->findOneBy(['organization' => $organization]);
    }
}
