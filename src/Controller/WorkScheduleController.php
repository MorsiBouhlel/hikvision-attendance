<?php

namespace App\Controller;

use App\Entity\WorkSchedule;
use App\Entity\WorkScheduleDay;
use App\Form\WorkScheduleType;
use App\Repository\DepartmentRepository;
use App\Repository\DeviceRepository;
use App\Repository\EmployeeRepository;
use App\Repository\WorkScheduleRepository;
use App\Service\WeekPlanSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/horaires', name: 'work_schedule_')]
class WorkScheduleController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(WorkScheduleRepository $schedules, DeviceRepository $devices): Response
    {
        return $this->render('work_schedule/index.html.twig', [
            'schedules' => $schedules->findAll(),
            'devices' => $devices->findBy(['isActive' => true]),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, EmployeeRepository $employees, DepartmentRepository $departments): Response
    {
        $schedule = new WorkSchedule();

        // Pré-remplissage depuis un import ISAPI (voir importFromDevice()) —
        // déposé en session par cette même route en redirect, lu une seule fois.
        $session = $request->getSession();
        $import = $session->get('work_schedule_import');
        $session->remove('work_schedule_import');
        if ($import) {
            $schedule->setName($import['name']);

            // startTime/endTime "plats" du WorkSchedule (requis, NotBlank) — repris
            // du 1er jour importé non-repos comme valeur par défaut raisonnable ;
            // resolvedWindowFor() les utilisera de toute façon en fallback pour tout
            // jour sans WorkScheduleDay explicite, donc autant les initialiser.
            $firstWorkingDay = array_values(array_filter($import['days'], fn ($d) => ! $d['isRestDay']))[0] ?? null;
            if ($firstWorkingDay) {
                $schedule->setStartTime($firstWorkingDay['startTime']);
                $schedule->setEndTime($firstWorkingDay['endTime']);
            }
        }

        $form = $this->createForm(WorkScheduleType::class, $schedule, [
            'day_data' => $import['days'] ?? [],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($schedule);

            foreach ($form->get('employees')->getData() as $employee) {
                $employee->setWorkSchedule($schedule);
            }

            $this->applyDays($form, $schedule);

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

        $form = $this->createForm(WorkScheduleType::class, $schedule, [
            'day_data' => $this->dayDataFromSchedule($schedule),
        ]);
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

            $this->applyDays($form, $schedule);

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

    /** @return array<int, array{isRestDay: bool, startTime: ?\DateTimeImmutable, endTime: ?\DateTimeImmutable}> */
    private function dayDataFromSchedule(WorkSchedule $schedule): array
    {
        $data = [];
        foreach (array_keys(WorkScheduleType::DAY_LABELS) as $dayOfWeek) {
            $config = $schedule->getDayConfig($dayOfWeek);
            $data[$dayOfWeek] = [
                'isRestDay' => $config?->isRestDay() ?? false,
                'startTime' => $config?->getStartTime() ?? $schedule->getStartTime(),
                'endTime' => $config?->getEndTime() ?? $schedule->getEndTime(),
            ];
        }
        return $data;
    }

    /** Crée/met à jour les 7 WorkScheduleDay depuis les sous-champs non mappés day1..day7 du formulaire. */
    private function applyDays(FormInterface $form, WorkSchedule $schedule): void
    {
        foreach (array_keys(WorkScheduleType::DAY_LABELS) as $dayOfWeek) {
            $dayForm = $form->get('day' . $dayOfWeek);
            $isRestDay = (bool) $dayForm->get('isRestDay')->getData();
            $startTime = $dayForm->get('startTime')->getData();
            $endTime = $dayForm->get('endTime')->getData();

            $day = $schedule->getDayConfig($dayOfWeek);
            if (! $day) {
                $day = (new WorkScheduleDay())->setWorkSchedule($schedule)->setDayOfWeek($dayOfWeek);
                $schedule->getDays()->add($day);
            }

            $day->setIsRestDay($isRestDay);
            $day->setStartTime($isRestDay ? null : $startTime);
            $day->setEndTime($isRestDay ? null : $endTime);
        }
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

    #[Route('/{id}/sync-device/{deviceId}', name: 'sync_device', methods: ['POST'])]
    public function syncDevice(WorkSchedule $schedule, int $deviceId, Request $request, DeviceRepository $devices, WeekPlanSyncService $sync): Response
    {
        if (! $this->isCsrfTokenValid('work_schedule_sync_' . $schedule->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('work_schedule_index');
        }

        $device = $devices->find($deviceId);
        if (! $device) {
            $this->addFlash('error', 'Device introuvable.');
            return $this->redirectToRoute('work_schedule_index');
        }

        try {
            // planNo résolu automatiquement depuis UserInfo.RightPlan côté device
            // (voir WeekPlanSyncService::resolvePlanNo()) — pas de saisie manuelle,
            // pour ne jamais risquer d'écraser le mauvais plan par erreur de frappe.
            $planNo = $sync->sync($device, $schedule);
            $this->addFlash('success', "Planning {$schedule->getName()} synchronisé sur {$device->getName()} (plan #{$planNo} détecté automatiquement).");
        } catch (\Throwable $e) {
            $this->addFlash('error', "Échec de synchro sur {$device->getName()} : {$e->getMessage()}");
        }

        return $this->redirectToRoute('work_schedule_index');
    }

    #[Route('/import-from-device/{deviceId}', name: 'import_from_device', methods: ['POST'])]
    public function importFromDevice(int $deviceId, Request $request, DeviceRepository $devices, WeekPlanSyncService $sync): Response
    {
        if (! $this->isCsrfTokenValid('work_schedule_import_' . $deviceId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('work_schedule_index');
        }

        $device = $devices->find($deviceId);
        if (! $device) {
            $this->addFlash('error', 'Device introuvable.');
            return $this->redirectToRoute('work_schedule_index');
        }

        try {
            $imported = $sync->importFromDevice($device);
        } catch (\Throwable $e) {
            $this->addFlash('error', "Échec de l'import depuis {$device->getName()} : {$e->getMessage()}");
            return $this->redirectToRoute('work_schedule_index');
        }

        $request->getSession()->set('work_schedule_import', [
            'name' => "Importé de {$device->getName()} — " . (new \DateTimeImmutable())->format('d/m/Y'),
            'days' => $imported['days'],
        ]);

        $this->addFlash('success', "Planning importé depuis {$device->getName()} (plan #{$imported['planNo']}) — vérifiez et enregistrez ci-dessous.");
        return $this->redirectToRoute('work_schedule_new');
    }
}
