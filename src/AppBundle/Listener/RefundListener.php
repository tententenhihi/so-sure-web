<?php

namespace AppBundle\Listener;

use AppBundle\Service\JudopayService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\Salva;
use AppBundle\Event\PolicyEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;

class RefundListener
{
    use CurrencyTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var JudopayService */
    protected $judopayService;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $environment;

    /**
     * @param DocumentManager $dm
     * @param JudopayService  $judopayService
     * @param LoggerInterface $logger
     * @param string          $environment
     */
    public function __construct(
        DocumentManager $dm,
        JudopayService $judopayService,
        LoggerInterface $logger,
        $environment
    ) {
        $this->dm = $dm;
        $this->judopayService = $judopayService;
        $this->logger = $logger;
        $this->environment = $environment;
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();

        // Cooloff cancellations should refund any so-sure payments
        if ($policy->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            $payments = $policy->getPayments();
            $total = Payment::sumPayments($payments, false, SoSurePayment::class);

            if (!$this->areEqualToTwoDp(0, $total['total'])) {
                $sosurePayment = SoSurePayment::init(Payment::SOURCE_SYSTEM);
                $sosurePayment->setAmount(0 - $total['total']);
                $sosurePayment->setTotalCommission(0 - $total['totalCommission']);
                $sosurePayment->setNotes(sprintf(
                    'cooloff cancellation refund of promo %s paid by so-sure',
                    $policy->getPromoCode()
                ));
                $policy->addPayment($sosurePayment);
                $this->dm->flush();
            }
        }

        $payment = $policy->getLastSuccessfulUserPaymentCredit();
        $refundAmount = $policy->getRefundAmount($event->getDate());
        $refundCommissionAmount = $policy->getRefundCommissionAmount($event->getDate());
        $this->logger->info(sprintf('Processing refund %f (policy %s)', $refundAmount, $policy->getId()));
        if ($refundAmount > 0) {
            if ($refundAmount > $payment->getAmount()) {
                $this->logger->error(sprintf(
                    'For policy %s, refund owed %f is greater than last payment received. Manual processing required.',
                    $policy->getId(),
                    $refundAmount
                ));

                return;
            }
            try {
                $this->judopayService->refund($payment, $refundAmount, $refundCommissionAmount, sprintf(
                    'cancelled %s',
                    $policy->getCancelledReason()
                ));
            } catch (\Exception $e) {
                $this->logger->error(
                    sprintf(
                        'Failed to refund policy %s for %0.2f. Fix issue, then manually cancel at salva.',
                        $policy->getId(),
                        $refundAmount
                    ),
                    ['exception' => $e]
                );
            }
        }

        if ($policy instanceof SalvaPhonePolicy) {
            // If refund was required, it's now finished (or exception thrown above, so skipped here)
            // Its now safe to allow the salva policy to be cancelled
            $policy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_PENDING_CANCELLED);
            $this->dm->flush();
        }
    }

    /**
     * @param PolicyEvent $event
     */
    public function refundFreeMonthPromo(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if (!in_array($policy->getPromoCode(), [
            Policy::PROMO_FREE_NOV,
            Policy::PROMO_LAUNCH_FREE_NOV,
            Policy::PROMO_FREE_DEC_2016,
        ])) {
            return;
        }

        $payment = $policy->getLastSuccessfulUserPaymentCredit();
        // Only run against JudoPayments
        if (!$payment instanceof JudoPayment) {
            return;
        }

        // Refund for Nov will break test cases
        if ($this->environment == "test") {
            return;
        }

        $refundAmount = $policy->getPremium()->getMonthlyPremiumPrice();
        $refundCommissionAmount = Salva::MONTHLY_TOTAL_COMMISSION;

        if ($refundAmount > $payment->getAmount()) {
            $this->logger->error(sprintf(
                'Manual processing required (policy %s), Promo %s refund %f is more than last payment.',
                $policy->getId(),
                $policy->getPromoCode(),
                $refundAmount
            ));

            return;
        }
        $this->judopayService->refund(
            $payment,
            $refundAmount,
            $refundCommissionAmount,
            sprintf('promo %s refund', $policy->getPromoCode()),
            Payment::SOURCE_SYSTEM
        );
        $sosurePayment = SoSurePayment::init(Payment::SOURCE_SYSTEM);
        $sosurePayment->setAmount($refundAmount);
        $sosurePayment->setTotalCommission($refundCommissionAmount);
        $sosurePayment->setNotes(sprintf('promo %s paid by so-sure', $policy->getPromoCode()));
        $policy->addPayment($sosurePayment);
        $this->dm->flush();
    }
}
