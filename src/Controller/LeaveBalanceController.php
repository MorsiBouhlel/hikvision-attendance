<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Entity\LeaveAdjustment;
use App\Repository\EmployeeRepository;
use App\Repository\LeaveAdjustmentRepository;
use App\Service\LeaveBalanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/soldes-conges', name: 'leave_balance_')]
class LeaveBalanceController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, EmployeeRepository $employees, LeaveBalanceService $balances): Response
    {
        $year = $request->query->getInt('year', (int) date('Y'));
        $list = $employees->findBy(['isActive' => true], ['lastName' => 'ASC', 'firstName' => 'ASC']);

        return $this->render('leave_balance/index.html.twig', [
            'year' => $year,
            'employees' => $list,
            'balances' => $balances->balances($list, $year),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Employee $employee, Request $request, LeaveBalanceService $balances, LeaveAdjustmentRepository $adjustments): Response
    {
        $year = $request->query->getInt('year', (int) date('Y'));

        return $this->render('leave_balance/show.html.twig', [
            'employee' => $employee,
            'year' => $year,
            'balance' => $balances->balance($employee, $year),
            'adjustments' => $adjustments->findForEmployee($employee, $year),
        ]);
    }

    #[Route('/{id}/adjust', name: 'adjust', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adjust(Employee $employee, Request $request, EntityManagerInterface $em): Response
    {
        $year = $request->request->getInt('year', (int) date('Y'));
        $days = str_replace(',', '.', (string) $request->request->get('days'));
        $reason = trim((string) $request->request->get('reason'));

        if (! $this->isCsrfTokenValid('leave_adjust_' . $employee->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
        } elseif (! is_numeric($days) || (float) $days === 0.0 || $reason === '') {
            $this->addFlash('error', ['key' => 'flash.leave_adjustment_invalid']);
        } else {
            $adjustment = (new LeaveAdjustment())
                ->setEmployee($employee)
                ->setYear($year)
                ->setDays((float) $days)
                ->setReason(mb_substr($reason, 0, 255))
                ->setCreatedBy($this->getUser());
            $em->persist($adjustment);
            $em->flush();
            $this->addFlash('success', ['key' => 'flash.leave_adjustment_created']);
        }

        return $this->redirectToRoute('leave_balance_show', ['id' => $employee->getId(), 'year' => $year]);
    }
}
