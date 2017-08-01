<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\LeadEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\ClaimEvent;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\InvitationEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\IntercomService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class IntercomListener
{
    /** @var IntercomService */
    protected $intercom;

    /**
     * @param IntercomService $intercom
     */
    public function __construct(IntercomService $intercom)
    {
        $this->intercom = $intercom;
    }

    public function onLeadUpdatedEvent(LeadEvent $event)
    {
        if ($event->getLead()->getEmail()) {
            $this->intercom->queueLead($event->getLead(), IntercomService::QUEUE_LEAD);
        }
    }

    public function onUserUpdatedEvent(UserEvent $event)
    {
        $this->intercom->queue($event->getUser());
    }

    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_CREATED);
    }

    public function onPolicyPotEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        // TODO: Trigger intercom event
    }

    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_CANCELLED);
    }

    public function onInvitationAcceptedEvent(InvitationEvent $event)
    {
        // Invitation accepted is a connection, so update both inviter & invitee
        $this->intercom->queue($event->getInvitation()->getInviter());
        $this->intercom->queue($event->getInvitation()->getInvitee());
    }

    public function onConnectionConnectedEvent(ConnectionEvent $event)
    {
        $this->intercom->queueConnection($event->getConnection());
    }

    public function onPaymentSuccessEvent(PaymentEvent $event)
    {
        $this->intercom->queuePayment($event->getPayment(), IntercomService::QUEUE_EVENT_PAYMENT_SUCCESS);
    }

    public function onPaymentFailedEvent(PaymentEvent $event)
    {
        $this->intercom->queuePayment($event->getPayment(), IntercomService::QUEUE_EVENT_PAYMENT_FAILED);
    }

    public function onPaymentFirstProblemEvent(PaymentEvent $event)
    {
        $this->intercom->queuePayment($event->getPayment(), IntercomService::QUEUE_EVENT_PAYMENT_FIRST_PROBLEM);
    }

    public function onUserPaymentFailedEvent(UserPaymentEvent $event)
    {
        $this->intercom->queueUser(
            $event->getUser(),
            IntercomService::QUEUE_EVENT_USER_PAYMENT_FAILED,
            ['reason' => $event->getReason()]
        );
    }

    public function onClaimCreatedEvent(ClaimEvent $event)
    {
        $this->intercom->queueClaim($event->getClaim(), IntercomService::QUEUE_EVENT_CLAIM_CREATED);
    }

    public function onClaimApprovedEvent(ClaimEvent $event)
    {
        $this->intercom->queueClaim($event->getClaim(), IntercomService::QUEUE_EVENT_CLAIM_APPROVED);
    }

    public function onClaimSettledEvent(ClaimEvent $event)
    {
        $this->intercom->queueClaim($event->getClaim(), IntercomService::QUEUE_EVENT_CLAIM_SETTLED);
    }
}
