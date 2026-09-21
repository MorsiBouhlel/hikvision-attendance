<?php

namespace App\Controller;

use App\Repository\DepartmentRepository;
use App\Service\AttendanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/rapports', name: 'attendance_report_')]
class AttendanceReportController extends AbstractController
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    #[Route('/mensuel', name: 'monthly', methods: ['GET'])]
    public function monthly(Request $request, DepartmentRepository $departments): Response
    {
        [$from, $to] = $this->resolveRange($request);
        $department = $request->query->getInt('department') ?: null;
        $rows = $this->attendanceService->rangeSummary($from, $to, $department);

        return $this->render('attendance_report/monthly.html.twig', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'departments' => $departments->findBy([], ['name' => 'ASC']),
            'department' => $department,
        ]);
    }

    #[Route('/mensuel/export', name: 'monthly_export', methods: ['GET'])]
    public function monthlyExport(Request $request): Response
    {
        [$from, $to] = $this->resolveRange($request);
        $department = $request->query->getInt('department') ?: null;
        $rows = $this->attendanceService->rangeSummary($from, $to, $department);

        $response = new StreamedResponse(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employé', 'Heures travaillées', 'Retards', 'Minutes de retard', 'Absences', 'Départs anticipés', 'Overtime entrée (min)', 'Overtime sortie (min)']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['employee'],
                    $row['worked_hours'],
                    $row['late_count'],
                    $row['late_minutes'],
                    $row['absences'],
                    $row['early_leave_count'],
                    $row['overtime_in_total'],
                    $row['overtime_out_total'],
                ]);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="rapport-' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.csv"');

        return $response;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function resolveRange(Request $request): array
    {
        $today = new \DateTimeImmutable('today');
        $defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
        $defaultTo = $today->modify('last day of this month')->format('Y-m-d');

        $fromParam = $request->query->get('from', $defaultFrom);
        $toParam = $request->query->get('to', $defaultTo);

        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromParam) ?: new \DateTimeImmutable($defaultFrom);
        $to = \DateTimeImmutable::createFromFormat('Y-m-d', $toParam) ?: new \DateTimeImmutable($defaultTo);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from->setTime(0, 0, 0), $to->setTime(0, 0, 0)];
    }
}
