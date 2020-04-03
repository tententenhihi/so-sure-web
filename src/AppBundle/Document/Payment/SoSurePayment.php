<?php

namespace AppBundle\Document\Payment;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class SoSurePayment extends Payment
{
    public static function init($source)
    {
        $sosurePayment = new SoSurePayment();
        $sosurePayment->setSuccess(true);
        $sosurePayment->setSource($source);

        return $sosurePayment;
    }

    public function isSuccess()
    {
        return $this->success;
    }

    public function isUserPayment()
    {
        return false;
    }

    /**
     * Judopay specific logic for whether to show a payment to users.
     * @inheritDoc
     */
    public function isVisibleUserPayment()
    {
        if ($this->areEqualToTwoDp(0, $this->amount)) {
            return false;
        } elseif ($this->notes == 'Referral Refund') {
            return false;
        }

        return $this->success;
    }

    /**
     * Gives user facing description of sosure payment.
     * @inheritDoc
     */
    protected function userPaymentName()
    {
        if ($this->amount < 0) {
            return "so-sure adjustment";
        } elseif ($this->notes == 'Referral Bonus') {
            return $this->notes;
        } else {
            return "Payment by so-sure";
        }
    }
}
