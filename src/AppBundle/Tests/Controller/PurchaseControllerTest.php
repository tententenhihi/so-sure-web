<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\LostPhone;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Lead;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use AppBundle\Classes\Salva;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\ReceperioService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\PurchaseControllerTest
 */
class PurchaseControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    const SEARCH_URL1_TEMPLATE = '/phone-insurance/%s';
    const SEARCH_URL2_TEMPLATE = '/phone-insurance/%s+%sGB';
    const LOSTSTOLEN_IMEI = '351451208401216';

    protected static $rootDir;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function tearDown()
    {
        //self::$client->request('GET', '/logout');
        //self::$client->followRedirect();
        self::$client->getCookieJar()->clear();
    }

    public function testPurchaseOkNew()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $email = self::generateEmail('testPurchaseNew', $this);
        $crawler = $this->createPurchase(
            $email,
            'foo bar',
            new \DateTime('1980-01-01')
        );

        //self::verifyResponse(302);
        //$this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-missing-phone'));

        $dm = $this->getDocumentManager();
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $now = new \DateTime();

        $this->assertNotNull($user->getIdentityLog());
        $diff = $user->getIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);

        $this->assertNotNull($user->getLatestWebIdentityLog());
        $diff = $user->getLatestWebIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);
    }

    public function testPurchaseUserPhoneSpaceNew()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseUserPhoneSpaceNew', $this),
            'foo bar',
            new \DateTime('1980-01-01'),
            implode(' ', str_split(self::generateRandomMobile(), 1))
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchaseExistingUserDiffDetailsNew()
    {
        $phone = $this->setRandomPhone();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserDiffDetailsNew', $this),
            'foo'
        );

        $crawler = $this->createPurchaseUserNew($user, 'not me', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchaseExistingUserWithPolicyDiffDetailsNew()
    {
        $phone = $this->setRandomPhone();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserWithPolicyDiffDetailsNew', $this),
            'foo'
        );
        // TODO: add policy
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $crawler = $this->createPurchaseUserNew($user, 'not me', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/login'));
    }

    public function testPurchaseExistingUserSameDetailsNew()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsNew', $this),
            'foo',
            self::getRandomPhone(self::$dm)
        );

        $crawler = $this->createPurchaseUserNew($user, 'foo bar', new \DateTime('1980-01-01'));
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchaseExistingUserSameDetailsWithPartialPolicyNew()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsWithPartialPolicyNew', $this),
            'foo',
            $phone
        );
        self::initPolicy($user, self::$dm, $phone);

        $crawler = $this->createPurchaseUserNew($user, 'foo bar', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchaseExistingUserSameDetailsWithMultiplePartialPolicyNew()
    {
        $phone = self::getRandomPhone(self::$dm);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsWithMultiplePartialPolicyNew', $this),
            'foo',
            $phone
        );
        $policy1 = self::initPolicy($user, self::$dm, $phone);
        sleep(1);
        $policy2 = self::initPolicy($user, self::$dm, $phone);

        $this->login($user->getEmail(), 'foo', 'user/invalid');

        $crawler = $this->setPhone($phone, $policy1->getImei());

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(
            sprintf('/purchase/step-phone/%s', $policy1->getId())
        ));
    }

    public function testPurchaseAddressNew()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseAddressNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchasePhone()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneNoPledge()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneNoPledge', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler, false);
        //print $crawler->html();
        self::verifyResponse(200);
    }

    /*
    public function testPurchasePhoneNewUploadFile()
    {
        $phone = $this->setRandomPhone('Apple');

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneNewUploadFile', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone, null, true, null, null, true);
        //print $crawler->html();
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
        $this->verifyImei($crawler, '355424073417084', 'C77QMB7SGRY9');
    }
    */

    /**
     *
     */
    public function testPurchasePhoneImeiSpaceNineSixtyEightNew()
    {
        $phoneRepo = static::$dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone = $phoneRepo->findOneBy(['devices' => 'zeroflte', 'memory' => 128]);
        //$phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSpaceNineSixtyEightNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiSpaceNew()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSpaceNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneLostImei()
    {
        $lostPhone = new LostPhone();
        $lostPhone->setImei(self::LOSTSTOLEN_IMEI);
        self::$dm->persist($lostPhone);
        self::$dm->flush();

        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneLostImei', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone, self::LOSTSTOLEN_IMEI);

        self::verifyResponse(200);
        $this->expectFlashError($crawler, 'Sorry, it looks this phone is already insured');
    }

    public function testPurchaseChangePhone()
    {
        $phone = $this->setRandomPhone();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseChangePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains($phone->getModel(), $crawler->html());

        $phone2 = $this->getRandomPhone(static::$dm);
        $crawler = $this->changePhone($phone2);
        self::verifyResponse(200);
        $this->assertContains($phone2->getModel(), $crawler->html());
        $this->assertNotEquals($phone2->getId(), $phone->getId());
    }

    public function testRePurchase()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRePurchase', $this),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        static::$policyService->expire($policy, new \DateTime('2017-01-01'));

        $this->login($user->getEmail(), 'bar', 'user/invalid');

        $crawler = self::$client->request(
            'GET',
            self::$router->generate('user_repurchase_policy', ['id' => $policy->getId()])
        );
        $crawler = self::$client->followRedirect();
        //print_r($crawler->html());
        self::verifyResponse(200);
        $this->assertEquals('readonly', $crawler->filter('#purchase_form_imei')->attr('readonly'));
        $this->assertNotEquals('readonly', $crawler->filter('#purchase_form__token')->attr('readonly'));
    }

    public function testPurchasePhoneImeiDashNew()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiDashNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode('-', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiSlashNew()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSlashNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode('/', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiS7New()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiS7New', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = sprintf('%s/71', self::generateRandomImei());
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchaseReviewToJudopay()
    {
        // unable to implement test
        // form post needs to be direct to judopay
        // symfony tests are unable to perform client side events
    }

    public function testPurchaseReviewWithAcceptNew()
    {
        $phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseReviewRequiresAcceptNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchaseReviewWithInvalidSerial()
    {
        $phone = self::getRandomPhone(static::$dm, 'Apple');

        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        $crawler = self::$client->followRedirect();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseReviewWithInvalidSerial', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = self::$client->request(
            'GET',
            self::$router->generate('purchase_step_phone')
        );
        self::verifyResponse(200, null, $crawler);
        $this->assertNotContains('Get a Quote', $crawler->html());

        $crawler = $this->setPhone($phone, null, null, ReceperioService::TEST_INVALID_SERIAL);

        self::verifyResponse(200);
        $this->expectFlashError($crawler, 'phone model');
    }

    public function testPurchaseReviewNotIOSWithNoPhoneSession()
    {
        $phone = self::getRandomPhone(static::$dm, 'Apple');
        $email = self::generateEmail('testPurchaseReviewNotIOSWithNoPhoneSession', $this);

        $user = static::createUser(
            static::$userManager,
            $email,
            'bar',
            static::$dm
        );
        static::addAddress($user);
        static::$dm->flush();

        $this->logout();
        self::$client->getCookieJar()->clear();

        $this->login($email, 'bar');

        $crawler = self::$client->request(
            'GET',
            self::$router->generate('purchase_step_phone')
        );
        self::verifyResponse(200, null, $crawler);
        $this->assertContains('Get a Quote', $crawler->html());
    }

    public function testPurchaseReviewIOSWithNoPhoneSession()
    {
        $phone = self::getRandomPhone(static::$dm, 'Apple');
        $email = self::generateEmail('testPurchaseReviewIOSWithNoPhoneSession', $this);

        $user = static::createUser(
            static::$userManager,
            $email,
            'bar',
            static::$dm
        );
        static::addAddress($user);
        static::$dm->flush();

        $this->logout();
        self::$client->getCookieJar()->clear();

        $this->login($email, 'bar');

        // @codingStandardsIgnoreStart
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('purchase_step_phone'),
            [],
            [],
            ['HTTP_User-Agent' => "Mozilla/5.0 (iPhone; CPU iPhone OS 9_1 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13B143 Safari/601.1"]
        );
        // @codingStandardsIgnoreEnd
        self::verifyResponse(200, null, $crawler);
        $this->assertContains('Get a Quote', $crawler->html());
    }

    public function testPayCC()
    {
        $dm = $this->getDocumentManager();
        $email = self::generateEmail('testPayCC', $this);
        $password = 'foo';
        $phone = self::getRandomPhone($dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            $dm
        );
        $policy = self::initPolicy($user, $dm, $phone);
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setReference($policy->getId());
        $payment->setUser($user);
        $policy->addPayment($payment);
        $dm->flush();

        $response = static::runJudoPayPayment(
            self::$container->get('app.judopay'),
            $user,
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        // change to use a flash message for purchase
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);

        self::verifyResponse(302);
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testPayCCRetry()
    {
        $dm = $this->getDocumentManager();
        $email = self::generateEmail('testPayCCRetry', $this);
        $password = 'foo';
        $phone = self::getRandomPhone($dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            $dm
        );
        $policy = self::initPolicy($user, $dm, $phone);
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setReference($policy->getId());
        $payment->setUser($user);
        $policy->addPayment($payment);
        $dm->flush();

        $response = static::runJudoPayPayment(
            self::$container->get('app.judopay'),
            $user,
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        // change to use a flash message for purchase
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);
        self::verifyResponse(302);
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);

        self::verifyResponse(302);
        // TODO: Current user welcome will only be displayed if one payment has been made,
        // but should check the actual paid amount on policy as well in the future
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function test2ndPolicyPayCC()
    {
        $email = self::generateEmail('test2ndPolicyPayCC', $this);
        $password = 'foo';
        $phone1 = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone1,
            self::$dm
        );

        $policy1 = self::initPolicy($user, self::$dm, $phone1, null, false, false);

        $judopay = self::$container->get('app.judopay');
        $policyService = self::$container->get('app.policy');
        $details = $judopay->testPayDetails(
            $user,
            $policy1->getId(),
            $phone1->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $policyService->setEnvironment('prod');
        // @codingStandardsIgnoreStart
        $judopay->add(
            $policy1,
            $details['receiptId'],
            $details['consumer']['consumerToken'],
            $details['cardDetails']['cardToken'],
            Payment::SOURCE_WEB_API,
            "{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}"
        );
        // @codingStandardsIgnoreEnd
        $policyService->setEnvironment('test');
        $this->assertTrue($user->hasValidPaymentMethod());

        $this->login($email, $password, 'user/');

        $phone2 = $this->setRandomPhone();
        $this->setPhone($phone2);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPaymentExisting($crawler, $phone2);
        self::verifyResponse(302);
        //$this->verifyPurchaseNotReady($crawler);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $userRepo->find($user->getId());

        $latestPolicy = $updatedUser->getLatestPolicy();
        $this->assertNotNull($latestPolicy);
        if ($latestPolicy) {
            $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $latestPolicy->getId()]);
            $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
            self::$client->followRedirect();
            self::verifyResponse(200);
        }
    }

    public function testLeadSource()
    {
        $email = self::generateEmail('testLeadSource', $this);
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $leadRepo = $dm->getRepository(Lead::class);
        /** @var Lead $lead */
        $lead = $leadRepo->findOneBy(['email' => mb_strtolower($email)]);
        $this->assertNotNull($lead);
        $this->assertEquals(mb_strtolower($email), $lead->getEmail());
        $this->assertEquals('foo bar', $lead->getName());
    }

    public function testLeadSourceMissingParam()
    {
        $email = self::generateEmail('testLeadSourceMissingParam', $this);

        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
            ])
        );
        self::verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testLeadSourceInvalidCsrf()
    {
        $email = self::generateEmail('testLeadSourceInvalidCsrf', $this);
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf') + '.';

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testLeadSourceBadName()
    {
        $email = self::generateEmail('testLeadSourceBadName', $this);
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $leadRepo = $dm->getRepository(Lead::class);
        /** @var Lead $lead */
        $lead = $leadRepo->findOneBy(['email' => mb_strtolower($email)]);
        $this->assertNotNull($lead);
        $this->assertEquals(mb_strtolower($email), $lead->getEmail());
        $this->assertNull($lead->getName());
    }

    private function createPurchaseUser($user, $name, $birthday)
    {
        $this->createPurchase($user->getEmail(), $name, $birthday, $user->getMobileNumber());
    }

    private function createPurchaseUserNew($user, $name, $birthday)
    {
        $this->createPurchase($user->getEmail(), $name, $birthday, $user->getMobileNumber());
    }

    private function verifyPurchaseReady($crawler)
    {
        $form = $crawler->filterXPath('//form[@id="webpay-form"]')->form();
        $this->assertContains('judopay', $form->getUri());
    }

    private function verifyPurchaseNotReady($crawler)
    {
        $form = $crawler->filterXPath('//form[@id="webpay-form"]')->form();
        $this->assertNotContains('judopay', $form->getUri());
    }

    private function verifyImei(Crawler $crawler, $imei, $serialNumber = null)
    {
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $this->assertEquals($imei, $form['purchase_form[imei]']->getValue());
        if ($serialNumber) {
            $this->assertEquals($serialNumber, $form['purchase_form[serialNumber]']->getValue());
        }
    }

    private function setPhone(
        $phone,
        $imei = null,
        $crawler = null,
        $serialNumber = null
    ) {
        if (!$crawler) {
            $crawler = self::$client->request('GET', '/purchase/step-phone');
        }
        //print $crawler->html();
        $form = $crawler->selectButton('purchase_form[next]')->form();

        //if (!$imei && !$useFile) {
        if (!$imei) {
            $imei = self::generateRandomImei();
        }
        $form['purchase_form[imei]'] = $imei;
        if ($phone->isApple()) {
            // use a different number in case we're testing /, -, etc
            //if (!$serialNumber && !$useFile) {
            if (!$serialNumber) {
                $serialNumber = self::generateRandomAppleSerialNumber();
            }
            $form['purchase_form[serialNumber]'] = $serialNumber;
        }
        /*
        if ($useFile) {
            $imeiFile = sprintf(
                "%s/../src/AppBundle/Tests/Resources/iPhoneSettings.png",
                self::$rootDir
            );
            $imei = new UploadedFile(
                $imeiFile,
                'imei.png',
                'image/png',
                95062
            );
            $form['purchase_form[file]']->upload($imei);
        }
        */
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function agreePledge(Crawler $crawler, $agreed = 1)
    {
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $agreedCheckboxes = ['agreedDamage', 'agreedAgeLocation', 'agreedTerms'];
        foreach ($agreedCheckboxes as $checkbox) {
            if ($agreed) {
                try {
                    $form['purchase_form[' . $checkbox . ']'] = $agreed;
                } catch (\Exception $e) {
                    $form['purchase_form[' . $checkbox . ']'] = 'checked';
                }
            }
        }
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setPayment(Crawler $crawler, $phone)
    {
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[amount]'] = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice();
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setPaymentExisting(Crawler $crawler, $phone)
    {
        $form = $crawler->selectButton('purchase_form[existing]')->form();
        $form['purchase_form[amount]'] = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice();
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function changePhone($phone)
    {
        $crawler = self::$client->request('GET', sprintf('/select-phone-search/purchase-change/%s', $phone->getId()));
        $crawler = self::$client->followRedirect();

        return $crawler;
    }

    private function createPurchase($email, $name, $birthday, $mobile = null)
    {
        if (!$mobile) {
            $mobile = self::generateRandomMobile();
        }
        $crawler = self::$client->request('GET', '/purchase/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $email;
        $form['purchase_form[name]'] = $name;
        $form['purchase_form[birthday]'] = sprintf("%s", $birthday->format('d/m/Y'));
        $form['purchase_form[mobileNumber]'] = $mobile;
        $form['purchase_form[addressLine1]'] = '123 Foo St';
        $form['purchase_form[city]'] = 'Unknown';
        $form['purchase_form[postcode]'] = 'BX1 1LT';
        $crawler = self::$client->submit($form);

        return $crawler;
    }
    
    private function setRandomPhone($make = null)
    {
        $phone = self::getRandomPhone(self::$dm, $make);
        // set phone in session
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('quote_phone', ['id' => $phone->getId()])
        );
        self::$client->followRedirect();

        return $phone;
    }

    public function testLeadInvalidEmail()
    {
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => 'foo',
                'name' => 'foo',
                'csrf' => $csrfToken,
            ])
        );

        self::verifyResponse(200, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testLeadInvalidName()
    {
        $email = self::generateEmail('testLeadInvalidName', $this);
        $this->setRandomPhone();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200, ApiErrorCode::SUCCESS);
    }

    public function testPhoneSearchPurchasePage()
    {
        $crawler = self::$client->request('GET', '/purchase/');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/select-phone-dropdown');
    }

    public function testPhoneSearchUserInvalidPolicy()
    {
        $email = self::generateEmail('testPhoneSearchUserInvalid', $this);
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
        $crawler = $this->login($email, $password, 'user/invalid');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }
}
