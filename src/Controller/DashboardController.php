<?php

namespace App\Controller;

use App\Repository\DepartmentRepository;
use App\Service\AttendanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request, DepartmentRepository $departments): Response
    {
        $today = new \DateTimeImmutable('today');
        $department = $request->query->getInt('department') ?: null;

        $summary = $this->attendanceService->dailySummaryForAll($today, $department);
        $absentCount = count(array_filter($summary, fn (array $row) => in_array($row['status'], ['absent'], true)));

        return $this->render('dashboard/index.html.twig', [
            'date' => $today,
            'summary' => $summary,
            'presentCount' => count($summary) - $absentCount,
            'absentCount' => $absentCount,
            'departments' => $departments->findBy([], ['name' => 'ASC']),
            'department' => $department,
        ]);
    }
}
