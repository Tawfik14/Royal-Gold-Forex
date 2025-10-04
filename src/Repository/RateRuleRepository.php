<?php

namespace App\Repository;

use App\Entity\RateRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RateRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RateRule::class);
    }

    public function findOneByCode(string $code): ?RateRule
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.code = :c')
            ->setParameter('c', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

