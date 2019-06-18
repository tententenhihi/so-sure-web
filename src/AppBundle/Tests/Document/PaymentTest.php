<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Tests\RandomTestCase;

/**
 * @group unit
 */
class PaymentTest extends RandomTestCase
{
    use CurrencyTrait;
    use DateTrait;

    public function testCalculatePremium()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-05-01');
        $premium = $phonePrice->createPremium(null, $date);

        $phonePolicy = new SalvaPhonePolicy();
        $phonePolicy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount(5);
        $payment->setPolicy($phonePolicy);
        $payment->calculateSplit();
        $this->assertEquals(4.57, $this->toTwoDp($payment->getGwp()));
        $this->assertEquals(0.43, $this->toTwoDp($payment->getIpt()));
    }

    public function testTotalCommission()
    {
        $payment = new CheckoutPayment();

        // yearly
        $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::YEARLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::YEARLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // monthly
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // final month
        $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::FINAL_MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());

        // partial
        $payment->setTotalCommission(0.94);
        $this->assertEquals(0.88, $payment->getCoverholderCommission());
        $this->assertEquals(0.06, $payment->getBrokerCommission());
    }

    /**
     * @expectedException \Exception
     */
    public function testOverwriteSuccess()
    {
        $payment = new CheckoutPayment();
        $this->assertFalse($payment->hasSuccess());
        $payment->setSuccess(true);
        $this->assertTrue($payment->hasSuccess());
        $payment->setSuccess(false);
    }

    public function testSetCommissionMonthly()
    {
        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        for ($i = 0; $i < 11; $i++) {
            $payment = new CheckoutPayment();
            $payment->setAmount(6);
            $policy->addPayment($payment);
            $payment->setCommission();

            // monthly
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            $this->assertEquals(Salva::MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
            $this->assertEquals(Salva::MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
        }

        $payment = new CheckoutPayment();
        $payment->setAmount(6);
        $policy->addPayment($payment);
        $payment->setCommission();

        // final month
        $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
        $this->assertEquals(Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::FINAL_MONTHLY_BROKER_COMMISSION, $payment->getBrokerCommission());
    }

    public function testSetCommissionYearly()
    {
        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount(6 * 12);
        $policy->addPayment($payment);
        $payment->setCommission();

        // yearly
        $this->assertEquals(Salva::YEARLY_COVERHOLDER_COMMISSION, $payment->getCoverholderCommission());
        $this->assertEquals(Salva::YEARLY_BROKER_COMMISSION, $payment->getBrokerCommission());
    }

    /**
     * @expectedException \Exception
     */
    public function testSetCommissionUnknown()
    {
        $policy = new PhonePolicy();

        $premium = new PhonePremium();
        $premium->setIptRate(0.12);
        $premium->setGwp(5);
        $premium->setIpt(1);
        $policy->setPremium($premium);

        $payment = new CheckoutPayment();
        $payment->setAmount(2);
        $policy->addPayment($payment);
        $payment->setCommission();
    }

    public function testTimezone()
    {
        $payments = [];
        $payment1 = new CheckoutPayment();
        $payment1->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('UTC')));
        $payment1->setAmount(1);
        $payments[] = $payment1;

        $payment2 = new CheckoutPayment();
        $payment2->setDate(new \DateTime('2018-04-01 00:00', new \DateTimeZone('Europe/London')));
        $payment2->setAmount(2);
        $payments[] = $payment2;

        $daily = Payment::dailyPayments($payments, false, CheckoutPayment::class, new \DateTimeZone('UTC'));
        $this->assertEquals(1, $daily[1]);

        $daily = Payment::dailyPayments($payments, false, CheckoutPayment::class);
        $this->assertEquals(3, $daily[1]);
    }

    /**
     * Tests setting the commission for a partial payment.
     * @dataProvider randomFunctions
     */
    public function testSetCommissionPartialPayment($random)
    {
        $startDate = new \DateTime();
        $policy = $this->createEligiblePolicy(
            $startDate,
            $random(10, 10000) / 100,
            $random(10, 10000) / 100,
            $random(10, 10000) / 100
        );
        $n = $random(0, 10);
        $date = clone $startDate;
        for ($i = 0; $i < $n; $i++) {
            $this->addPayment($policy, $date);
            $date->add(new \DateInterval("P1M"));
        }
        // Now do the payment at some point in the future. Payment can be bigger or smaller than premium and can
        // occur very soon or far off, but within a year of premium start.
        $paymentDate = $this->addDays($date, $random(1, 360 - 30 * $n));
        $payment = new CheckoutPayment();
        $payment->setDate($paymentDate);
        $payment->setAmount($random(1, 500) / 7);
        $policy->addPayment($payment);
        // Make sure that the fractional commission is correct.
        // Should be equal to pro rata commission due.
        $payment->setCommission(true);
        $payment->setSuccess(true);
        $this->assertTrue($policy->getProratedCommission($paymentDate) == $policy->getTotalCommissionPaid());
    }

    /**
     * @expectedException \AppBundle\Exception\CommissionException
     */
    public function testSetCommissionRemainderFailsWithFalse()
    {
        $startDate = new \DateTime();
        $policy = $this->createEligiblePolicy($startDate, 5, 1, 0.12);

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 0.5);

        $policy->addPayment($payment);

        $payment->setCommission(false);
    }

    public function testSetCommissionRemainderDoesNotFailWithTrue()
    {
        $startDate = new \DateTime();
        $policy = $this->createEligiblePolicy($startDate, 5, 1, 0.12);

        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice() * 0.5);

        $policy->addPayment($payment);

        $payment->setCommission(true);

        $commission = $payment->getTotalCommission();
        $this->assertGreaterThan(0, $commission);
        static::assertLessThan(Salva::MONTHLY_TOTAL_COMMISSION, $commission);
    }

    /**
     * Creates a policy that is set up enough that it can calculate pro rata commission.
     * @param \DateTime $startDate is the date at which the policy starts.
     * @param float     $gwp       is the policy premium's GWP.
     * @param float     $ipt       is the policy premium's IPT.
     * @param float     $iptRate   is the policy premium's IPT rate.
     * @return Policy that has just been created.
     */
    private function createEligiblePolicy(\DateTime $startDate, $gwp, $ipt, $iptRate)
    {
        $nextYear = clone $startDate;
        $nextYear = $nextYear->modify('+1 year');
        $nextYear->modify("-1 day");
        $nextYear->setTime(23, 59, 59);
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($startDate);
        $policy->setEnd($nextYear);
        $policy->setStaticEnd($nextYear);
        $premium = new PhonePremium();
        $premium->setGwp($gwp);
        $premium->setIpt($ipt);
        $premium->setIptRate($iptRate);
        $premium->setIptRate($ipt / 12);
        $policy->setPremium($premium);
        return $policy;
    }

    /**
     * Create a normal premium payment for the given date and add it to the given policy.
     * @param Policy    $policy is the policy to add the payment to and whose premium is used.
     * @param \DateTime $date   is the date at which the payment occurs.
     */
    private function addPayment($policy, \DateTime $date)
    {
        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setDate($date);
        $policy->addPayment($payment);
        $payment->setCommission();
        $payment->setSuccess(true);
    }
}
