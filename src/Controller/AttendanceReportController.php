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
        $month = $this->resolveMonth($request);
        $department = $request->query->getInt('department') ?: null;
        $rows = $this->attendanceService->monthlySummary($month, $department);

        return $this->render('attendance_report/monthly.html.twig', [
            'month' => $month,
            'rows' => $rows,
            'departments' => $departments->findBy([], ['name' => 'ASC']),
            'department' => $department,
        ]);
    }

    #[Route('/mensuel/export', name: 'monthly_export', methods: ['GET'])]
    public function monthlyExport(Request $request): Response
    {
        $month = $this->resolveMonth($request);
        $department = $request->query->getInt('department') ?: null;
        $rows = $this->attendanceService->monthlySummary($month, $department);

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
        $response->headers->set('Content-Disposition', 'attachment; filename="rapport-' . $month->format('Y-m') . '.csv"');

        return $response;
    }

    private function resolveMonth(Request $request): \DateTimeImmutable
    {
        $monthParam = $request->query->get('month', (new \DateTimeImmutable('first day of this month'))->format('Y-m'));

        return \DateTimeImmutable::createFromFormat('Y-m-d', $monthParam . '-01')
            ?: new \DateTimeImmutable('first day of this month');
    }
}
