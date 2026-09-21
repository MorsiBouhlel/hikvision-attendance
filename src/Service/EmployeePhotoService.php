<?php

namespace App\Service;

use App\Entity\Employee;

class EmployeePhotoService
{
    public function __construct(
        private readonly string $publicDir,
    ) {
    }

    /** Télécharge la photo d'enrôlement depuis la pointeuse et la stocke sur disque. */
    public function store(Employee $employee, string $absoluteFaceUrl, HikvisionDigestClient $client): void
    {
        $binary = $client->fetchBinary($absoluteFaceUrl);

        $relativePath = 'employee-photos/' . $employee->getId() . '.jpg';
        $fullPath = $this->publicDir . '/uploads/' . $relativePath;

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0775, true);
        }

        file_put_contents($fullPath, $binary);

        $employee->setPhotoPath($relativePath);
    }
}
