<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\UserControllerTest
 */
class UserControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
    }

    public function testUserOk()
    {
        $email = self::generateEmail('testUserOk', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);

        $crawler = self::$client->request('GET', '/user/');

        $this->validateBonus($crawler, 14, 14);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    public function testUserOk2ndCliff()
    {
        $email = self::generateEmail('testUserOk2ndCliff', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = new \DateTime();
        $cliffDate = $cliffDate->sub(new \DateInterval('P14D'));
        $cliffDate = $cliffDate->sub(new \DateInterval('PT2S'));
        $policy = self::initPolicy($user, self::$dm, $phone, $cliffDate, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');

        $this->validateBonus($crawler, 46, 46);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    public function testUserOkFinal()
    {
        $email = self::generateEmail('testUserOkFinal', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = new \DateTime();
        $cliffDate = $cliffDate->sub(new \DateInterval('P60D'));
        $cliffDate = $cliffDate->sub(new \DateInterval('PT1H'));
        $policy = self::initPolicy($user, self::$dm, $phone, $cliffDate, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');

        // todo - will fail during leap year
        $this->validateBonus($crawler, [304, 305], [304, 305]);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    public function testUserClaimed()
    {
        $email = self::generateEmail('testUserClaimed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setNumber(rand(1, 999999));
        $claimService = self::$container->get('app.claims');
        $claimService->addClaim($policy, $claim);

        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');

        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, false);
    }

    public function testUserInvite()
    {
        $email = self::generateEmail('testUserInvite-inviter', $this);
        $inviteeEmail = self::generateEmail('testUserInvite-invitee', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('email[submit]')->form();
        $form['email[email]'] = $inviteeEmail;
        $crawler = self::$client->submit($form);

        $this->login($inviteeEmail, $password, 'user/');
        $crawler = self::$client->request('GET', '/user/');
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user/');
        $this->validateRewardPot($crawler, 10);
    }

    public function testUserInviteOptOut()
    {
        $email = self::generateEmail('testUserInviteOptOut', $this);
        $inviteeEmail = 'foo@so-sure.com';
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('email[submit]')->form();
        $form['email[email]'] = $inviteeEmail;
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
    }

    public function testUserSCode()
    {
        $email = self::generateEmail('testUserSCode-inviter', $this);
        $inviteeEmail = self::generateEmail('testUserSCode-invitee', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user/');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', sprintf('/scode/%s', $inviteePolicy->getStandardSCode()->getCode()));
        self::verifyResponse(200);
        self::$client->followRedirects(false);

        $form = $crawler->selectButton('scode[submit]')->form();
        $this->assertEquals($inviteePolicy->getStandardSCode()->getCode(), $form['scode[scode]']->getValue());
        $crawler = self::$client->submit($form);

        $this->login($inviteeEmail, $password, 'user/');
        $crawler = self::$client->request('GET', '/user/');
        // print $crawler->html();
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user/');
        $this->validateRewardPot($crawler, 10);
    }

    private function validateInviteAllowed($crawler, $allowed)
    {
        $count = 0;
        if ($allowed) {
            $count = 1;
        }
        $this->assertEquals($count, $crawler->evaluate('count(//a[@id="add-connect"])')[0]);
    }

    private function validateRewardPot($crawler, $amount)
    {
        $this->assertEquals(
            $amount,
            $crawler->filterXPath('//div[@id="reward-pot-chart"]')->attr('data-pot-value')
        );
    }

    private function validateBonus($crawler, $daysRemaining, $daysTotal)
    {
        $chart = $crawler->filterXPath('//div[@id="connection-bonus-chart"]');
        $actualRemaining = $chart->attr('data-bonus-days-remaining');
        $actualTotal = $chart->attr('data-bonus-days-total');
        if (is_array($daysRemaining)) {
            $this->assertTrue(in_array($actualRemaining, $daysRemaining));
        } else {
            $this->assertEquals($daysRemaining, $actualRemaining);
        }
        if (is_array($daysTotal)) {
            $this->assertTrue(in_array($actualTotal, $daysTotal));
        } else {
            $this->assertEquals($daysTotal, $actualTotal);
        }
    }

    private function validateRenewalAllowed($crawler, $allowed)
    {
        $count = 0;
        if ($allowed) {
            $count = 1;
        }
        $this->assertEquals($count, $crawler->evaluate('count(//li[@id="user-homepage--nav-renew"])')[0]);
    }

    public function testUserUnpaidPolicy()
    {
        $email = self::generateEmail('testUserUnpaid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/unpaid');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);
    }

    public function testUserUnpaidPolicyPaymentDetails()
    {
        $email = self::generateEmail('testUserUnpaidPolicyPaymentDetails', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/unpaid', '/user/payment-details');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/payment-details');
        self::$client->followRedirects(false);
        $this->assertEquals(
            sprintf('http://localhost/user/unpaid'),
            self::$client->getHistory()->current()->getUri()
        );
    }

    public function testUserInvalidPolicy()
    {
        $email = self::generateEmail('testUserInvalid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::$dm->flush();
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(200);
    }

    public function testUserPolicyCancelledAndPaymentOwed()
    {
        $email = self::generateEmail('testUserPolicyCancelledAndPaymentOwed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setCancelledReason(Policy::CANCELLED_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->isCancelledAndPaymentOwed());
        $this->login($email, $password, sprintf('purchase/remainder/%s', $policy->getId()));
    }

    public function testUserAccessDenied()
    {
        $emailA = self::generateEmail('testUserAccessDenied-A', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $policyA = self::initPolicy($userA, self::$dm, $phone, null, true, true);
        $policyA->setStatus(Policy::STATUS_ACTIVE);

        $emailB = self::generateEmail('testUserAccessDenied-B', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $policyB = self::initPolicy($userB, self::$dm, $phone, null, true, true);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policyA->getUser()->hasActivePolicy());
        $this->assertTrue($policyB->getUser()->hasActivePolicy());
        $this->login($emailA, $password, 'user/');

        $crawler = self::$client->request('GET', sprintf('/user/%s', $policyA->getId()));
        self::verifyResponse(200);

        $crawler = self::$client->request('GET', sprintf('/user/%s', $policyB->getId()));
        self::verifyResponse(403);
    }

    public function testUserRenewSimple()
    {
        $email = self::generateEmail('testUserRenewSimple', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', '/user/renew');

        $form = $crawler->selectButton('renew_form[renew]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicy->getStatus());
    }

    public function testUserRenewCustomMonthly()
    {
        $email = self::generateEmail('testUserRenewCustomMonthly', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policy->getId()));

        $form = $crawler->selectButton('renew_form[renew]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicy->getStatus());
    }

    public function testUserRenewCustomMonthlyDecline()
    {
        $email = self::generateEmail('testUserRenewCustomMonthlyDecline', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policy->getId()));

        $form = $crawler->selectButton('decline_form[decline]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $updatedRenewalPolicy->getStatus());
        $this->assertNull($updatedRenewalPolicy->getPreviousPolicy()->getCashback());
    }

    public function testUserRenewCashbackCustomMonthly()
    {
        $emailA = self::generateEmail('testUserRenewCashbackCustomMonthlyA', $this);
        $emailB = self::generateEmail('testUserRenewCashbackCustomMonthlyB', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policyA = self::initPolicy($userA, self::$dm, $phone, $date, true, true, false);
        $policyB = self::initPolicy($userB, self::$dm, $phone, $date, true, true, false);
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policyA->isRenewed());
        $this->assertFalse($policyB->isRenewed());
        
        self::connectPolicies(self::$invitationService, $policyA, $policyB, $date);

        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicyA = $this->getRenewalPolicy($policyA, false, $tomorrow);
        $renewalPolicyB = $this->getRenewalPolicy($policyB, false, $tomorrow);
        static::$dm->persist($renewalPolicyA);
        static::$dm->persist($renewalPolicyB);
        static::$dm->flush();
        
        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $crawler = $this->login($emailA, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policyA->getId()));

        $form = $crawler->selectButton('renew_cashback_form[renew]')->form();
        $form['renew_cashback_form[accountName]'] = 'foo bar';
        $form['renew_cashback_form[sortCode]'] = '123456';
        $form['renew_cashback_form[accountNumber]'] = '12345678';
        $form['renew_cashback_form[encodedAmount]'] =
            sprintf('%0.2f|12|0', $renewalPolicyA->getPremium()->getYearlyPremiumPrice());
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $repo->find($renewalPolicyA->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicyA->getStatus());
        $this->assertNotNull($updatedRenewalPolicyA->getPreviousPolicy()->getCashback());
    }

    public function testUserRenewCashbackCustomDeclined()
    {
        $emailA = self::generateEmail('testUserRenewCashbackCustomDeclinedA', $this);
        $emailB = self::generateEmail('testUserRenewCashbackCustomDeclinedB', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $date = new \DateTime();
        $date = $date->sub(new \DateInterval('P350D'));
        $policyA = self::initPolicy($userA, self::$dm, $phone, $date, true, true, false);
        $policyB = self::initPolicy($userB, self::$dm, $phone, $date, true, true, false);
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policyA->isRenewed());
        $this->assertFalse($policyB->isRenewed());
        
        self::connectPolicies(self::$invitationService, $policyA, $policyB, $date);
        $tomorrow = new \DateTime();
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicyA = $this->getRenewalPolicy($policyA, false, $tomorrow);
        $renewalPolicyB = $this->getRenewalPolicy($policyB, false, $tomorrow);
        static::$dm->persist($renewalPolicyA);
        static::$dm->persist($renewalPolicyB);
        static::$dm->flush();
        
        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $crawler = $this->login($emailA, $password, 'user/');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policyA->getId()));

        $form = $crawler->selectButton('cashback_form[cashback]')->form();
        $form['cashback_form[accountName]'] = 'foo bar';
        $form['cashback_form[sortCode]'] = '123456';
        $form['cashback_form[accountNumber]'] = '12345678';
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedRenewalPolicyA = $repo->find($renewalPolicyA->getId());
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $updatedRenewalPolicyA->getStatus());
        $this->assertNotNull($updatedRenewalPolicyA->getPreviousPolicy()->getCashback());
    }

    public function testUserFormRateLimit()
    {
        $this->clearRateLimit();

        $email = self::generateEmail('testUserRateLimit', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        for ($i = 1; $i < 25; $i++) {
            $this->login($email, 'bar', 'login');
        }

        $this->login($email, 'bar', 'login', null, null);

        // expect a locked account
        $this->login($email, 'bar', 'login', null, 503);
    }
    public function testUserWelcomePage()
    {
        $email = self::generateEmail('testUserWelcomePage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        $welcomePage = sprintf('/user/welcome/%s', $policy->getId());
        // initial flag is false
        $this->login($email, $password);
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': false",
            self::$client->getResponse()->getContent()
        );
        // set after first show to true
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': true",
            self::$client->getResponse()->getContent()
        );
        // consistent after repeated show
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': true",
            self::$client->getResponse()->getContent()
        );
    }
}
