<?php

namespace App\Controller;

use App\Entity\Leave;
use App\Form\LeaveType;
use App\Repository\LeaveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/conges', name: 'leave_')]
class LeaveController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(LeaveRepository $leaves): Response
    {
        return $this->render('leave/index.html.twig', [
            'leaves' => $leaves->findBy([], ['startDate' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $leave = new Leave();
        $form = $this->createForm(LeaveType::class, $leave);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($leave->getEndDate() < $leave->getStartDate()) {
                $this->addFlash('error', 'La date de fin doit être postérieure ou égale à la date de début.');
                return $this->render('leave/new.html.twig', ['form' => $form]);
            }

            $em->persist($leave);
            $em->flush();

            $this->addFlash('success', "Congé créé pour {$leave->getEmployee()->getFullName()}.");
            return $this->redirectToRoute('leave_index');
        }

        return $this->render('leave/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Leave $leave, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('leave_delete_' . $leave->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('leave_index');
        }

        $em->remove($leave);
        $em->flush();

        $this->addFlash('success', 'Congé supprimé.');
        return $this->redirectToRoute('leave_index');
    }
}
