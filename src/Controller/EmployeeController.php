<?php

namespace App\Controller;

use App\Entity\AttendanceCorrection;
use App\Entity\AttendanceEvent;
use App\Entity\Device;
use App\Entity\Employee;
use App\Form\AttendanceEventType;
use App\Form\EmployeeType;
use App\Repository\AttendanceCorrectionRepository;
use App\Repository\AttendanceEventRepository;
use App\Repository\DeviceEmployeeRepository;
use App\Repository\DeviceRepository;
use App\Repository\EmployeeRepository;
use App\Repository\UserRepository;
use App\Service\AttendanceService;
use App\Service\EmployeeSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/employees', name: 'employee_')]
class EmployeeController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, EmployeeRepository $employees): Response
    {
        $showExcluded = $request->query->getBoolean('show_excluded');

        return $this->render('employee/index.html.twig', [
            'employees' => $showExcluded ? $employees->findAll() : $employees->findListable(),
            'showExcluded' => $showExcluded,
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

            $this->addFlash('success', ['key' => 'flash.employee_created', 'params' => ['%name%' => $employee->getFullName()]]);
            return $this->redirectToRoute('employee_index');
        }

        return $this->render('employee/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Employee $employee, Request $request, AttendanceService $attendanceService, AttendanceCorrectionRepository $corrections, DeviceRepository $devices, UserRepository $users): Response
    {
        $defaultEnd = new \DateTimeImmutable('today');
        $defaultStart = $defaultEnd->modify('-29 days');

        $start = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->query->get('from')) ?: $defaultStart;
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->query->get('to')) ?: $defaultEnd;

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $history = $attendanceService->employeeHistory($employee, $start, $end);

        $linkedDeviceIds = array_map(fn ($link) => $link->getDevice()->getId(), $employee->getDeviceLinks()->toArray());
        $pushableDevices = array_filter(
            $devices->findBy(['isActive' => true]),
            fn (Device $d) => ! in_array($d->getId(), $linkedDeviceIds, true)
        );

        return $this->render('employee/show.html.twig', [
            'employee' => $employee,
            'history' => array_reverse($history),
            'start' => $start,
            'end' => $end,
            'corrections' => $this->isGranted('ROLE_MANAGER') ? $corrections->findRecentForEmployee($employee) : [],
            'pushableDevices' => $pushableDevices,
            'linkedAccount' => $users->findByEmployee($employee),
        ]);
    }

    #[Route('/{id}/push/{deviceId}', name: 'push_to_device', methods: ['POST'], requirements: ['deviceId' => '\d+'])]
    public function pushToDevice(Employee $employee, int $deviceId, Request $request, DeviceRepository $devices, EmployeeSyncService $employeeSync): Response
    {
        if (! $this->isCsrfTokenValid('employee_push_' . $employee->getId() . '_' . $deviceId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        $device = $devices->find($deviceId);
        if (! $device) {
            throw $this->createNotFoundException();
        }

        try {
            $result = $employeeSync->pushSelected($device, [$employee]);
        } catch (\Throwable $e) {
            $this->addFlash('error', ['key' => 'flash.device_unreachable', 'params' => ['%name%' => $device->getName(), '%error%' => $e->getMessage()]]);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        if (! empty($result['pushed'])) {
            $this->addFlash('success', ['key' => 'flash.employee_pushed', 'params' => ['%name%' => $employee->getFullName(), '%device%' => $device->getName()]]);
        } else {
            $error = $result['failed'][0]['error'] ?? '?';
            $this->addFlash('error', ['key' => 'flash.employee_push_failed', 'params' => ['%name%' => $employee->getFullName(), '%device%' => $device->getName(), '%error%' => $error]]);
        }

        return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
    }

    #[Route('/{id}/push-update/{deviceId}', name: 'push_update_to_device', methods: ['POST'], requirements: ['deviceId' => '\d+'])]
    public function pushUpdateToDevice(Employee $employee, int $deviceId, Request $request, DeviceEmployeeRepository $deviceEmployees, EmployeeSyncService $employeeSync): Response
    {
        if (! $this->isCsrfTokenValid('employee_push_update_' . $employee->getId() . '_' . $deviceId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        $link = $deviceEmployees->findOneBy(['employee' => $employee, 'device' => $deviceId]);
        if (! $link) {
            throw $this->createNotFoundException();
        }

        try {
            $employeeSync->pushUpdate($link);
        } catch (\Throwable $e) {
            $this->addFlash('error', ['key' => 'flash.employee_push_update_failed', 'params' => ['%name%' => $employee->getFullName(), '%device%' => $link->getDevice()->getName(), '%error%' => $e->getMessage()]]);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        $this->addFlash('success', ['key' => 'flash.employee_update_pushed', 'params' => ['%name%' => $employee->getFullName(), '%device%' => $link->getDevice()->getName()]]);
        return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
    }

    #[Route('/{id}/pull/{deviceId}', name: 'pull_from_device_preview', methods: ['GET'], requirements: ['deviceId' => '\d+'])]
    public function pullFromDevicePreview(Employee $employee, int $deviceId, DeviceRepository $devices, EmployeeSyncService $employeeSync): Response
    {
        $device = $devices->find($deviceId);
        if (! $device) {
            throw $this->createNotFoundException();
        }

        try {
            $unlinked = $employeeSync->previewUnlinked($device);
        } catch (\Throwable $e) {
            $this->addFlash('error', ['key' => 'flash.device_unreachable', 'params' => ['%name%' => $device->getName(), '%error%' => $e->getMessage()]]);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        return $this->render('employee/pull_preview.html.twig', [
            'employee' => $employee,
            'device' => $device,
            'unlinked' => $unlinked,
        ]);
    }

    #[Route('/{id}/pull/{deviceId}', name: 'pull_from_device_confirm', methods: ['POST'], requirements: ['deviceId' => '\d+'])]
    public function pullFromDeviceConfirm(Employee $employee, int $deviceId, Request $request, DeviceRepository $devices, EmployeeSyncService $employeeSync): Response
    {
        if (! $this->isCsrfTokenValid('employee_pull_' . $employee->getId() . '_' . $deviceId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        $device = $devices->find($deviceId);
        if (! $device) {
            throw $this->createNotFoundException();
        }

        $employeeNo = (string) $request->request->get('employee_no');
        if ($employeeNo === '') {
            $this->addFlash('error', ['key' => 'flash.no_employee_no_selected']);
            return $this->redirectToRoute('employee_pull_from_device_preview', ['id' => $employee->getId(), 'deviceId' => $deviceId]);
        }

        try {
            $employeeSync->linkOne($device, $employee, $employeeNo);
        } catch (\Throwable $e) {
            $this->addFlash('error', ['key' => 'flash.employee_pull_failed', 'params' => ['%name%' => $employee->getFullName(), '%device%' => $device->getName(), '%error%' => $e->getMessage()]]);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        $this->addFlash('success', ['key' => 'flash.employee_pulled', 'params' => ['%name%' => $employee->getFullName(), '%device%' => $device->getName(), '%employeeNo%' => $employeeNo]]);
        return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
    }

    #[Route('/{id}/punches/{date}', name: 'punches', methods: ['GET'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function punches(Employee $employee, string $date, AttendanceEventRepository $events): Response
    {
        $punches = $events->findForEmployeeOnDate($employee, new \DateTimeImmutable($date));

        return $this->render('attendance/_punches_table.html.twig', [
            'punches' => $punches,
        ]);
    }

    /** @return array<int, \App\Entity\Device> devices proposés pour une correction manuelle: ceux liés à l'employé, ou tous les devices actifs s'il n'en a aucun */
    private function deviceChoicesFor(Employee $employee, DeviceRepository $devices): array
    {
        $linked = array_map(fn ($link) => $link->getDevice(), $employee->getDeviceLinks()->toArray());

        return $linked !== [] ? $linked : $devices->findBy(['isActive' => true]);
    }

    #[Route('/{id}/punches/{date}/new', name: 'punch_new', methods: ['GET', 'POST'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function punchNew(Employee $employee, string $date, Request $request, EntityManagerInterface $em, DeviceRepository $devices): Response
    {
        $event = new AttendanceEvent();
        $event->setEmployee($employee);
        $event->setEventType('manual');
        $event->setOccurredAt(new \DateTimeImmutable($date . ' 08:00:00'));

        $deviceChoices = $this->deviceChoicesFor($employee, $devices);
        $form = $this->createForm(AttendanceEventType::class, $event, ['device_choices' => $deviceChoices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($event);

            $correction = (new AttendanceCorrection())
                ->setEmployee($employee)
                ->setUser($this->getUser())
                ->setAction(AttendanceCorrection::ACTION_CREATED)
                ->setOccurredAt($event->getOccurredAt())
                ->setAttendanceStatus($event->getAttendanceStatus())
                ->setReason($form->get('reason')->getData());
            $em->persist($correction);

            $em->flush();

            $this->addFlash('success', ['key' => 'flash.punch_created', 'params' => ['%name%' => $employee->getFullName()]]);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        return $this->render('employee/punch_form.html.twig', [
            'employee' => $employee,
            'form' => $form,
            'date' => $date,
            'mode' => 'new',
        ]);
    }

    #[Route('/{id}/punches/{date}/{eventId}/edit', name: 'punch_edit', methods: ['GET', 'POST'], requirements: ['date' => '\d{4}-\d{2}-\d{2}', 'eventId' => '\d+'])]
    public function punchEdit(Employee $employee, string $date, int $eventId, Request $request, EntityManagerInterface $em, AttendanceEventRepository $events, DeviceRepository $devices): Response
    {
        $event = $events->find($eventId);
        if (! $event || $event->getEmployee() !== $employee) {
            throw $this->createNotFoundException();
        }

        $deviceChoices = $this->deviceChoicesFor($employee, $devices);
        $form = $this->createForm(AttendanceEventType::class, $event, ['device_choices' => $deviceChoices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $correction = (new AttendanceCorrection())
                ->setEmployee($employee)
                ->setUser($this->getUser())
                ->setAction(AttendanceCorrection::ACTION_UPDATED)
                ->setOccurredAt($event->getOccurredAt())
                ->setAttendanceStatus($event->getAttendanceStatus())
                ->setReason($form->get('reason')->getData());
            $em->persist($correction);

            $em->flush();

            $this->addFlash('success', ['key' => 'flash.punch_updated', 'params' => ['%name%' => $employee->getFullName()]]);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        return $this->render('employee/punch_form.html.twig', [
            'employee' => $employee,
            'form' => $form,
            'date' => $date,
            'mode' => 'edit',
            'event' => $event,
        ]);
    }

    #[Route('/{id}/punches/{date}/{eventId}/delete', name: 'punch_delete', methods: ['POST'], requirements: ['date' => '\d{4}-\d{2}-\d{2}', 'eventId' => '\d+'])]
    public function punchDelete(Employee $employee, string $date, int $eventId, Request $request, EntityManagerInterface $em, AttendanceEventRepository $events): Response
    {
        $event = $events->find($eventId);
        if (! $event || $event->getEmployee() !== $employee) {
            throw $this->createNotFoundException();
        }

        if (! $this->isCsrfTokenValid('punch_delete_' . $event->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
        }

        $correction = (new AttendanceCorrection())
            ->setEmployee($employee)
            ->setUser($this->getUser())
            ->setAction(AttendanceCorrection::ACTION_DELETED)
            ->setOccurredAt($event->getOccurredAt())
            ->setAttendanceStatus($event->getAttendanceStatus())
            ->setReason((string) $request->request->get('reason') ?: null);
        $em->persist($correction);

        $em->remove($event);
        $em->flush();

        $this->addFlash('success', ['key' => 'flash.punch_deleted', 'params' => ['%name%' => $employee->getFullName()]]);
        return $this->redirectToRoute('employee_show', ['id' => $employee->getId()]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Employee $employee, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EmployeeType::class, $employee);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', ['key' => 'flash.employee_updated', 'params' => ['%name%' => $employee->getFullName()]]);
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
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('employee_index');
        }

        $employee->setIsActive(false);
        $em->flush();

        $this->addFlash('success', ['key' => 'flash.employee_deactivated', 'params' => ['%name%' => $employee->getFullName()]]);
        return $this->redirectToRoute('employee_index');
    }

    #[Route('/{id}/toggle-tracking', name: 'toggle_tracking', methods: ['POST'])]
    public function toggleTracking(Employee $employee, Request $request, EntityManagerInterface $em): Response
    {
        if (! $this->isCsrfTokenValid('employee_toggle_tracking_' . $employee->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
            return $this->redirectToRoute('employee_index', ['show_excluded' => 1]);
        }

        $employee->setIsTrackingEnabled(! $employee->isTrackingEnabled());
        $em->flush();

        $this->addFlash('success', $employee->isTrackingEnabled()
            ? ['key' => 'flash.employee_tracking_enabled', 'params' => ['%name%' => $employee->getFullName()]]
            : ['key' => 'flash.employee_tracking_disabled', 'params' => ['%name%' => $employee->getFullName()]]);

        return $this->redirectToRoute('employee_index', ['show_excluded' => 1]);
    }
}
