<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceEmployee;
use App\Entity\Employee;
use App\Repository\DeviceEmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EmployeeSyncService
{
    public function __construct(
        private readonly HikvisionClientFactory $clientFactory,
        private readonly DeviceEmployeeRepository $deviceEmployees,
        private readonly EmployeePhotoService $photos,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<int, array{employeeNo: string, name: string, faceURL: ?string}> utilisateurs du device pas encore liés */
    public function previewUnlinked(Device $device): array
    {
        $users = $this->clientFactory->forDevice($device)->listUsers();

        $unlinked = [];
        foreach ($users as $user) {
            $employeeNo = $user['employeeNo'] ?? null;
            if (! $employeeNo) {
                continue;
            }

            if ($this->deviceEmployees->findByDeviceAndEmployeeNo($device, $employeeNo)) {
                continue;
            }

            $unlinked[] = [
                'employeeNo' => $employeeNo,
                'name' => $user['name'] ?? "Employé {$employeeNo}",
                'faceURL' => $user['faceURL'] ?? null,
            ];
        }

        return $unlinked;
    }

    /**
     * @param array<int, array{employeeNo: string, name: string, faceURL?: ?string}> $selected
     * @return Employee[] employés créés et liés
     */
    public function linkSelected(Device $device, array $selected): array
    {
        $created = [];
        $client = $this->clientFactory->forDevice($device);

        foreach ($selected as $entry) {
            $employeeNo = $entry['employeeNo'];
            $name = $entry['name'];

            if ($this->deviceEmployees->findByDeviceAndEmployeeNo($device, $employeeNo)) {
                continue;
            }

            $parts = explode(' ', $name, 2);
            $employee = new Employee();
            $employee->setFirstName($parts[0] ?: $name);
            $employee->setLastName($parts[1] ?? '');
            $this->em->persist($employee);

            $link = new DeviceEmployee();
            $link->setDevice($device);
            $link->setEmployee($employee);
            $link->setEmployeeNo($employeeNo);
            $this->em->persist($link);

            $this->em->flush(); // nécessaire pour obtenir $employee->getId() avant de nommer le fichier photo

            if (! empty($entry['faceURL'])) {
                try {
                    $this->photos->store($employee, $entry['faceURL'], $client);
                } catch (\Throwable $e) {
                    $this->logger->warning('Échec du téléchargement de la photo employé', [
                        'employee_id' => $employee->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $created[] = $employee;
        }

        $this->em->flush();

        return $created;
    }
}
