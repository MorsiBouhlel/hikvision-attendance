<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Applique la locale enregistrée sur le compte de l'utilisateur connecté.
 *
 * Ne peut pas se faire en lisant Security::getUser() directement sur
 * kernel.request: LocaleAwareListener (qui propage Request::getLocale() vers
 * le Translator et les autres services locale-aware) tourne à la priorité
 * 15, avant le firewall qui authentifie l'utilisateur (priorité 8) — donc
 * l'utilisateur n'est jamais disponible à temps sur cette même requête.
 * À la place: la locale est copiée en session (clé _locale) dès la
 * connexion (onLoginSuccess) et immédiatement par LocaleController::switch()
 * quand l'utilisateur change de langue en cours de session. Le contenu de
 * la session est lui disponible dès le début de la requête suivante (après
 * SessionListener, priorité 128) — onRequest() la lit et pose
 * Request::setLocale() avant que LocaleAwareListener (15) ne la propage.
 * Le LocaleListener natif de Symfony ne fait pas ce travail: il lit
 * uniquement request->attributes->get('_locale') (un paramètre de route ou
 * l'en-tête Accept-Language), jamais la session.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $event->getRequest()->getSession()->set('_locale', $user->getLocale());
        }
    }

    public function onRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->hasSession() && $locale = $request->getSession()->get('_locale')) {
            $request->setLocale($locale);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            KernelEvents::REQUEST => [['onRequest', 17]],
        ];
    }
}
