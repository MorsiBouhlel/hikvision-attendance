<?php

namespace App\Controller;

use App\Entity\Holiday;
use App\Form\HolidayType;
use App\Repository\HolidayRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/jours-feries', name: 'holiday_')]
class HolidayController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(HolidayRepository $holidays): Response
    {
        return $this->render('holiday/index.html.twig', [
            'holidays' => $holidays->findBy([], ['date' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $holiday = new Holiday();
        $form = $this->createForm(HolidayType::class, $holiday);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($holiday);
            $em->flush();

            $this->addFlash('success', ['key' => 'flash.holiday_created', 'params' => ['%name%' => $holiday->getLabel()]]);
            return $this->redirectToRoute('holiday_index');
        }

        return $this->render('holiday/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Holiday $holiday, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('holiday_delete_' . $holiday->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('holiday_index');
        }

        $em->remove($holiday);
        $em->flush();

        $this->addFlash('success', ['key' => 'flash.holiday_deleted', 'params' => ['%name%' => $holiday->getLabel()]]);
        return $this->redirectToRoute('holiday_index');
    }
}
