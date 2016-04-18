<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\ODM\MongoDB\PersistentCollection;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("policy_type")
 * @MongoDB\DiscriminatorMap({"phone"="PhonePolicy"})
 */
abstract class Policy
{
    use ArrayToApiArrayTrait;

    const RISK_HIGH = 'high';
    const RISK_MEDIUM = 'medium';
    const RISK_LOW = 'low';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';

    const PAYMENT_DD_MONTHLY = 'gocardless_monthly';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Payment", mappedBy="policy")
     */
    protected $payments;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="policies")
     */
    protected $user;

    /** @MongoDB\Field(type="string") */
    protected $status;

    /**
     * @MongoDB\Field(type="string", name="policy_number")
     * @MongoDB\Index(unique=true, sparse=true)
     */
    protected $policyNumber;

    /** @MongoDB\Field(type="string", name="payment_type", nullable=true) */
    protected $paymentType;

    /** @MongoDB\Field(type="string", name="gocardless_mandate", nullable=true) */
    protected $gocardlessMandate;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Invitation\Invitation", mappedBy="policy")
     */
    protected $invitations;

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\Connection")
     */
    protected $connections = array();

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\Claim")
     */
    protected $claims = array();

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\Date(nullable=true) */
    protected $start;

    /** @MongoDB\Date(nullable=true) */
    protected $end;

    /** @MongoDB\Field(type="float", name="pot_value", nullable=false) */
    protected $potValue;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->payments = new \Doctrine\Common\Collections\ArrayCollection();
        $this->invitations = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getPayments()
    {
        return $this->payments;
    }

    public function addPayment($payment)
    {
        $this->payments->add($payment);
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getStart()
    {
        return $this->start;
    }

    public function setStart(\DateTime $start)
    {
        $this->start = $start;
    }

    public function getEnd()
    {
        return $this->end;
    }

    public function setEnd(\DateTime $end)
    {
        $this->end = $end;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }
    
    public function getPolicyNumber()
    {
        return $this->policyNumber;
    }

    public function setPolicyNumber($policyNumber)
    {
        $this->policyNumber = $policyNumber;
    }

    public function getPaymentType()
    {
        return $this->paymentType;
    }

    public function setPaymentType($paymentType)
    {
        $this->paymentType = $paymentType;
    }

    public function getGocardlessMandate()
    {
        return $this->gocardlessMandate;
    }

    public function setGocardlessMandate($gocardlessMandate)
    {
        $this->gocardlessMandate = $gocardlessMandate;
    }

    public function addConnection(Connection $connection)
    {
        $this->connections[] = $connection;
    }

    public function getConnections()
    {
        return $this->connections;
    }

    public function addClaim(Claim $claim)
    {
        $this->claims[] = $claim;
    }

    public function getClaims()
    {
        return $this->claims;
    }

    public function getPotValue()
    {
        return $this->potValue;
    }

    public function setPotValue($potValue)
    {
        // TODO: Should validate this value does not go over 80% of policy value
        $this->potValue = $potValue;
    }

    public function getInvitations()
    {
        return $this->invitations;
    }

    public function getInvitationsAsArray()
    {
        // TODO: should be instanceof \Doctrine\Common\Collections\ArrayCollection, but not working
        if (is_object($this->getInvitations())) {
            return $this->getInvitations()->toArray();
        }

        return $this->getInvitations();
    }

    public function create($seq)
    {
        $this->setStart(new \DateTime());
        $nextYear = clone $this->getStart();
        $nextYear = $nextYear->modify('+1 year');
        $this->setEnd($nextYear);

        $initialPolicyNumber = 5500000;
        $this->setPolicyNumber(sprintf("Mob/%s/%d", $this->getStart()->format("Y"), $initialPolicyNumber + $seq));
        $this->setStatus(self::STATUS_ACTIVE);
    }

    public function getRisk($date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        if (count($this->getConnections()) > 0) {
            // Connected and value of their pot is zero
            if ($this->getPotValue() == 0) {
                return self::RISK_HIGH;
            }

            if ($this->getLatestClaim()) {
                // Connected and value of their pot is £10 following a claim in the past month
                if ($this->hasClaimedInLast30Days($date)) {
                    return self::RISK_MEDIUM;
                } else {
                    // Connected and value of their pot is £10 following a claim which took place over a month ago
                    return self::RISK_LOW;
                }
            }

            // Connected and no claims in their network
            return self::RISK_LOW;
        }

        // Claim within first month of buying policy, has no connections and has made no attempts to make connections
        if ($this->isPolicyWithin30Days($date)) {
            return self::RISK_HIGH;
        } else {
            // No connections & claiming after the 1st month
            return self::RISK_MEDIUM;
        }
    }

    public function isPolicyWithin30Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart()->diff($date)->days <= 30;
    }
    
    public function hasClaimedInLast30Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        if (!$latestClaim = $this->getLatestClaim()) {
            return false;
        }

        return $latestClaim->getDate()->diff($date)->days <= 30;
    }

    public function getLatestClaim()
    {
        if (!$this->getClaims() || count($this->getClaims()) == 0) {
            return null;
        }

        $claims = $this->getClaims();
        if ($claims instanceof PersistentCollection) {
            $claims = $claims->getValues();
        }
        uasort($claims, function ($a, $b) {
            return $a->getDate() < $b->getDate();
        });

        return array_values($claims)[0];
    }

    public function calculatePotValue()
    {
        $potValue = 0;
        // TODO: How does a cancelled policy affect networked connections?  Would the connection be withdrawn?
        foreach ($this->connections as $connection) {
            $potValue += $connection->getValue();
        }

        $claimCount = 0;
        foreach ($this->claims as $claim) {
            if ($claim->isMonetaryClaim()) {
                $claimCount++;
            }
        }

        // TODO: Do we need to adjust for connections that occur post claim?
        if ($claimCount == 1 && count($this->getConnections()) >= 4) {
            $potValue = 10;
        } elseif ($claimCount > 0) {
            $potValue = 0;
        }

        return $potValue;
    }

    public function updatePotValue()
    {
        $this->setPotValue($this->updatePotValue());
    }

    public function isPolicy()
    {
        return $this->getStatus() !== null;
    }

    public function getSentInvitations()
    {
        $userId = $this->getUser() ? $this->getUser()->getId() : null;
        return array_filter($this->getInvitationsAsArray(), function ($invitation) use ($userId) {
            if ($inviter = $invitation->getInviter()) {
                return $inviter->getId() == $userId;
            }

            return false;
        });
    }

    abstract function getMaxConnections();
    abstract function getMaxPot();
    abstract function getConnectionValue();

    protected function toApiArray()
    {
        return [
            'id' => $this->getId(),
            'status' => $this->getStatus(),
            'type' => 'phone',
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ISO8601) : null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ISO8601) : null,
            'policy_number' => $this->getPolicyNumber(),
            'pot' => [
                'connections' => count($this->getConnections()),
                'max_connections' => $this->getMaxConnections(),
                'value' => $this->getPotValue(),
                'max_value' => $this->getMaxPot(),
            ],
            'connections' => $this->eachApiArray($this->getConnections()),
            'sent_invitations' => $this->eachApiArray($this->getSentInvitations()),
        ];
    }
}
