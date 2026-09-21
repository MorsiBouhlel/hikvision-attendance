<?php

namespace App\Controller;

use App\Entity\Department;
use App\Form\DepartmentType;
use App\Repository\DepartmentRepository;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/departements', name: 'department_')]
class DepartmentController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(DepartmentRepository $departments): Response
    {
        return $this->render('department/index.html.twig', [
            'departments' => $departments->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, EmployeeRepository $employees): Response
    {
        $department = new Department();
        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($department);

            foreach ($form->get('employees')->getData() as $employee) {
                $employee->setDepartment($department);
            }

            $em->flush();

            $this->addFlash('success', "Département {$department->getName()} créé.");
            return $this->redirectToRoute('department_index');
        }

        return $this->render('department/new.html.twig', [
            'form' => $form,
            'employeesById' => $this->indexById($employees->findActive()),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Department $department, Request $request, EntityManagerInterface $em, EmployeeRepository $employees): Response
    {
        $previouslyLinked = $department->getEmployees()->toArray();

        $form = $this->createForm(DepartmentType::class, $department);
        $form->get('employees')->setData($previouslyLinked);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selected = $form->get('employees')->getData();

            foreach (array_udiff($previouslyLinked, $selected, fn ($a, $b) => $a->getId() <=> $b->getId()) as $removed) {
                $removed->setDepartment(null);
            }

            foreach ($selected as $employee) {
                $employee->setDepartment($department);
            }

            $em->flush();

            $this->addFlash('success', "Département {$department->getName()} mis à jour.");
            return $this->redirectToRoute('department_index');
        }

        return $this->render('department/edit.html.twig', [
            'form' => $form,
            'department' => $department,
            'employeesById' => $this->indexById($employees->findActive()),
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
    public function delete(Department $department, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('department_delete_' . $department->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('department_index');
        }

        $em->remove($department);
        $em->flush();

        $this->addFlash('success', "Département {$department->getName()} supprimé.");
        return $this->redirectToRoute('department_index');
    }
}
