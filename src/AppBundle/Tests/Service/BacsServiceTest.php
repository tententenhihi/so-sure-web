<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 * AppBundle\\Tests\\Service\\BacsServiceTest
 */
class BacsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    protected static $container;
    protected static $xmlFile;
    protected static $bacsService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$xmlFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/bacs/ADDACS.xml",
            self::$container->getParameter('kernel.root_dir')
        );
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$bacsService = self::$container->get('app.bacs');
        self::$userManager = self::$container->get('fos_user.user_manager');
    }

    public function tearDown()
    {
    }

    public function testBacsXml()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testBacsXml', $this),
            'bar'
        );
        $bankAccount = new BankAccount();
        $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        $bankAccount->setReference('SOSURE01');
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($bankAccount);
        $user->setPaymentMethod($bacs);
        static::$dm->flush();

        $this->assertTrue(self::$bacsService->addacs(self::$xmlFile));

        $updatedUser = $this->assertUserExists(self::$container, $user);
        $this->assertEquals(
            BankAccount::MANDATE_CANCELLED,
            $updatedUser->getPaymentMethod()->getBankAccount()->getMandateStatus()
        );
    }
}
