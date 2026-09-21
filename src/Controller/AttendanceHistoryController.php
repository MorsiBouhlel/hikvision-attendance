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
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $fromParam = $request->query->get('from', $request->query->get('date', $today));
        $toParam = $request->query->get('to', $request->query->get('date', $today));

        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromParam) ?: new \DateTimeImmutable('today');
        $to = \DateTimeImmutable::createFromFormat('Y-m-d', $toParam) ?: new \DateTimeImmutable('today');
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $department = $request->query->getInt('department') ?: null;

        $days = $this->attendanceService->rangeSummaryForAll($from, $to, $department);

        return $this->render('attendance_history/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'days' => array_reverse($days),
            'departments' => $departments->findBy([], ['name' => 'ASC']),
            'department' => $department,
        ]);
    }
}
