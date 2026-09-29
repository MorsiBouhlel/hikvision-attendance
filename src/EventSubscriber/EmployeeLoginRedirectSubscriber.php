<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Un compte ROLE_EMPLOYEE n'a accès qu'à /mon-espace : le rediriger vers le dashboard
 * (default_target_path) ou vers une page visitée avant le login donnerait un 403.
 */
class EmployeeLoginRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $roles = $event->getUser()->getRoles();

        if (in_array('ROLE_EMPLOYEE', $roles, true) && ! in_array('ROLE_VIEWER', $roles, true)) {
            $event->setResponse(new RedirectResponse($this->urls->generate('employee_space_index')));
        }
    }
}
