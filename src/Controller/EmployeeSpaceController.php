<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mon-espace', name: 'employee_space_')]
class EmployeeSpaceController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('employee_space/index.html.twig', [
            'employee' => $this->getUser()->getEmployee(),
        ]);
    }
}
