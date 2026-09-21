<?php

namespace App\Controller;

use App\Repository\DepartmentRepository;
use App\Service\AttendanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/historique')]
class AttendanceHistoryController extends AbstractController
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    #[Route('', name: 'attendance_history_index', methods: ['GET'])]
    public function index(Request $request, DepartmentRepository $departments): Response
    {
        $dateParam = $request->query->get('date', (new \DateTimeImmutable('today'))->format('Y-m-d'));
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam) ?: new \DateTimeImmutable('today');
        $date = $date->setTime(0, 0, 0);
        $department = $request->query->getInt('department') ?: null;

        $summary = $this->attendanceService->dailySummaryForAll($date, $department);

        return $this->render('attendance_history/index.html.twig', [
            'date' => $date,
            'summary' => $summary,
            'departments' => $departments->findBy([], ['name' => 'ASC']),
            'department' => $department,
        ]);
    }
}
