<?php

namespace App\Controller;

use App\Repository\DepartmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\HolidayRepository;
use App\Repository\LeaveRepository;
use App\Repository\LeaveRequestRepository;
use App\Repository\RemoteWorkRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/calendrier', name: 'calendar_')]
class CalendarController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        EmployeeRepository $employees,
        DepartmentRepository $departments,
        LeaveRepository $leaves,
        LeaveRequestRepository $leaveRequests,
        RemoteWorkRequestRepository $remoteRequests,
        HolidayRepository $holidays,
    ): Response {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('month'))
            ?: new \DateTimeImmutable('first day of this month midnight');
        $start = $month;
        $end = $month->modify('last day of this month');
        $department = $request->query->getInt('department') ?: null;

        $holidaySet = array_flip(array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $holidays->findDatesBetween($start, $end)));
        $days = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $days[$key] = ['date' => $d, 'weekend' => (int) $d->format('N') >= 6, 'holiday' => isset($holidaySet[$key]), 'today' => $key === date('Y-m-d')];
        }

        $rows = [];
        foreach ($employees->findActive() as $e) {
            if ($department === null || $e->getDepartment()?->getId() === $department) {
                $rows[$e->getId()] = ['employee' => $e, 'cells' => []];
            }
        }

        // Priorité d'affichage d'une cellule : congé approuvé > congé en attente > télétravail (approuvé > en attente).
        // Chaque cellule garde aussi la liste de tous les événements du jour, pour le détail au clic.
        $events = [];
        $mark = function (string $eventKey, object $entity, string $source, \DateTimeImmutable $from, \DateTimeImmutable $to, string $kind, bool $pending) use (&$rows, &$events, $start, $end): void {
            $employeeId = $entity->getEmployee()->getId();
            if (! isset($rows[$employeeId])) {
                return;
            }
            $events[$eventKey] = ['source' => $source, 'entity' => $entity];
            for ($d = max($from, $start); $d <= min($to, $end); $d = $d->modify('+1 day')) {
                $key = $d->format('Y-m-d');
                $cell = &$rows[$employeeId]['cells'][$key];
                if ($cell === null) {
                    $cell = ['kind' => $kind, 'pending' => $pending, 'events' => []];
                } elseif (($cell['kind'] === 'remote' && $kind !== 'remote') || ($cell['pending'] && ! $pending && $cell['kind'] === $kind)) {
                    $cell['kind'] = $kind;
                    $cell['pending'] = $pending;
                }
                $cell['events'][] = $eventKey;
                unset($cell);
            }
        };

        foreach ($leaves->findOverlapping($start, $end) as $l) {
            $mark('leave-' . $l->getId(), $l, 'leave', $l->getStartDate(), $l->getEndDate(), $l->getType(), false);
        }
        foreach ($leaveRequests->findPendingBetween($start, $end) as $r) {
            $mark('lr-' . $r->getId(), $r, 'leave_request', $r->getStartDate(), $r->getEndDate(), $r->getType(), true);
        }
        foreach ($remoteRequests->findActiveBetween($start, $end) as $r) {
            $mark('rw-' . $r->getId(), $r, 'remote_request', $r->getStartDate(), $r->getEndDate(), 'remote', $r->isPending());
        }

        return $this->render('calendar/index.html.twig', [
            'month' => $month,
            'prev' => $month->modify('-1 month'),
            'next' => $month->modify('+1 month'),
            'days' => $days,
            'rows' => $rows,
            'events' => $events,
            'departments' => $departments->findBy([], ['name' => 'ASC']),
            'department' => $department,
        ]);
    }
}
