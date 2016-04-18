<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Document\User;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("invitation_type")
 * @MongoDB\DiscriminatorMap({"email"="EmailInvitation", "sms"="SmsInvitation"})
 */
abstract class Invitation
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\Date() */
    protected $cancelled;

    /** @MongoDB\Date() */
    protected $accepted;

    /** @MongoDB\Date() */
    protected $rejected;

    /** @MongoDB\Date(name="last_reinvited") */
    protected $lastReinvited;

    /** @MongoDB\Field(type="integer", name="reinvited_count") */
    protected $reinvitedCount;

    /** @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="sentInvitations") */
    protected $inviter;

    /** @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="receivedInvitations") */
    protected $invitee;

    /** @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="invitations") */
    protected $policy;

    /** @MongoDB\String(name="link", nullable=true) */
    protected $link;

    /** @MongoDB\String(name="name", nullable=true) */
    protected $name;

    abstract public function isSingleUse();
    abstract public function getChannel();
    abstract public function getMaxReinvitations();

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->reinvitedCount = 0;
    }

    public function getId()
    {
        return $this->id;
    }
    
    public function getCreated()
    {
        return $this->created;
    }

    public function getAccepted()
    {
        return $this->accepted;
    }

    public function setAccepted($accepted)
    {
        $this->accepted = $accepted;
    }

    public function getRejected()
    {
        return $this->rejected;
    }

    public function isRejected()
    {
        return $this->rejected !== null;
    }

    public function setRejected($rejected)
    {
        $this->rejected = $rejected;
    }

    public function getCancelled()
    {
        return $this->cancelled;
    }

    public function isCancelled()
    {
        return $this->cancelled !== null;
    }

    public function setCancelled($cancelled)
    {
        $this->cancelled = $cancelled;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getLink()
    {
        return $this->link;
    }

    public function setLink($link)
    {
        $this->link = $link;
    }

    public function getInviter()
    {
        return $this->inviter;
    }

    public function setInviter(User $inviter)
    {
        $this->inviter = $inviter;
    }

    public function getInvitee()
    {
        return $this->invitee;
    }

    public function setInvitee(User $invitee)
    {
        $this->invitee = $invitee;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
        $policy->getUser()->addSentInvitation($this);
        //$this->setInviter($policy->getUser());
    }

    public function getReinvitedCount()
    {
        return $this->reinvitedCount;
    }

    public function getLastReinvited()
    {
        return $this->lastReinvited;
    }

    public function canReinvite()
    {
        return $this->getReinvitedCount() <= $this->getMaxReinvitations();
    }

    public function reinvite(\DateTime $date = null)
    {
        if (!$this->canReinvite()) {
            throw new \Exception('Max invitations have been reached');
        }

        if (!$date) {
            $date = new \DateTime();
        }
        $this->lastReinvited = $date;
        $this->reinvitedCount++;
    }

    public function hasAccepted()
    {
        return $this->getAccepted() !== null;
    }

    public function isProcessed()
    {
        return $this->getAccepted() || $this->getRejected() || $this->getCancelled();
    }

    public function toApiArray($debug = false)
    {
        $inviterName = $this->getInviter() ? $this->getInviter()->getName() : null;
        $inviteeName = $this->getInvitee() ? $this->getInvitee()->getName() : null;
        $inviterId = $this->getInviter() ? $this->getInviter()->getId() : null;
        $inviteeId = $this->getInvitee() ? $this->getInvitee()->getId() : null;
        $data = [
            'id' => $this->getId(),
            'name' => $this->getName() ? $this->getName() : null,
            'invitee_name' => $inviteeName,
            'inviter_name' => $inviterName,
            'channel' => $this->getChannel(),
            'link' => $this->getLink(),
            'created_date' => $this->getCreated(),
        ];

        if ($debug) {
            $data = array_merge($data, [
                'inviter_id' => $inviterId,
                'invitee_id' => $inviteeId,
            ]);
        }

        return $data;
    }
}
