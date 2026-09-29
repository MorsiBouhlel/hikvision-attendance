<?php

namespace App\Controller;

use App\Entity\RemoteWorkRequest;
use App\Repository\RemoteWorkRequestRepository;
use App\Service\QuotaService;
use App\Service\RemoteWorkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/demandes-teletravail', name: 'remote_work_')]
class RemoteWorkRequestController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(RemoteWorkRequestRepository $requests, QuotaService $quotas): Response
    {
        $list = $requests->findAllOrdered();
        $exceeds = [];
        foreach ($list as $r) {
            if ($r->isPending()) {
                $exceeds[$r->getId()] = $quotas->exceedsRemoteWorkQuota($r);
            }
        }

        return $this->render('remote_work_request/index.html.twig', ['requests' => $list, 'exceeds' => $exceeds]);
    }

    #[Route('/{id}/{action}', name: 'decide', requirements: ['id' => '\d+', 'action' => 'approve|reject'], methods: ['POST'])]
    public function decide(RemoteWorkRequest $remoteRequest, string $action, Request $request, RemoteWorkService $service): Response
    {
        if (! $this->isCsrfTokenValid('remote_work_decide_' . $remoteRequest->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', ['key' => 'flash.invalid_csrf']);
        } elseif (! $remoteRequest->isPending()) {
            $this->addFlash('error', ['key' => 'flash.leave_request_already_decided']);
        } else {
            $comment = mb_substr(trim((string) $request->request->get('comment')), 0, 255);
            $params = ['%name%' => $remoteRequest->getEmployee()->getFullName()];
            if ($action === 'approve') {
                $service->approve($remoteRequest, $this->getUser(), $comment);
                $this->addFlash('success', ['key' => 'flash.remote_work_approved', 'params' => $params]);
            } else {
                $service->reject($remoteRequest, $this->getUser(), $comment);
                $this->addFlash('success', ['key' => 'flash.remote_work_rejected', 'params' => $params]);
            }
        }

        // Décision prise depuis le calendrier : on y retourne, sur le même mois.
        $returnMonth = (string) $request->request->get('return_month');
        if (preg_match('/^\d{4}-\d{2}$/', $returnMonth)) {
            return $this->redirectToRoute('calendar_index', ['month' => $returnMonth]);
        }

        return $this->redirectToRoute('remote_work_index');
    }
}
