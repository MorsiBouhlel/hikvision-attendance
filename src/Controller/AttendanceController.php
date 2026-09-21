<?php

namespace App\Controller;

use App\Service\AttendanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/attendance')]
class AttendanceController extends AbstractController
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    #[Route('/today', name: 'attendance_today', methods: ['GET'])]
    public function today(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $summary = $this->attendanceService->dailySummaryForAll(new \DateTimeImmutable('today'));
        return $this->json($summary);
    }

    #[Route('/{date}', name: 'attendance_by_date', methods: ['GET'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function byDate(string $date): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $summary = $this->attendanceService->dailySummaryForAll(new \DateTimeImmutable($date));
        return $this->json($summary);
    }

    #[Route('/missing/today', name: 'attendance_missing_today', methods: ['GET'])]
    public function missingToday(): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $missing = $this->attendanceService->missingToday();
        return $this->json(array_map(fn ($e) => [
            'id' => $e->getId(),
            'name' => $e->getFullName(),
            'department' => $e->getDepartment()?->getName(),
        ], $missing));
    }
}
