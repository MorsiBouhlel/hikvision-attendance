<?php

namespace App\Controller;

use App\Entity\LeaveRequest;
use App\Entity\PermissionRequest;
use App\Entity\RemoteWorkRequest;
use App\Form\LeaveRequestType;
use App\Form\PermissionRequestType;
use App\Form\RemoteWorkRequestType;
use App\Repository\LeaveRequestRepository;
use App\Repository\PermissionRequestRepository;
use App\Repository\RemoteWorkRequestRepository;
use App\Service\LeaveBalanceService;
use App\Service\LeaveRequestService;
use App\Service\PermissionService;
use App\Service\QuotaService;
use App\Service\RemoteWorkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mon-espace', name: 'employee_space_')]
class EmployeeSpaceController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(LeaveBalanceService $balances, LeaveRequestRepository $requests, RemoteWorkRequestRepository $remoteRequests, PermissionRequestRepository $permissionRequests, QuotaService $quotas): Response
    {
        $employee = $this->getUser()->getEmployee();

        return $this->render('employee_space/index.html.twig', [
            'employee' => $employee,
            'year' => (int) date('Y'),
            'balance' => $employee ? $balances->balance($employee, (int) date('Y')) : null,
            'requests' => $employee ? $requests->findForEmployee($employee) : [],
            'remote_requests' => $employee ? $remoteRequests->findForEmployee($employee) : [],
            'permission_requests' => $employee ? $permissionRequests->findForEmployee($employee) : [],
            'quota' => $employee ? $quotas->monthSummary($employee, new \DateTimeImmutable()) : null,
        ]);
    }

    #[Route('/conges/nouveau', name: 'leave_new', methods: ['GET', 'POST'])]
    public function newLeave(Request $request, LeaveRequestService $service): Response
    {
        $employee = $this->getUser()->getEmployee();
        if (! $employee) {
            return $this->redirectToRoute('employee_space_index');
        }

        $leaveRequest = (new LeaveRequest())->setEmployee($employee);
        $form = $this->createForm(LeaveRequestType::class, $leaveRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($error = $service->validate($leaveRequest)) {
                $this->addFlash('error', ['key' => $error]);
            } else {
                $exceeds = $service->exceedsBalance($leaveRequest);
                $service->submit($leaveRequest);
                if ($exceeds) {
                    $this->addFlash('warning', ['key' => 'flash.leave_request_exceeds_balance']);
                }
                $this->addFlash('success', ['key' => 'flash.leave_request_submitted']);
                return $this->redirectToRoute('employee_space_index');
            }
        }

        return $this->render('employee_space/leave_new.html.twig', ['form' => $form]);
    }

    #[Route('/conges/{id}/annuler', name: 'leave_cancel', methods: ['POST'])]
    public function cancelLeave(LeaveRequest $leaveRequest, Request $request, LeaveRequestService $service): Response
    {
        $employee = $this->getUser()->getEmployee();

        if ($leaveRequest->getEmployee() !== $employee) {
            throw $this->createAccessDeniedException();
        }
        if (! $this->isCsrfTokenValid('leave_request_cancel_' . $leaveRequest->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
        } elseif ($leaveRequest->isCancellable()) {
            $service->cancel($leaveRequest);
            $this->addFlash('success', ['key' => 'flash.leave_request_cancelled']);
        }

        return $this->redirectToRoute('employee_space_index');
    }

    #[Route('/teletravail/nouveau', name: 'remote_new', methods: ['GET', 'POST'])]
    public function newRemote(Request $request, RemoteWorkService $service, QuotaService $quotas): Response
    {
        $employee = $this->getUser()->getEmployee();
        if (! $employee) {
            return $this->redirectToRoute('employee_space_index');
        }

        $remoteRequest = (new RemoteWorkRequest())->setEmployee($employee);
        $form = $this->createForm(RemoteWorkRequestType::class, $remoteRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($error = $service->validate($remoteRequest)) {
                $this->addFlash('error', ['key' => $error]);
            } else {
                $exceeds = $quotas->exceedsRemoteWorkQuota($remoteRequest);
                $service->submit($remoteRequest);
                $this->addFlash('success', ['key' => 'flash.remote_work_submitted']);
                if ($exceeds) {
                    $this->addFlash('warning', ['key' => 'flash.remote_work_exceeds_quota']);
                }
                return $this->redirectToRoute('employee_space_index');
            }
        }

        return $this->render('employee_space/remote_new.html.twig', ['form' => $form]);
    }

    #[Route('/teletravail/{id}/annuler', name: 'remote_cancel', methods: ['POST'])]
    public function cancelRemote(RemoteWorkRequest $remoteRequest, Request $request, RemoteWorkService $service): Response
    {
        if ($remoteRequest->getEmployee() !== $this->getUser()->getEmployee()) {
            throw $this->createAccessDeniedException();
        }
        if (! $this->isCsrfTokenValid('remote_work_cancel_' . $remoteRequest->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
        } elseif ($remoteRequest->isCancellable()) {
            $service->cancel($remoteRequest);
            $this->addFlash('success', ['key' => 'flash.remote_work_cancelled']);
        }

        return $this->redirectToRoute('employee_space_index');
    }

    #[Route('/autorisations/nouvelle', name: 'permission_new', methods: ['GET', 'POST'])]
    public function newPermission(Request $request, PermissionService $service, QuotaService $quotas): Response
    {
        $employee = $this->getUser()->getEmployee();
        if (! $employee) {
            return $this->redirectToRoute('employee_space_index');
        }

        $permissionRequest = (new PermissionRequest())->setEmployee($employee);
        $form = $this->createForm(PermissionRequestType::class, $permissionRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($error = $service->validate($permissionRequest)) {
                $this->addFlash('error', ['key' => $error]);
            } else {
                $exceeds = $quotas->exceedsPermissionQuota($permissionRequest);
                $service->submit($permissionRequest);
                $this->addFlash('success', ['key' => 'flash.permission_submitted']);
                if ($exceeds) {
                    $this->addFlash('warning', ['key' => 'flash.permission_exceeds_quota']);
                }
                return $this->redirectToRoute('employee_space_index');
            }
        }

        return $this->render('employee_space/permission_new.html.twig', ['form' => $form]);
    }

    #[Route('/autorisations/{id}/annuler', name: 'permission_cancel', methods: ['POST'])]
    public function cancelPermission(PermissionRequest $permissionRequest, Request $request, PermissionService $service): Response
    {
        if ($permissionRequest->getEmployee() !== $this->getUser()->getEmployee()) {
            throw $this->createAccessDeniedException();
        }
        if (! $this->isCsrfTokenValid('permission_cancel_' . $permissionRequest->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
        } elseif ($permissionRequest->isCancellable()) {
            $service->cancel($permissionRequest);
            $this->addFlash('success', ['key' => 'flash.permission_cancelled']);
        }

        return $this->redirectToRoute('employee_space_index');
    }
}
