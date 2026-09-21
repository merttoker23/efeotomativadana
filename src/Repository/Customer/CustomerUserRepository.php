<?php

namespace App\Repository\Customer;

use App\Entity\Customer\CustomerUser;
use App\Module\Admin\Pagination\AdminPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<CustomerUser>
 */
final class CustomerUserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerUser::class);
    }

    public function findOneByEmail(string $email): ?CustomerUser
    {
        return $this->findOneBy(['email' => CustomerUser::normalizeEmail($email)]);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof CustomerUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    /** @return AdminPage<CustomerUser> */
    public function adminPage(string $query, ?bool $active, int $page, int $perPage = 20): AdminPage
    {
        $builder = $this->createQueryBuilder('customer')->orderBy('customer.createdAt', 'DESC')->addOrderBy('customer.id', 'DESC');
        if ('' !== ($query = trim($query))) {
            $builder->andWhere('LOWER(customer.email) LIKE :query OR LOWER(customer.firstName) LIKE :query OR LOWER(customer.lastName) LIKE :query')->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if (null !== $active) {
            $builder->andWhere('customer.active = :active')->setParameter('active', $active);
        }
        $page = max(1, $page);
        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($builder->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery());
        /** @var list<CustomerUser> $items */
        $items = iterator_to_array($paginator->getIterator(), false);
        return new AdminPage($items, $page, $perPage, count($paginator));
    }
}
