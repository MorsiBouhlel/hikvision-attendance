<?php

namespace App\Controller;

use App\Entity\WorkSchedule;
use App\Form\WorkScheduleType;
use App\Repository\DepartmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\WorkScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/horaires', name: 'work_schedule_')]
class WorkScheduleController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(WorkScheduleRepository $schedules): Response
    {
        return $this->render('work_schedule/index.html.twig', [
            'schedules' => $schedules->findAll(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, EmployeeRepository $employees, DepartmentRepository $departments): Response
    {
        $schedule = new WorkSchedule();
        $form = $this->createForm(WorkScheduleType::class, $schedule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($schedule);

            foreach ($form->get('employees')->getData() as $employee) {
                $employee->setWorkSchedule($schedule);
            }

            $em->flush();

            $this->addFlash('success', "Horaire {$schedule->getName()} créé.");
            return $this->redirectToRoute('work_schedule_index');
        }

        return $this->render('work_schedule/new.html.twig', [
            'form' => $form,
            'employeesById' => $this->indexById($employees->findActive()),
            'departments' => $departments->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(WorkSchedule $schedule, Request $request, EntityManagerInterface $em, EmployeeRepository $employees, DepartmentRepository $departments): Response
    {
        $previouslyLinked = $schedule->getEmployees()->toArray();

        $form = $this->createForm(WorkScheduleType::class, $schedule);
        $form->get('employees')->setData($previouslyLinked);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selected = $form->get('employees')->getData();

            foreach (array_udiff($previouslyLinked, $selected, fn ($a, $b) => $a->getId() <=> $b->getId()) as $removed) {
                $removed->setWorkSchedule(null);
            }

            foreach ($selected as $employee) {
                $employee->setWorkSchedule($schedule);
            }

            $em->flush();

            $this->addFlash('success', "Horaire {$schedule->getName()} mis à jour.");
            return $this->redirectToRoute('work_schedule_index');
        }

        return $this->render('work_schedule/edit.html.twig', [
            'form' => $form,
            'schedule' => $schedule,
            'employeesById' => $this->indexById($employees->findActive()),
            'departments' => $departments->findBy([], ['name' => 'ASC']),
        ]);
    }

    /** @param \App\Entity\Employee[] $employees @return array<int, \App\Entity\Employee> */
    private function indexById(array $employees): array
    {
        $indexed = [];
        foreach ($employees as $employee) {
            $indexed[$employee->getId()] = $employee;
        }
        return $indexed;
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(WorkSchedule $schedule, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('work_schedule_delete_' . $schedule->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('work_schedule_index');
        }

        $em->remove($schedule);
        $em->flush();

        $this->addFlash('success', "Horaire {$schedule->getName()} supprimé.");
        return $this->redirectToRoute('work_schedule_index');
    }
}
