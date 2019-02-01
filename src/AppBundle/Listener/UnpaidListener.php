<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Service\MailerService;
use AppBundle\Service\SmsService;
use AppBundle\Service\FeatureService;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Event\ScheduledPaymentEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PaymentMethod;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Charge;
use AppBundle\Document\Feature;

/**
 * Manages unpaid comms and rescheduling of payments.
 */
class UnpaidListener
{
    use DateTrait;

    /** @var DocumentManager */
    protected $dm;
    /** @var MailerService */
    protected $mailerService;
    /** @var SmsService */
    protected $smsService;
    /** @var FeatureService */
    protected $featureService;

    /**
     * Constructs the listener.
     * @param DocumentManager $dm             is the document manager object.
     * @param MailerService   $mailerService  is the service's mail sender.
     * @param SmsService      $smsService     is the service's sms sender.
     * @param FeatureService  $featureService is used to tell the listener what features should be used.
     */
    public function __construct($dm, $mailerService, $smsService, $featureService)
    {
        $this->dm = $dm;
        $this->mailerService = $mailerService;
        $this->smsService = $smsService;
        $this->featureService = $featureService;
    }

    /**
     * Triggered when a scheduled payment for a given policy fails.
     * @param ScheduledPaymentEvent $event is the even containing the information that we need.
     */
    public function onUnpaidEvent(ScheduledPaymentEvent $event)
    {
        $scheduledPayment = $event->getScheduledPayment();
        $policy = $scheduledPayment->getPolicy();
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        $failedPayments = $scheduledPaymentRepo->countUnpaidScheduledPayments($policy);
        $nextScheduledPayment = null;
        if ($failedPayments <= 3) {
            /** @var ScheduledPayment $scheduledPayment */
            $scheduledPayment = $scheduledPaymentRepo->mostRecentWithStatuses(
                $policy,
                [ScheduledPayment::STATUS_FAILED, ScheduledPayment::STATUS_REVERTED]
            );
            if ($scheduledPayment) {
                $nextScheduledPayment = $scheduledPayment->reschedule($event->getDate());
                $policy->addScheduledPayment($nextScheduledPayment);
                $this->dm->flush();
            }
        }
        $this->emailNotification($policy, $failedPayments, $nextScheduledPayment);
        $this->smsNotification($policy, $failedPayments);
    }

    /**
     * Sends an email notifying the user that they have had a failed payment occur.
     * @param Policy           $policy               is the policy for which payment has failed.
     * @param int              $number               is the number of times failure has occurred including this time.
     * @param ScheduledPayment $nextScheduledPayment
     */
    public function emailNotification(Policy $policy, $number, ScheduledPayment $nextScheduledPayment = null)
    {
        if ($number < 1 || $number > 4) {
            return;
        }
        $folder = $this->selectFolder($policy);
        if (!$folder) {
            return;
        }

        $baseTemplate = $this->selectBaseEmail($policy);
        if (!$baseTemplate) {
            return;
        }
        $htmlTemplate = sprintf("AppBundle:Email:%s/%s-%d.html.twig", $folder, $baseTemplate, $number);
        $textTemplate = sprintf("AppBundle:Email:%s/%s-%d.txt.twig", $folder, $baseTemplate, $number);

        $subject = sprintf("Payment failure for your so-sure policy %s", $policy->getPolicyNumber());
        $this->mailerService->sendTemplateToUser(
            $subject,
            $policy->getUser(),
            $htmlTemplate,
            ["policy" => $policy, 'nextScheduledPayment' => $nextScheduledPayment],
            $textTemplate,
            ["policy" => $policy, 'nextScheduledPayment' => $nextScheduledPayment],
            null,
            $this->featureService->isEnabled(Feature::FEATURE_PAYMENTS_BCC) ? "bcc@so-sure.com" : null
        );
    }

    /**
     * Sends an sms warning the user that they are unpaid with special copy based on bacs vs judo. Only sends on the
     * second to fourth failures.
     * @param Policy $policy is the policy for which we are notifying.
     * @param int    $number is the number of times failure has occurred including this time.
     */
    private function smsNotification($policy, $number)
    {
        // TODO: Bacs sms copy
        if ($policy->getPolicyOrUserBacsPaymentMethod()) {
            return;
        }
        if ($number < 2 || $number > 4) {
            return;
        }
        $smsTemplate = sprintf("AppBundle:Sms:card/failedPayment-%d.txt.twig", $number);
        $this->smsService->sendUser($policy, $smsTemplate, ["policy" => $policy], Charge::TYPE_SMS_PAYMENT);
    }

    /**
     * Find the folder that the emails that will be in because the folder for judo emails is not called judo for some
     * reason.
     * @param Policy $policy is the policy that we are looking for the sake of.
     * @return string|null containing the name of the folder.
     */
    private function selectFolder(Policy $policy)
    {
        if ($policy->getPolicyOrUserBacsPaymentMethod()) {
            return "bacs";
        } elseif ($policy->getPolicyOrPayerOrUserJudoPaymentMethod()) {
            return "card";
        } else {
            return null;
        }
    }

    /**
     * Gets the base email template type to be sent to the user. This function differs for different payment method
     * types that the policy may have.
     * @param Policy $policy is the policy that the email is being sent for.
     * @return string|null containing the email base.
     */
    private function selectBaseEmail(Policy $policy)
    {
        if ($policy->getPolicyOrUserBacsPaymentMethod()) {
            if ($policy->hasMonetaryClaimed(true, true)) {
                return "bacsPaymentFailedWithClaim";
            } else {
                return "bacsPaymentFailed";
            }
        } elseif ($policy->getPolicyOrPayerOrUserJudoPaymentMethod()) {
            if ($policy->hasMonetaryClaimed(true, true)) {
                return "failedPaymentWithClaim";
            } elseif (!$policy->getPolicyOrPayerOrUserJudoPaymentMethod()->isValid()) {
                return "cardMissing";
            } else {
                return "failedPayment";
            }
        } else {
            return null;
        }
    }
}