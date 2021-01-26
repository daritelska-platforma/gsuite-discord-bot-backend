<?php

namespace App\Repository;

use App\Entity\CreateEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CreateEvent|null find($id, $lockMode = null, $lockVersion = null)
 * @method CreateEvent|null findOneBy(array $criteria, array $orderBy = null)
 * @method CreateEvent[]    findAll()
 * @method CreateEvent[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CreateEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreateEvent::class);
    }

    // /**
    //  * @return CreateEvent[] Returns an array of CreateEvent objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?CreateEvent
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
