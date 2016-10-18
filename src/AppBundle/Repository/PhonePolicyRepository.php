<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\DateTrait;

class PhonePolicyRepository extends PolicyRepository
{
    use DateTrait;

    public function findDuplicateImei($imei)
    {
        return $this->createQueryBuilder()
            ->field('imei')->equals($imei)
            ->getQuery()
            ->execute();
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     */
    public function countAllActivePoliciesToEndOfMonth(\DateTime $date = null)
    {
        $nextMonth = $this->endOfMonth($date);

        return $this->countAllActivePolicies($nextMonth);
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     */
    public function countAllActivePolicies(\DateTime $endDate = null, \DateTime $startDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        $qb->field('start')->lte($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        return $qb->getQuery()
            ->execute()
            ->count();
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     */
    public function findAllActivePolicies($leadSource, \DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        $qb->field('leadSource')->equals($leadSource);
        $qb->field('start')->lte($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        return $qb->getQuery()
            ->execute();
    }

    public function getAllPoliciesForExport(\DateTime $date, $environment)
    {
        \AppBundle\Classes\NoOp::noOp([$date]);

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_UNPAID
            ])
            ->field('premiumInstallments')->gt(0);

        if ($environment == "prod") {
            $qb->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));
        } else {
            $qb->field('policyNumber')->notEqual(null);
        }

        return $qb->getQuery()
            ->execute();
    }
}
