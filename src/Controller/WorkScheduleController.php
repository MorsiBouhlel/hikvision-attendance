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
        $form = $this->createForm(WorkScheduleType::class, $schedule);
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

        // Le plan ISAPI à écraser (UserRightWeekPlanCfg/{planNo}) n'est pas déductible
        // depuis notre modèle: c'est le device qui sait, par employé, quel planTemplateNo
        // (UserInfo.RightPlan) est réellement appliqué — souvent différent de 1. Demandé
        // explicitement plutôt que deviné, pour ne jamais écraser le mauvais plan par erreur.
        $planNo = (int) $request->request->get('plan_no', 0);
        if ($planNo < 1) {
            $this->addFlash('error', 'Numéro de plan ISAPI invalide.');
            return $this->redirectToRoute('work_schedule_index');
        }

        try {
            $sync->sync($device, $schedule, $planNo);
            $this->addFlash('success', "Planning {$schedule->getName()} synchronisé sur {$device->getName()} (plan #{$planNo}).");
        } catch (\Throwable $e) {
            $this->addFlash('error', "Échec de synchro sur {$device->getName()} : {$e->getMessage()}");
        }

        return $this->redirectToRoute('work_schedule_index');
    }
}
