<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\DateTrait;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\User;
use AppBundle\Exception\MonitorException;
use AppBundle\Form\Type\UserRoleType;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Service\InvitationService;
use AppBundle\Service\MonitorService;
use Doctrine\Tests\Common\DataFixtures\TestDocument\Role;
use Exception;
use AppBundle\Document\Invitation\EmailInvitation;
use FOS\UserBundle\Model\UserManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Claim;
use Symfony\Component\Validator\Constraints\Date;

/**
 * @group functional-nonet
 *
 * \\AppBundle\\Tests\\Service\\MonitorServiceTest
 */
class MonitorServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use DateTrait;

    protected static $container;

    /** @var Phone */
    protected static $phone;

    /** @var MonitorService */
    protected static $monitor;

    /** @var InvitationService */
    protected static $invitationService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');

        /** @var InvitationService invitationService */
        $invitationService = self::$container->get('app.invitation');
        $invitationService->setDebug(true);
        self::$invitationService = $invitationService;

        self::$invitationService->setEnvironment('test');

        /** @var UserManager userManager */
        self::$userManager = self::$container->get('fos_user.user_manager');

        /** @var MonitorService $monitor */
        $monitor = self::$container->get('app.monitor');
        self::$monitor = $monitor;
    }

    public function tearDown()
    {
        parent::tearDown();
    }

    public function setUp()
    {
        parent::setUp();
    }

    public function testClaimsSettledUnprocessedOk()
    {
        // should not be throwing an exception
        self::$monitor->claimsSettledUnprocessed();

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testMissingName()
    {
        $this->expectException(Exception::class);
        self::$monitor->run('foo');
    }

    public function testMissingPartialName()
    {
        $this->expectException(Exception::class);
        self::$monitor->run('claimsSettledUnprocessedFoo');
    }

    public function testClaimsSettledUnprocessedFalse()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setProcessed(false);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->expectException(MonitorException::class);

        self::$monitor->claimsSettledUnprocessed();
    }

    public function testClaimsSettledUnprocessedNull()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_LOSS);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->expectException(MonitorException::class);

        self::$monitor->claimsSettledUnprocessed();
    }

    public function testExpectedFailOldSubmittedClaimsUnit()
    {
        $daysAgo = $this->subBusinessDays(\DateTime::createFromFormat('U', time()), 3);

        // add a record that will make the monitor fail
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setStatusLastUpdated($daysAgo);
        $claim->setType(Claim::TYPE_LOSS);

        $this->expectException(MonitorException::class);

        self::$monitor->outstandingSubmittedClaims([$claim]);
    }

    public function testNoOldSubmittedClaimsSucceedsUnit()
    {
        self::$monitor->outstandingSubmittedClaims([]);
        $this->assertTrue(true, 'monitoring old submitted claims with no results succeeds');
    }

    public function testExpectedFailOldSubmittedClaimsFunctional()
    {
        $daysAgo = $this->subBusinessDays(\DateTime::createFromFormat('U', time()), 3);

        // add a record that will make the monitor fail
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setStatusLastUpdated($daysAgo);
        $claim->setType(Claim::TYPE_LOSS);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->assertSame($claim->getStatusLastUpdated(), $daysAgo);

        $this->expectException(MonitorException::class);
        $this->expectExceptionMessage('At least one Claim (eg: ');
        $this->expectExceptionMessage(") is still marked as 'Submitted' after 2 business days");

        self::$monitor->outstandingSubmittedClaims();

        // try to clean up, and remove the record
        self::$dm->remove($claim);
        self::$dm->flush();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testSalvaPolicy()
    {
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('salva', $this));
        $policy->setPolicyNumber(self::getRandomPolicyNumber('Mob'));

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->salvaPolicy();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testInvalidPolicy()
    {
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('invalid', $this));
        $policy->setPolicyNumber(self::getRandomPolicyNumber('INVALID'));
        $policy->addSalvaPolicyResults('0', SalvaPhonePolicy::RESULT_TYPE_CREATE, []);

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->invalidPolicy();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testSalvaStatus()
    {
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('salvastatus', $this));
        $policy->setPolicyNumber(self::getRandomPolicyNumber('Mob'));
        $policy->setSalvaStatus('pending');

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->salvaStatus();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testPolicyFiles()
    {
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('policy', $this));
        $policy->setPolicyNumber(self::getRandomPolicyNumber('Mob'));

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->policyFiles();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testPolicyPending()
    {
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('pending', $this));
        $policy->setPolicyNumber(self::getRandomPolicyNumber('Mob'));
        $policy->setStatus(Policy::STATUS_PENDING);

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);

        self::$dm->flush();

        self::$monitor->policyPending();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testDuplicateInvites()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testDuplicateInvites', $this),
            'bar'
        );

        $policy = self::initPolicy($user, self::$dm, $this->getRandomPhone(self::$dm), null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $invitationOne = self::$invitationService->inviteByEmail(
            $policy,
            self::generateEmail('testDuplicateInvites-invite-one', $this)
        );

        $invitationTwo = self::$invitationService->inviteByEmail(
            $policy,
            self::generateEmail('testDuplicateInvites-invite-two', $this)
        );

        /*
         * Generating two invites on the same email throws an error, changing one of the invites' email after
         * creation does not
         */
        $invitationTwo->setEmail(self::generateEmail('testDuplicateInvites-invite-one', $this));

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->persist($invitationOne);
        self::$dm->persist($invitationTwo);
        self::$dm->flush();

        self::$monitor->duplicateInvites();
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testCheckAllUserRolePriv()
    {
        // TODO: Do not hard-code database
        $database = self::$dm->getConnection()->selectDatabase('so-sure');

        $database->command([
            'createRole' => 'so-sure-user',
            'privileges' => [
                ['resource' => ['db' => 'so-sure', 'collection' => 'Phone'], 'actions' => ['find' , 'update', 'remove']]
            ],
            'roles' => []
        ]);

        $database->command([
            'createUser' => 'SOSUREUSER',
            'pwd' => 'sosure',
            'roles' => ['so-sure-user']
        ]);

        self::$monitor->checkAllUserRolePriv();

        $database->command([
            'dropUser' => 'SOSUREUSER'
        ]);

        $database->command([
            'dropRole' => 'so-sure-user'
        ]);
    }

    /**
     * @expectedException \AppBundle\Exception\MonitorException
     */
    public function testCheckExpiration()
    {
        $policy = self::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('expired', $this));
        $policy->setPolicyNumber(self::getRandomPolicyNumber('Mob'));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setEnd((\DateTime::createFromFormat('U', time()))->sub(new \DateInterval('P1D')));

        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();

        self::$monitor->checkExpiration();
    }
}
