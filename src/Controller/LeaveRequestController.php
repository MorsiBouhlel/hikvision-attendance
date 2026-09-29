<?php

namespace App\Controller;

use App\Entity\LeaveRequest;
use App\Repository\LeaveRequestRepository;
use App\Service\LeaveBalanceService;
use App\Service\LeaveRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/demandes-conges', name: 'leave_request_')]
class LeaveRequestController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(LeaveRequestRepository $requests, LeaveBalanceService $balances): Response
    {
        $list = $requests->findAllOrdered();
        $year = (int) date('Y');
        $pendingEmployees = [];
        foreach ($list as $r) {
            if ($r->isPending()) {
                $pendingEmployees[$r->getEmployee()->getId()] = $r->getEmployee();
            }
        }

        return $this->render('leave_request/index.html.twig', [
            'requests' => $list,
            'balances' => $balances->balances(array_values($pendingEmployees), $year),
        ]);
    }

    #[Route('/{id}/{action}', name: 'decide', requirements: ['id' => '\d+', 'action' => 'approve|reject'], methods: ['POST'])]
    public function decide(LeaveRequest $leaveRequest, string $action, Request $request, LeaveRequestService $service): Response
    {
        if (! $this->isCsrfTokenValid('leave_request_decide_' . $leaveRequest->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
        } elseif (! $leaveRequest->isPending()) {
            $this->addFlash('error', ['key' => 'flash.leave_request_already_decided']);
        } else {
            $comment = trim((string) $request->request->get('comment'));
            $comment = mb_substr($comment, 0, 255);
            if ($action === 'approve') {
                $service->approve($leaveRequest, $this->getUser(), $comment);
                $this->addFlash('success', ['key' => 'flash.leave_request_approved', 'params' => ['%name%' => $leaveRequest->getEmployee()->getFullName()]]);
            } else {
                $service->reject($leaveRequest, $this->getUser(), $comment);
                $this->addFlash('success', ['key' => 'flash.leave_request_rejected', 'params' => ['%name%' => $leaveRequest->getEmployee()->getFullName()]]);
            }
        }

        // Décision prise depuis le calendrier : on y retourne, sur le même mois.
        $returnMonth = (string) $request->request->get('return_month');
        if (preg_match('/^\d{4}-\d{2}$/', $returnMonth)) {
            return $this->redirectToRoute('calendar_index', ['month' => $returnMonth]);
        }

        return $this->redirectToRoute('leave_request_index');
    }
}
