<?php

namespace AppBundle\Listener;

use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\User\UserInterface;

class KernelResponseListener
{
    const SOSURE_EMPLOYEE_COOKIE_NAME = 'sosure-employee';
    const SOSURE_EMPLOYEE_COOKIE_LENGTH = 604800; // 7 days
    protected $tokenStorage;
    protected $authChecker;
    protected $adminCookieValue;
    protected $logger;
    protected $environment;

    public function __construct($tokenStorage, $authChecker, $adminCookieValue, $logger, $environment)
    {
        $this->tokenStorage = $tokenStorage;
        $this->authChecker = $authChecker;
        $this->adminCookieValue = $adminCookieValue;
        $this->logger = $logger;
        $this->environment = $environment;
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        // Only set cookie for prod
        if ($this->environment != 'prod') {
            return;
        }

        $response = $event->getResponse();
        $request  = $event->getRequest();

        try {
            $token = $this->tokenStorage->getToken();
            if (!$token || !$token->getUser() || !$token->getUser() instanceof UserInterface) {
                return;
            }

            // authChecker doesn't seem to be working :(
            // WARNING - this means that ROLE_ADMIN needs to be directly assigned instead of chainging
            // TODO: Fixme
            $adminUser = false;
            foreach ($token->getUser()->getRoles() as $role) {
                if ($role == "ROLE_ADMIN") {
                    $adminUser = true;
                }
            }
            if (!$adminUser) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        // create/update cookie
        $cookie = new Cookie(
            self::SOSURE_EMPLOYEE_COOKIE_NAME,
            $this->adminCookieValue,
            time() + self::SOSURE_EMPLOYEE_COOKIE_LENGTH,
            '.wearesosure.com',
            true,
            true
        );

        // set cookie in response
        $response->headers->setCookie($cookie);
    }
}
