<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceEmployee;
use App\Entity\Employee;
use App\Repository\DeviceEmployeeRepository;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EmployeeSyncService
{
    public function __construct(
        private readonly HikvisionClientFactory $clientFactory,
        private readonly DeviceEmployeeRepository $deviceEmployees,
        private readonly EmployeeRepository $employees,
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
     * Lie un $employeeNo précis, déjà enregistré sur $device, à un Employee
     * existant de la plateforme (contrairement à linkSelected() qui crée
     * toujours un nouvel Employee) — cas d'usage: l'employé existe déjà
     * côté plateforme et vient d'être enrôlé physiquement sur ce terminal,
     * on veut juste rattacher les deux sans passer par le preview global
     * de tous les utilisateurs non liés du device. Télécharge aussi la
     * photo d'enrôlement si le device en fournit une (même comportement
     * que linkSelected(), échec de téléchargement non bloquant).
     *
     * @throws \RuntimeException si $employeeNo est déjà lié (sur ce device, à cet employé ou à un autre)
     */
    public function linkOne(Device $device, Employee $employee, string $employeeNo): void
    {
        if ($this->deviceEmployees->findByDeviceAndEmployeeNo($device, $employeeNo)) {
            throw new \RuntimeException("employeeNo {$employeeNo} déjà lié sur {$device->getName()}.");
        }

        $client = $this->clientFactory->forDevice($device);
        $user = null;
        foreach ($client->listUsers() as $candidate) {
            if (($candidate['employeeNo'] ?? null) === $employeeNo) {
                $user = $candidate;
                break;
            }
        }

        if ($user === null) {
            throw new \RuntimeException("employeeNo {$employeeNo} introuvable sur {$device->getName()}.");
        }

        $link = new DeviceEmployee();
        $link->setDevice($device);
        $link->setEmployee($employee);
        $link->setEmployeeNo($employeeNo);
        $this->em->persist($link);
        $this->em->flush();

        if (! empty($user['faceURL'])) {
            try {
                $this->photos->store($employee, $user['faceURL'], $client);
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->warning('Échec du téléchargement de la photo employé', [
                    'employee_id' => $employee->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
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

    /** @return Employee[] employés actifs de la plateforme pas encore liés à ce device (candidats au push) */
    public function previewPushable(Device $device): array
    {
        $linkedIds = [];
        foreach ($device->getDeviceEmployees() as $link) {
            $linkedIds[$link->getEmployee()->getId()] = true;
        }

        return array_values(array_filter(
            $this->employees->findActive(),
            fn (Employee $e) => ! isset($linkedIds[$e->getId()])
        ));
    }

    /**
     * Pousse $selected vers $device : crée chaque employé côté terminal
     * (sans biométrie — la prise d'empreinte faciale reste à faire sur
     * place) puis le lien DeviceEmployee correspondant. employeeNo attribué
     * automatiquement (max des employeeNo déjà utilisés sur ce device + 1,
     * recalculé à chaque employé pour rester valide dans la même boucle).
     * Une erreur ISAPI sur un employé (device injoignable en cours de
     * route, employeeNo déjà pris entre-temps, etc.) est loguée et
     * n'interrompt pas les suivants — chaque envoi est indépendant.
     *
     * @param Employee[] $selected
     * @return array{pushed: Employee[], failed: array<int, array{employee: Employee, error: string}>}
     */
    public function pushSelected(Device $device, array $selected): array
    {
        $client = $this->clientFactory->forDevice($device);
        $nextEmployeeNo = $this->nextEmployeeNo($client->listUsers());

        $pushed = [];
        $failed = [];

        foreach ($selected as $employee) {
            if ($this->deviceEmployees->findOneBy(['device' => $device, 'employee' => $employee])) {
                continue;
            }

            $employeeNo = (string) $nextEmployeeNo;

            try {
                $client->addUser($employeeNo, $employee->getFullName());
            } catch (\Throwable $e) {
                $this->logger->warning('Échec de création employé côté device', [
                    'device_id' => $device->getId(),
                    'employee_id' => $employee->getId(),
                    'employee_no' => $employeeNo,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = ['employee' => $employee, 'error' => $e->getMessage()];
                continue;
            }

            $link = new DeviceEmployee();
            $link->setDevice($device);
            $link->setEmployee($employee);
            $link->setEmployeeNo($employeeNo);
            $this->em->persist($link);
            $this->em->flush();

            $pushed[] = $employee;
            $nextEmployeeNo++;
        }

        return ['pushed' => $pushed, 'failed' => $failed];
    }

    /**
     * Repousse l'état actuel de $link->getEmployee() (nom/prénom) vers le
     * device sur lequel il est déjà enregistré, en écrasant l'entrée
     * existante côté terminal (même employeeNo). Utile après une édition
     * dans /employees/{id}/edit pour propager la modification — déclenché
     * manuellement via un bouton, pas automatiquement à chaque sauvegarde
     * (choix délibéré : un device peut être injoignable au moment de
     * l'édition, l'admin rejoue l'action quand il le souhaite).
     */
    public function pushUpdate(DeviceEmployee $link): void
    {
        $client = $this->clientFactory->forDevice($link->getDevice());
        $client->updateUser($link->getEmployeeNo(), $link->getEmployee()->getFullName());
    }

    /** @param array<int, array{employeeNo?: string}> $users */
    private function nextEmployeeNo(array $users): int
    {
        $max = 0;
        foreach ($users as $user) {
            $no = (int) ($user['employeeNo'] ?? 0);
            $max = max($max, $no);
        }

        return $max + 1;
    }
}
