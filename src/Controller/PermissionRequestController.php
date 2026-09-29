<?php

namespace App\Controller;

use App\Entity\PermissionRequest;
use App\Repository\PermissionRequestRepository;
use App\Service\PermissionService;
use App\Service\QuotaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/demandes-autorisations', name: 'permission_')]
class PermissionRequestController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(PermissionRequestRepository $requests, QuotaService $quotas): Response
    {
        $list = $requests->findAllOrdered();
        $exceeds = [];
        foreach ($list as $r) {
            if ($r->isPending()) {
                $exceeds[$r->getId()] = $quotas->exceedsPermissionQuota($r);
            }
        }

        return $this->render('permission_request/index.html.twig', ['requests' => $list, 'exceeds' => $exceeds]);
    }

    #[Route('/{id}/{action}', name: 'decide', requirements: ['id' => '\d+', 'action' => 'approve|reject'], methods: ['POST'])]
    public function decide(PermissionRequest $permissionRequest, string $action, Request $request, PermissionService $service): Response
    {
        if (! $this->isCsrfTokenValid('permission_decide_' . $permissionRequest->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
        } elseif (! $permissionRequest->isPending()) {
            $this->addFlash('error', ['key' => 'flash.leave_request_already_decided']);
        } else {
            $comment = mb_substr(trim((string) $request->request->get('comment')), 0, 255);
            $params = ['%name%' => $permissionRequest->getEmployee()->getFullName()];
            if ($action === 'approve') {
                $service->approve($permissionRequest, $this->getUser(), $comment);
                $this->addFlash('success', ['key' => 'flash.permission_approved', 'params' => $params]);
            } else {
                $service->reject($permissionRequest, $this->getUser(), $comment);
                $this->addFlash('success', ['key' => 'flash.permission_rejected', 'params' => $params]);
            }
        }

        return $this->redirectToRoute('permission_index');
    }
}
