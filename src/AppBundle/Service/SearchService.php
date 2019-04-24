<?php

namespace AppBundle\Service;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Exception\MissingDependencyException;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Symfony\Component\Form\FormInterface;

/**
 * Class SearchService
 * @package AppBundle\Service
 */
class SearchService
{
    /**
     * @var DocumentManager
     */
    private $dm;

    private $form;

    /**
     * @var UserRepository
     */
    private $userRepo;

    /**
     * @var Builder
     */
    private $userQb;

    /**
     * @var  PolicyRepository
     */
    private $policyRepo;

    /**
     * @var Builder
     */
    private $policyQb;

    /**
     * @var bool
     */
    private $searchWithUsers = false;

    public function __construct(DocumentManager $dm, FormInterface $form = null)
    {
        $this->dm = $dm;
        $this->form = $form;
        $this->initQueryBuilders();
    }

    public function getForm(): FormInterface
    {
        if ($this->form == null) {
            throw new MissingDependencyException("The form has not been set, please set the form before continuing");
        }
        return $this->form;
    }

    public function setForm(FormInterface $form): SearchService
    {
        $this->form = $form;
        return $this;
    }

    public function searchPolicies()
    {
        if (empty($this->form->getNormData())) {
            return [];
        }
        $data = $this->form->getNormData();
        if (!array_key_exists('invalid', $data)) {
            $data['invalid'] = 0;
        }
        unset($data['invalid']);
        $this->policyQb->eagerCursor(true);
        $userMap = [
            'email' => 'emailCanonical',
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'facebookId' => 'facebookId'
        ];

        $map = [
            'bacsReference' => 'paymentMethod.bankAccount.reference',
            'mobile' => 'mobileNumber',
            'paymentMethod' => 'paymentMethod.type',
            'policy' => 'policyNumber',
            'postcode' => 'billingAddress.postcode',
            'serial' => 'serialNumber'
        ];
        $fields = array_keys($data);
        $this->addStatusQuery($data['status']);
        foreach ($fields as $field) {
            if ($field === "status") {
                continue;
            } elseif (!empty($data[$field])) {
                if (array_key_exists($field, $map)) {
                    $this->policyQb->field($map[$field])->equals($data[$field]);
                } elseif (array_key_exists($field, $userMap)) {
                    $this->searchWithUsers = true;
                    $this->userQb->addAnd([$userMap[$field] => new \MongoRegex('/' . $data[$field] . '/i')]);
                } else {
                    $this->policyQb->field($field)->equals($data[$field]);
                }
            }
        }
        if ($this->searchWithUsers) {
            $users = $this->userQb->getQuery()->execute()->toArray();
            $searchUsers = [];
            foreach ($users as $user) {
                $searchUsers[] = $user->getId();
            }

            if (!empty($searchUsers)) {
                $this->policyQb->addAnd(
                    $this->policyQb->expr()->field('user.$id')->in($searchUsers)
                );
            }
        }
        return $this->sortResults($data['status']);
    }

    public function initQueryBuilders()
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        $userQb = $userRepo->createQueryBuilder();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policyQb = $policyRepo->createQueryBuilder();

        $this->userRepo = $userRepo;
        $this->userQb = $userQb;
        $this->policyRepo = $policyRepo;
        $this->policyQb = $policyQb;
    }

    private function addStatusQuery($status)
    {
        if ($status == 'current') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])
            );
        } elseif ($status == 'current-discounted') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])
            );
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('policyDiscountPresent')->equals(true)
            );
        } elseif ($status == 'past-due') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_CANCELLED])
            );
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('cancelledReason')->notIn([Policy::CANCELLED_UPGRADE])
            );
        } elseif ($status == 'call') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_UNPAID])
            );
        } elseif ($status == 'called') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('notesList.type')->equals('call')
            );
            $oneWeekAgo = \DateTime::createFromFormat('U', time());
            $oneWeekAgo = $oneWeekAgo->sub(new \DateInterval('P7D'));
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('notesList.date')->gte($oneWeekAgo)
            );
        } elseif ($status == Policy::STATUS_EXPIRED_CLAIMABLE) {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_EXPIRED_CLAIMABLE])
            );
        } elseif ($status == Policy::STATUS_EXPIRED_WAIT_CLAIM) {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_EXPIRED_WAIT_CLAIM])
            );
        } elseif ($status == Policy::STATUS_PENDING_RENEWAL) {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_PENDING_RENEWAL])
            );
        }
    }

    private function sortResults($status)
    {
        if ($status == Policy::STATUS_UNPAID) {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
        } elseif ($status == 'past-due') {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
            $policies = array_filter($policies, function ($policy) {
                return $policy->isCancelledAndPaymentOwed();
            });
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
        } elseif ($status == 'call') {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
            $policies = array_filter($policies, function ($policy) {
                /** @var Policy $policy */
                $fourteenDays = \DateTime::createFromFormat('U', time());
                $fourteenDays = $fourteenDays->sub(new \DateInterval('P14D'));
                $sevenDays = \DateTime::createFromFormat('U', time());
                $sevenDays = $fourteenDays->sub(new \DateInterval('P7D'));

                // 14 days & no calls or 7 days & at most 1 call
                if ($policy->getPolicyExpirationDateDays() <= 14 && $policy->getNoteCalledCount($fourteenDays) == 0) {
                    return true;
                } elseif ($policy->getPolicyExpirationDateDays() <= 7 &&
                    $policy->getNoteCalledCount($fourteenDays) <= 1) {
                    return true;
                } else {
                    return false;
                }
            });
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
        } else {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
        }
        return $policies;
    }
}
