<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Form\EmployeeType;
use App\Repository\EmployeeRepository;
use App\Service\AttendanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/employees', name: 'employee_')]
class EmployeeController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EmployeeRepository $employees): Response
    {
        return $this->render('employee/index.html.twig', [
            'employees' => $employees->findAll(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $employee = new Employee();
        $form = $this->createForm(EmployeeType::class, $employee);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($employee);
            $em->flush();

            $this->addFlash('success', "Employé {$employee->getFullName()} créé.");
            return $this->redirectToRoute('employee_index');
        }

        return $this->render('employee/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Employee $employee, Request $request, AttendanceService $attendanceService): Response
    {
        $defaultEnd = new \DateTimeImmutable('today');
        $defaultStart = $defaultEnd->modify('-29 days');

        $start = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->query->get('from')) ?: $defaultStart;
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->query->get('to')) ?: $defaultEnd;

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $history = $attendanceService->employeeHistory($employee, $start, $end);

        return $this->render('employee/show.html.twig', [
            'employee' => $employee,
            'history' => array_reverse($history),
            'start' => $start,
            'end' => $end,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Employee $employee, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EmployeeType::class, $employee);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', "Employé {$employee->getFullName()} mis à jour.");
            return $this->redirectToRoute('employee_index');
        }

        return $this->render('employee/edit.html.twig', [
            'form' => $form,
            'employee' => $employee,
        ]);
    }

    #[Route('/{id}/deactivate', name: 'deactivate', methods: ['POST'])]
    public function deactivate(Employee $employee, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('employee_deactivate_' . $employee->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('employee_index');
        }

        $employee->setIsActive(false);
        $em->flush();

        $this->addFlash('success', "Employé {$employee->getFullName()} désactivé.");
        return $this->redirectToRoute('employee_index');
    }
}
