<?php

namespace App\Repository;

use App\Entity\Device;
use App\Entity\DeviceEmployee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceEmployee>
 */
class DeviceEmployeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceEmployee::class);
    }

    public function findByDeviceAndEmployeeNo(Device $device, string $employeeNo): ?DeviceEmployee
    {
        return $this->findOneBy(['device' => $device, 'employeeNo' => $employeeNo]);
    }
}
