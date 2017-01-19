<?php

namespace AppBundle\Listener;

use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\User;
use AppBundle\Service\MixpanelService;

class SecurityListener
{
    protected $logger;

    /** @var RequestStack */
    protected $requestStack;

    /** @var MixpanelService */
    protected $mixpanel;

    public function __construct($logger, RequestStack $requestStack, MixpanelService $mixpanel)
    {
        $this->logger = $logger;
        $this->requestStack = $requestStack;
        $this->mixpanel = $mixpanel;
    }

    /**
     * @param InteractiveLoginEvent $event
     */
    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event)
    {
        if (!$event->getAuthenticationToken() || !$event->getAuthenticationToken()->getUser() instanceof User) {
            return;
        }

        $identityLog = new IdentityLog();
        $identityLog->setIp($this->requestStack->getCurrentRequest()->getClientIp());
        $user = $event->getAuthenticationToken()->getUser();
        $user->setLatestWebIdentityLog($identityLog);

        $this->mixpanel->trackWithUser($user, MixpanelService::LOGIN);
    }
}
