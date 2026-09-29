<?php

namespace App\Controller;

use App\Form\HrSettingsType;
use App\Repository\HrSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reglages-rh', name: 'hr_settings_')]
class HrSettingsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, HrSettingsRepository $settings, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(HrSettingsType::class, $settings->getOrCreate());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', ['key' => 'flash.hr_settings_saved']);
            return $this->redirectToRoute('hr_settings_index');
        }

        return $this->render('hr_settings/index.html.twig', ['form' => $form]);
    }
}
