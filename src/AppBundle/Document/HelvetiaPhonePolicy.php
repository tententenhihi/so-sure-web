<?php

namespace AppBundle\Document;

use AppBundle\Document\Policy\Policy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Classes\Helvetia;
use AppBundle\Classes\NoOp;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\HelvetiaPhonePolicyRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class HelvetiaPhonePolicy extends PhonePolicy
{
    /**
     * @inheritDoc
     */
    public function getUnderwriterTimeZone()
    {
        return new \DateTimeZone("Europe/Zurich");
    }

    /**
     * @inheritDoc
     */
    public function getUnderwriterName()
    {
        return Helvetia::NAME;
    }

    /**
     * @inheritDoc
     */
    public function setCommission($payment, $allowFraction = false, \DateTime $date = null)
    {
        NoOp::ignore([$allowFraction, $date]);
        $payment->setCoverholderCommission($payment->getGwp() * Helvetia::COMMISSION_PROPORTION);
        $n = $this->getPremium()->getNumberOfMonthlyPayments($payment->getAmount());
        $payment->setBrokerCommission(Helvetia::MONTHLY_BROKER_COMMISSION * $n);
    }

    /**
     * @inheritDoc
     */
    public function getYearlyTotalCommission(): float
    {
        $premium = $this->getPremium();
        if (!$premium) {
            return 0;
        }
        return $premium->getGwp() * 12 * Helvetia::COMMISSION_PROPORTION + Helvetia::YEARLY_BROKER_COMMISSION;
    }

    /**
     * @inheritDoc
     */
    public function getYearlyCoverholderCommission(): float
    {
        $premium = $this->getPremium();
        if (!$premium) {
            return 0;
        }
        return $premium->getGwp() * 12 * Helvetia::COMMISSION_PROPORTION;
    }

    /**
     * @inheritDoc
     */
    public function getYearlyBrokerCommission(): float
    {
        return Helvetia::YEARLY_BROKER_COMMISSION;
    }

    /**
     * @inheritDoc
     */
    public function getExpectedCommission(\DateTime $date = null): float
    {
        $expectedCommission = null;
        $totalPayments = $this->getTotalSuccessfulStandardPayments(false, $date);
        $expectedPayments = $this->getTotalExpectedPaidToDate($date);
        $isMoneyOwed = !$this->areEqualToTwoDp($totalPayments, $expectedPayments) && $totalPayments < $expectedPayments;
        $numPayments = $this->getPremium()->getNumberOfMonthlyPayments($totalPayments);
        if ($numPayments > 12 || $numPayments < 0) {
            throw new \Exception(sprintf('Unable to calculate expected broker fees for policy %s', $this->getId()));
        }
        $expectedMonthlyCommission = $numPayments * $this->getPremium()->getGwp() * Helvetia::COMMISSION_PROPORTION;
        $commissionReceived = Payment::sumPayments($this->getSuccessfulPayments(), true)['totalCommission'];
        if ($this->isCooloffCancelled()) {
            return 0;
        } elseif (in_array($this->getStatus(), [
            self::STATUS_ACTIVE,
            self::STATUS_UNPAID,
            self::STATUS_PICSURE_REQUIRED
        ])) {
            $expectedCommission = $expectedMonthlyCommission;
        } elseif ($this->isCancelled() && (!$this->isRefundAllowed() || $isMoneyOwed)) {
            if ($numPayments) {
                $expectedCommission = $expectedMonthlyCommission;
            } else {
                $expectedCommission = $commissionReceived;
            }
        } elseif (in_array($this->getStatus(), [
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_CLAIMABLE,
            self::STATUS_EXPIRED_WAIT_CLAIM]) && $numPayments == 11) {
            $expectedCommission = $expectedMonthlyCommission;
        } else {
            if (!$date) {
                $date = \DateTime::createFromFormat('U', time());
            }
            if ($date > $this->getEnd()) {
                $date = $this->getEnd();
            }
            $expectedCommission = $this->getProratedCommission($date);
        }
        return $expectedCommission;
    }

    /**
     * Gives you the amount of money helvetia should have gotten out of this policy currently.
     * @return float the amount of cash.
     */
    public function getHelvetiaCash()
    {
        return $this->getPremiumPaid() - $this->getCoverholderCommissionPaid() - $this->getBrokerCommissionPaid();
    }

    /**
     * Gives you the proportionate amount of premium owed given the end date.
     * @return float the amount of premium due on this policy.
     */
    public function getProRataPremium()
    {
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }
        return $this->getPremium()->getYearlyPremiumPrice() * $this->proRataMultiplier();
    }

    /**
     * Gives you the proportionate amount of ipt owed given the end date.
     * @return float the amount of ipt due on this policy.
     */
    public function getProRataIpt()
    {
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }
        return $this->getPremium()->getYearlyIptActual() * $this->proRataMultiplier();
    }

    /**
     * Gives you the proportionat4e amount of broker fee owed given the policy start and end.
     * @return float the amount of broker fee due on this policy.
     */
    public function getProRataBrokerFee()
    {
        if ($this->isRefundAllowed() && $this->isCooloffCancelled()) {
            return 0;
        }
        return Helvetia::YEARLY_BROKER_COMMISSION * $this->proRataMultiplier();
    }

    /**
     * Gives you a number by which you can multiply a yearly value to give a value proportional to the amount of the
     * policy that actually got run.
     * @return float the multiplier.
     */
    public function proRataMultiplier()
    {
        $actualDays = $this->getStart()->diff($this->getEnd())->days;
        $fullDays = $this->policyDays();
        return $actualDays / $fullDays;
    }

    /**
     * Gives you the number of days in the full policy from start date to the final end date.
     * @return int the number of days from the policy start to the policy expiration date.
     */
    public function policyDays()
    {
        return $this->getStart()->diff($this->getStaticEnd())->days;
    }
}