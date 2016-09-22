<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\LostPhone;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\SCode;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use AppBundle\Document\Invitation\EmailInvitation;

/**
 * @group functional-net
 */
class ApiAuthControllerTest extends BaseControllerTest
{
    const VALID_IMEI = '356938035643809';
    const INVALID_IMEI = '356938035643808';
    const BLACKLISTED_IMEI = '352000067704506';
    const LOSTSTOLEN_IMEI = '351451208401216';
    const MISMATCH_SERIALNUMBER = '111111';

    protected static $testUser;
    protected static $testUser2;
    protected static $testUser3;
    protected static $testUserDisabled;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::$testUser = self::createUser(
            self::$userManager,
            'foo@auth-api.so-sure.com',
            'foo'
        );
        self::$testUser2 = self::createUser(
            self::$userManager,
            'bar@auth-api.so-sure.com',
            'bar'
        );
        self::$testUser3 = self::createUser(
            self::$userManager,
            'foobar@auth-api.so-sure.com',
            'barfoo'
        );
    }

    // address

    /**
     *
     */
    public function testAddress()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode=BX11LT&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals("so-sure Test Address Line 1", $data['line1']);
        $this->assertEquals("so-sure Test Address Line 2", $data['line2']);
        $this->assertEquals("so-sure Test City", $data['city']);
        $this->assertEquals("BX1 1LT", $data['postcode']);
    }

    public function testAddressValidation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode[$ne]=BX11LT&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testAddressQuote()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/address?postcode=%s&number=%s&_method=GET',
            urlencode('RG47RG'),
            urlencode("flat 6, 17 st peter's court")
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        /* actual data
        $this->assertEquals("Flat 6", $data['line1']);
        $this->assertEquals("RG4 7RG", $data['postcode']);
        */
        $this->assertEquals("Lock Keepers Cottage", $data['line1']);
        $this->assertEquals("WR5 3DA", $data['postcode']);
    }

    public function testAddressRateLimited()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode=BX11LT&_method=GET';

        // Run enough to trigger cognito rate limit
        for ($i = 0; $i < 4; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        }

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }

    /* TODO: Consider moving to a different type of test.
     * Note that once we're out of test mode mid-apr 2016,
     * then it should be possible to use this test
    public function testAddress()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/auth/address?postcode=WR53DA');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), json_encode($data));
        $this->assertEquals("Lock Keepers Cottage", $data['line1']);
        $this->assertEquals("Basin Road", $data['line2']);
        $this->assertEquals("Worcester", $data['city']);
        $this->assertEquals("WR5 3DA", $data['postcode']);
    }
    */

    /**
     *
     */
    public function testAddressReqParam()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode=&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400);
    }

    // invitation/{id}

    /**
     *
     */
    public function testInvitationCancel()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-cancel', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-cancel', $this),
            'name' => 'invite cancel test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'cancel']);
        $data = $this->verifyResponse(200);
    }

    public function testInvitationUnknown()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-unknown', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-unknown', $this),
            'name' => 'invite unknown action test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'foo']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testInvitationMissingActionAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-missing', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-missing', $this),
            'name' => 'invite missing action test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testInvitationReinviteLimitedAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite-limited', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite-limited', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::$dm->getRepository(EmailInvitation::class);
        $invitation = $emailRepo->find($invitationData['id']);
        $this->assertFalse($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_LIMIT);
    }

    public function testInvitationReinviteAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::$dm->getRepository(EmailInvitation::class);
        $invitation = $emailRepo->find($invitationData['id']);
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));
        self::$dm->flush();
        $this->assertTrue($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(200);
    }

    public function testInvitationReinviteFullPotAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite-maxpot', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite-maxpot', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::$dm->getRepository(EmailInvitation::class);
        $invitation = $emailRepo->find($invitationData['id']);
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));

        $policyRepo = self::$dm->getRepository(Policy::class);
        $policy = $policyRepo->find($policyData['id']);
        $policy->setPotValue($policy->getMaxPot());
        self::$dm->flush();
        $this->assertTrue($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_MAXPOT);
    }

    public function testInvitationReinviteClaimsAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite-claims', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite-claims', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::$dm->getRepository(EmailInvitation::class);
        $invitation = $emailRepo->find($invitationData['id']);
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));

        $policyRepo = self::$dm->getRepository(Policy::class);
        $policy = $policyRepo->find($policyData['id']);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);
        self::$dm->flush();
        $this->assertTrue($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_POLICY_HAS_CLAIM);
    }

    public function testInvitationAccept()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-accept-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-accept', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-accept-invitee', $this),
            'name' => 'invite accept test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $inviteeCognitoIdentityId, $url, [
            'action' => 'accept',
            'policy_id' => $inviteePolicyData['id']
        ]);
        $data = $this->verifyResponse(200);
    }

    public function testInvitationValidation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-validation-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-validation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-claimaccept-invitee', $this),
            'name' => ['$ne' => 'invite claimaccept test'],
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testInvitationAcceptWithClaims()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-claimaccept-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-claimaccept', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-claimaccept-invitee', $this),
            'name' => 'invite claimaccept test',
        ]);
        $invitationData = $this->verifyResponse(200);

        // Add claim to policy
        $policyRepo = self::$dm->getRepository(Policy::class);
        $policy = $policyRepo->find($policyData['id']);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);
        self::$dm->flush();

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $inviteeCognitoIdentityId, $url, [
            'action' => 'accept',
            'policy_id' => $inviteePolicyData['id']
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_POLICY_HAS_CLAIM);
    }

    public function testInvitationAcceptWithPotFull()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-fullpotaccept-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-fullpotaccept', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-fullpotaccept-invitee', $this),
            'name' => 'invite fullpotaccept test',
        ]);
        $invitationData = $this->verifyResponse(200);

        // set policy to max pot value
        $policyRepo = self::$dm->getRepository(Policy::class);
        $policy = $policyRepo->find($inviteePolicyData['id']);
        $policy->setPotValue($policy->getMaxPot());
        self::$dm->flush();

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $inviteeCognitoIdentityId, $url, [
            'action' => 'accept',
            'policy_id' => $inviteePolicyData['id']
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_MAXPOT);
    }

    // ping / auth

    /**
     *
     */
    public function testAuthRequiresIdentity()
    {
        $crawler = self::$client->request('POST', '/api/v1/auth/ping?_method=GET');
        $data = $this->verifyResponse(403);
    }

    /**
     *
     */
    public function testGetAnonIsAnon()
    {
        $crawler = self::$client->request('GET', '/api/v1/ping');
        $data = $this->verifyResponse(200, 0);
    }

    // policy

    /**
     *
     */
    public function testNewPolicy()
    {
        $this->clearRateLimit();
        $user = self::createUser(self::$userManager, self::generateEmail('policy', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'HTC',
            'device' => 'A0001',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);

        $data = $this->verifyResponse(200);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertTrue(in_array('A0001', $data['phone_policy']['phone']['devices']));
        $this->assertGreaterThan(0, $data['monthly_premium']);
        $this->assertGreaterThan(0, $data['yearly_premium']);

        // Now make sure that the policy shows up against the user
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $userData = $this->verifyResponse(200);

        $foundPolicy = false;
        foreach ($userData['policies'] as $policy) {
            if ($policy['id'] == $data['id']) {
                $foundPolicy = true;
            }
        }
        $this->assertTrue($foundPolicy);

        $repo = self::$dm->getRepository(Policy::class);
        $policy = $repo->find($data['id']);
        $this->assertTrue($policy !== null);
        $this->assertEquals('62.253.24.189', $policy->getIdentityLog()->getIp());
        $this->assertEquals('GB', $policy->getIdentityLog()->getCountry());
        $this->assertEquals([-0.13,51.5], $policy->getIdentityLog()->getLoc()->coordinates);
    }

    public function testNewPolicyNotRegulated()
    {
        $this->clearRateLimit();
        $user = self::createUser(self::$userManager, self::generateEmail('policy-notreg', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $imei = self::generateRandomImei();

        $redis = self::$client->getContainer()->get('snc_redis.default');
        $redis->set('ERROR_NOT_YET_REGULATED', 1);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'HTC',
            'device' => 'A0001',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_YET_REGULATED);

        $redis->del('ERROR_NOT_YET_REGULATED');
    }

    public function testNewPolicyDisabledUser()
    {
        $this->clearRateLimit();
        $userDisabled = self::createUser(self::$userManager, self::generateEmail('disabled', $this), 'foo');
        $cognitoIdentityId = $this->getAuthUser($userDisabled);
        $userDisabled->setEnabled(false);
        self::$dm->flush();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(403);
    }

    public function testNewPolicyExpiredUser()
    {
        $this->clearRateLimit();
        $userExpired = self::createUser(self::$userManager, self::generateEmail('expired', $this), 'foo');
        $cognitoIdentityId = $this->getAuthUser($userExpired);
        $userExpired->setExpired(true);
        self::$dm->flush();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(403);
    }

    public function testNewPolicyLockedUser()
    {
        $this->clearRateLimit();
        $userLocked = self::createUser(self::$userManager, self::generateEmail('locked', $this), 'foo');
        $cognitoIdentityId = $this->getAuthUser($userLocked);
        $userLocked->setLocked(true);
        self::$dm->flush();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(403);
    }

    public function testNewPolicyDuplicateImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser);
        $data = $this->verifyResponse(200);

        $imei = $data['phone_policy']['imei'];

        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_DUPLICATE_IMEI);
    }

    public function testNewPolicyRateLimited()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $limit = RateLimitService::$maxIpRequests[RateLimitService::DEVICE_TYPE_POLICY];
        for ($i = 1; $i <= $limit + 1; $i++) {
            $user = self::createUser(
                self::$userManager,
                self::generateEmail('rate-limit-email-' + $i, $this),
                'foo'
            );
            $cognitoIdentityId = $this->getAuthUser($user);
            $this->updateUserDetails($cognitoIdentityId, $user);
            $imei = self::generateRandomImei();
            $crawler = static::postRequest(
                self::$client,
                $cognitoIdentityId,
                '/api/v1/auth/policy',
                ['phone_policy' => [
                    'imei' => $imei,
                    'make' => 'OnePlus',
                    'device' => 'A0001',
                    'memory' => 65,
                    'rooted' => false,
                    'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
                ]]
            );
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }

    public function testNewPolicyInvalidUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser2);
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS);
    }

    public function testNewPolicyMemoryExceeded()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 512,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewPolicyMemoryStandard()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 60,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(200);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertEquals('64', $data['phone_policy']['phone']['memory']);
    }

    public function testNewPolicyInvalidImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => self::INVALID_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::INVALID_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_INVALID);
    }

    public function testNewPolicyBlacklistedImei()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-blacklist', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => self::BLACKLISTED_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::BLACKLISTED_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED);
    }

    public function testNewPolicyLostStolenImei()
    {
        $lostPhone = new LostPhone();
        $lostPhone->setImei(self::LOSTSTOLEN_IMEI);
        self::$dm->persist($lostPhone);

        $user = self::createUser(self::$userManager, self::generateEmail('policy-loststolen', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => self::LOSTSTOLEN_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::LOSTSTOLEN_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_LOSTSTOLEN);
    }

    public function testNewPolicyMismatchPhone()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-mismatch', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => self::MISMATCH_SERIALNUMBER,
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_PHONE_MISMATCH);
    }

    public function testNewPolicyOkPhone()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-ok-phone', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => "23423423342",
        ]]);
        $data = $this->verifyResponse(200);
    }

    public function testNewPolicyInvactivePhone()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-inactive-phone', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 4',
            'memory' => 15,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => "23423423342",
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewPolicyRooted()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => true,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED);
    }

    public function testNewPolicyMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);

        // missing imei
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::INVALID_IMEI]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing memory
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing device
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing make
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing rooted
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);
    }

    public function testNewPolicyUnknownPhone()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);

        // missing imei
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'foo',
            'device' => 'bar',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(404);
    }

    public function testNewPolicyYoungUser()
    {
        $this->clearRateLimit();
        $userYoung = self::createUser(self::$userManager, self::generateEmail('young', $this), 'foo');

        $now = new \DateTime();
        $userYoung->setBirthday(new \DateTime(sprintf("%d-01-01", $now->format('Y'))));
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($userYoung);
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS);
    }

    // policy/{id}

    /**
     *
     */
    public function testGetNullPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);

        $this->assertEquals($createData['id'], $getData['id']);
        $this->assertEquals($createData['phone_policy']['imei'], $getData['phone_policy']['imei']);
        $this->assertEquals(0, $createData['pot']['connections']);
        $this->assertEquals(0, $createData['pot']['max_connections']);
        $this->assertEquals(0, $createData['pot']['value']);
        $this->assertEquals(0, round($createData['pot']['max_value'], 2));
    }

    public function testGetPolicyUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/policy/1?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(404);
    }

    public function testGetPolicyUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getpolicy-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(403);
    }

    // policy/{id}/pay dd

    /**
     *
     */
    public function testNewPolicyDdMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'sort_code' => '333333',
                'account_number' => '12345678',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_number' => '12345678',
                'first_name' => 'foo',
                'last_name' => 'bar',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'sort_code' => '12345678',
                'first_name' => 'foo',
                'last_name' => 'bar',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_number' => '12345678',
                'sort_code' => '12345678',
                'first_name' => 'foo',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_number' => '12345678',
                'sort_code' => '12345678',
                'last_name' => 'bar',
            ]
        ]);
        $data = $this->verifyResponse(400);
    }

    public function testNewPolicyDdUnknownPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'sort_code' => '200000',
                'account_number' => '12345678',
                'first_name' => 'foo',
                'last_name' => 'bar',
            ]
        ]);
        $data = $this->verifyResponse(404);
    }

    public function testNewPolicyPayNotRegulated()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        self::$testUser->setFirstName('foo');
        self::$testUser->setLastName('bar');
        self::$dm->flush();

        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(200);

        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser);
        $data = $this->verifyResponse(200);

        $redis = self::$client->getContainer()->get('snc_redis.default');
        $redis->set('ERROR_NOT_YET_REGULATED', 1);

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_YET_REGULATED);

        $redis->del('ERROR_NOT_YET_REGULATED');
    }

    public function testNewPolicyJudopayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        $judopay = self::$client->getContainer()->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '6.99',
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);
        $this->assertEquals('launch', $policyData['promo_code']);
        $this->assertEquals(6, $policyData['pot']['max_connections']);
        $this->assertEquals(83.88, $policyData['pot']['max_value']);
        $highConnectionValue = 0;
        $lowConnectionValue = null;
        foreach ($policyData['pot']['connection_values'] as $connectionValue) {
            if ($connectionValue['value'] > $highConnectionValue) {
                $highConnectionValue = $connectionValue['value'];
            }
            if (!$lowConnectionValue || $connectionValue['value'] < $lowConnectionValue) {
                $lowConnectionValue = $connectionValue['value'];
            }
        }
        $this->assertEquals(15, $highConnectionValue);
        $this->assertEquals(2, $lowConnectionValue);
    }

    public function testNewPolicyJudopayDeclined()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-declined', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        $judopay = self::$client->getContainer()->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '6.99',
            '4221 6900 0000 4963',
            '12/20',
            '125'
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => null,
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED);
    }

    public function testNewPolicyJudopayInvalidPremium()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-invalidpremium', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        $judopay = self::$client->getContainer()->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '1.01',
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_INVALID_AMOUNT);
    }

    public function testNewPolicyJudopayDuplicate()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-dup', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        $judopay = self::$client->getContainer()->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '6.99',
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        // Ensure that policy creation didn't run twice
        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $repo->find($policyData['id']);
        $this->assertEquals(11, count($policy->getScheduledPayments()));
    }

    public function testNewPolicyJudopayUnpaidRepayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-repay', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        $judopay = self::$client->getContainer()->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '6.99',
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200001',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        // Ensure that policy creation didn't run twice
        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $repo->find($policyData['id']);
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $dm->flush();

        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '6.99',
            '4976 0000 0000 3436',
            '12/20',
            '452'
        );
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200001',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $repo->find($policyData['id']);
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policy->getStatus());

        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policyData['premium']);
        $this->assertEquals('monthly', $policyData['premium_plan']);
    }

    // policy/{id}/terms

    /**
     *
     */
    public function testPolicyTerms()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-ok-terms', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => "23423423342",
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s/terms?_method=GET', $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
    }

    // policy/{id}/invitation

    /**
     *
     */
    public function testNewEmailAndDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-email', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('patrick@so-sure.com', $data['invitation_detail']);
        $this->assertEquals('functional test', $data['name']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_DUPLICATE);
    }

    public function testEmailValidationInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-email-validate', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'notanemail',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewSmsAndDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-sms', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900002',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('+447700900002', $data['invitation_detail']);
        $this->assertEquals('functional test', $data['name']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900002',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_DUPLICATE);
    }

    public function testSmsValidationInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-sms-validate', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => 'notasms',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewSCodeInvitation()
    {
        $inviter = self::createUser(
            self::$userManager,
            self::generateEmail('inviter-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($inviter);
        $crawler = $this->generatePolicy($cognitoIdentityId, $inviter);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($inviter, $policyData['id']);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $policyRepo->find($policyData['id']);
        $scode = $policy->getStandardSCode()->getCode();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'scode' => $scode,
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('foo bar', $data['name']);
    }

    public function testNameConformInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invite-name-conform', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'name' => 'functional test$',
            'email' => $this->generateEmail('invite-name-conform-invite', $this)
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('functional test', $data['name']);
    }

    public function testSCodeValidationInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-scode-validate', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'scode' => 'not-an-scode',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testSentInvitationAppears()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('sent-invitation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => $this->generateEmail('invite-appears', $this),
            'name' => 'Invitation Name',
        ]);
        $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $policyData = $this->verifyResponse(200);
        $this->assertTrue(count($policyData['sent_invitations']) > 0);
        $foundInvitation = false;
        foreach ($policyData['sent_invitations'] as $invitation) {
            if ($invitation['name'] == "Invitation Name") {
                $foundInvitation = true;
            }
        }
        $this->assertTrue($foundInvitation);
    }

    public function testReceivedInvitationAppears()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser2);
        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser2);
        $data = $this->verifyResponse(200);

        $this->payPolicy(self::$testUser2, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $data['id']);

        //print sprintf("Invite from %s to %s", self::$testUser2->getName(), self::$testUser->getName());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::$testUser->getEmail(),
            'name' => self::$testUser->getName(),
        ]);
        $invitationData = $this->verifyResponse(200);

        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET&debug=true', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $userData = $this->verifyResponse(200);
        $this->assertTrue(count($userData['received_invitations']) > 0);
        $foundInvitation = false;
        foreach ($userData['received_invitations'] as $invitation) {
            if ($invitation['id'] == $invitationData['id']) {
                $foundInvitation = true;
                $this->assertEquals(self::$testUser2->getId(), $invitation['inviter_id']);
                // http://aruljohn.com/gravatar/
                // bar@auth-api.so-sure.com (testUser2) -> 0b1cac52ee6250748998bf4e2ccc29b1
                $this->assertEquals(
                    'https://www.gravatar.com/avatar/0b1cac52ee6250748998bf4e2ccc29b1?d=404&s=100',
                    $invitation['image_url']
                );
            }
        }
        $this->assertTrue($foundInvitation);
    }

    public function testUnableToInviteSelf()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-notself', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invitation-notself', $this),
            'name' => 'Invitation Name',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_SELF_INVITATION);
    }

    public function testUnableToCrossInvite()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-cross', $this),
            'foo'
        );
        $user2 = self::createUser(
            self::$userManager,
            self::generateEmail('cross-invitation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('cross-invitation', $this),
            'name' => 'Invitation Name',
        ]);
        $invitationData = $this->verifyResponse(200);

        $cognitoIdentityId2 = $this->getAuthUser($user2);
        $crawler = $this->generatePolicy($cognitoIdentityId2, $user2);
        $policyData2 = $this->verifyResponse(200);

        $this->payPolicy($user2, $policyData2['id']);
        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId2, $url, [
            'action' => 'accept',
            'policy_id' => $policyData2['id'],
        ]);

        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData2['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId2, $url, [
            'email' => self::generateEmail('invitation-cross', $this),
            'name' => 'Invitation Name',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_CONNECTED);
    }

    // scode

    /**
     *
     */
    public function testCreatePolicySCode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $policyRepo->find($policyId);
        $oldCode = $policy->getStandardSCode()->getCode();
        $policy->getStandardSCode()->setActive(false);
        $dm->flush();

        $url = sprintf('/api/v1/auth/scode');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => SCode::TYPE_STANDARD,
            'policy_id' => $policyId,
        ]);
        $getData = $this->verifyResponse(200);
        $this->assertEquals(8, strlen($getData['code']));
        $this->assertEquals(SCode::TYPE_STANDARD, $getData['type']);
        $this->assertNotEquals($oldCode, $getData['code']);
    }

    public function testCreatePolicySCodeMissingParams()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-scode-notype', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $url = sprintf('/api/v1/auth/scode');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'policy_id' => $policyId,
        ]);
        $getData = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => SCode::TYPE_STANDARD,
        ]);
        $getData = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testCreatePolicySCodeDuplicate()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-scode-duptype', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $url = sprintf('/api/v1/auth/scode');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => SCode::TYPE_STANDARD,
            'policy_id' => $policyId,
        ]);
        $getData = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    // DELETE scode/{id}

    /**
     *
     */
    public function testDeletePolicySCode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-del-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $policyRepo->find($policyId);
        $sCode = $policy->getStandardSCode()->getCode();

        $url = sprintf('/api/v1/auth/scode/%s?_method=DELETE', $sCode);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $policyRepo->find($policyId);
        $this->assertNull($policy->getStandardSCode());
    }

    public function testDeletePolicyInactiveSCode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-delete-policy-inactive-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $policyRepo->find($policyId);
        $sCode = $policy->getStandardSCode();

        $sCode->setActive(false);
        $dm->flush();

        $url = sprintf('/api/v1/auth/scode/%s?_method=DELETE', $sCode->getCode());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    // policy/{id}/terms

    /**
     *
     */
    public function testGetPolicyTerms()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s/terms?maxPotValue=62.8&_method=GET', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);
        $policyUrl = self::$router->generate('policy_terms', ['id' => $policyId]);
        //print $getData["view_url"];
        $this->assertTrue(stripos($getData["view_url"], $policyUrl) >= 0);
        $this->assertTrue(stripos($getData["view_url"], 'http') >= 0);
    }

    // secret

    /**
     *
     */
    public function testAuthSecret()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/secret?_method=GET', []);
        $data = $this->verifyResponse(200);
        $this->assertEquals('ThisTokenIsNotSoSecretChangeIt', $data['secret']);
    }

    // user

    /**
     *
     */
    public function testGetCurrentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $data['email']);
    }

    // user/{id}

    /**
     *
     */
    public function testGetUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $data['email']);
    }

    public function testGetUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $result = $this->verifyResponse(403);
    }

    public function testGetUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getuser-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $result = $this->verifyResponse(403);
    }

    // put user/{id}

    /**
     *
     */
    public function testUpdateUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'first_name' => 'bar',
            'last_name' => 'foo',
            'email' => 'barfoo@auth-api.so-sure.com',
            'mobile_number' => '+447700900000',
            'facebook_id' => 'abcd',
            'facebook_access_token' => 'zy',
            'birthday' => $birthday->format(\DateTime::ATOM),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals('barfoo@auth-api.so-sure.com', $result['email']);
        $this->assertEquals('bar', $result['first_name']);
        $this->assertEquals('foo', $result['last_name']);
        $this->assertEquals('+447700900000', $result['mobile_number']);
        $this->assertEquals('abcd', $result['facebook_id']);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->find($result['id']);
        $this->assertEquals($birthday, $user->getBirthday());
    }

    public function testUpdateUserBadName()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'first_name' => 'bar$',
            'last_name' => 'foo$',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals('bar', $result['first_name']);
        $this->assertEquals('foo', $result['last_name']);
    }

    public function testUpdateFacebook()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'first_name' => 'bar',
            'last_name' => 'foo',
            'email' => 'barfoo@auth-api.so-sure.com',
            'mobile_number' => '+447700900000',
            'facebook_id' => 'abcd',
            'facebook_access_token' => 'zy',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals('abcd', $result['facebook_id']);

        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, ['last_name' => 'barfoo']);
        $result = $this->verifyResponse(200);
        $this->assertEquals('abcd', $result['facebook_id']);

        // facebook update needs auth token as well
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'facebook_id' => 'barfoo'
        ]);
        $result = $this->verifyResponse(200);
        $this->assertEquals('abcd', $result['facebook_id']);

        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'facebook_id' => 'barfoo',
            'facebook_access_token' => 'lala'
        ]);
        $result = $this->verifyResponse(200);
        $this->assertEquals('barfoo', $result['facebook_id']);
    }

    public function testUpdateUserValidation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'first_name' => ['$ne' => 'bar']
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser2->getId());
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'first_name' => 'bar',
        ]);
        $data = $this->verifyResponse(403);
    }

    public function testUpdateUserInvalidEmail()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'email' => 'barfoo@',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserInvalidMobile()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'email' => static::generateEmail('invalid-mobile', $this),
            'mobile_number' => '+44770090000',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserEmailExists()
    {
        $originalUser = self::createUser(
            self::$userManager,
            self::generateEmail('update-user-email-exists', $this),
            'foo'
        );

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('update-user-email-new', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $data = [
            'email' => self::generateEmail('update-user-email-exists', $this),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testUpdateUserInvalidBirthday()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'birthday' => 'abc',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserTooOld()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'birthday' => '1800-01-01T00:00:00Z',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserTooYoung()
    {
        $now = new \DateTime();
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'birthday' => sprintf('%d-01-01T00:00:00Z', $now->format('Y')),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_TOO_YOUNG);
    }

    public function testUpdateUserSCode()
    {
        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $scode = new SCode();
        $dm->persist($scode);
        $dm->flush();

        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'scode' => $scode->getCode(),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->find($result['id']);
        $this->assertEquals($scode->getId(), $user->getAcceptedSCode()->getId());
    }

    public function testUpdateUserInactiveSCode()
    {
        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $scode = new SCode();
        $scode->setActive(false);
        $dm->persist($scode);
        $dm->flush();

        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'scode' => $scode->getCode(),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testUpdateUserSnsEndpoint()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-a', $this),
            'foo'
        );
        $userA->setSnsEndpoint('foo');

        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-b', $this),
            'foo'
        );
        $userB->setSnsEndpoint('foo');

        $userC = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-c', $this),
            'foo'
        );

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $dm->flush();

        $cognitoIdentityId = $this->getAuthUser($userC);
        $url = sprintf('/api/v1/auth/user/%s', $userC->getId());
        $data = [
            'sns_endpoint' => 'foo',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);

        $changedUserA = $userRepo->findOneBy(['email' => $userA->getEmail()]);
        $changedUserB = $userRepo->findOneBy(['email' => $userB->getEmail()]);
        $changedUserC = $userRepo->findOneBy(['email' => $userC->getEmail()]);
        $this->assertEquals('foo', $changedUserC->getSnsEndpoint());
        $this->assertNull($changedUserA->getSnsEndpoint());
        $this->assertNull($changedUserB->getSnsEndpoint());

        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $userC->getId());
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, []);
        $result = $this->verifyResponse(200);
        $this->assertEquals('foo', $result['sns_endpoint']);
    }

    public function testUpdateUserChangeNameWithPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('update-user-change-name', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $data = [
            'first_name' => 'first',
            'last_name' => 'last',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals('first', $result['first_name']);
        $this->assertEquals('last', $result['last_name']);

        // note this changes the user's name
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->find($user->getId());
        $this->assertTrue($user->hasValidPolicy());

        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $data = [
            'first_name' => 'newfirst',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $data = [
            'last_name' => 'newlast',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    // user/{id}/address

    /**
     *
     */
    public function testUserAddAddress()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $result['email']);
        $this->assertTrue(isset($result['addresses']));
        $this->assertTrue(isset($result['addresses'][0]));
        $this->assertEquals($data['type'], $result['addresses'][0]['type']);
        $this->assertEquals($data['line1'], $result['addresses'][0]['line1']);
        $this->assertEquals($data['city'], $result['addresses'][0]['city']);
        $this->assertEquals($data['postcode'], $result['addresses'][0]['postcode']);
    }

    public function testUserAddAddressBadLines()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1$',
            'line2' => 'address line 2$',
            'line3' => 'address line 3$',
            'city' => 'London$',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $result['email']);
        $this->assertTrue(isset($result['addresses']));
        $this->assertTrue(isset($result['addresses'][0]));
        $this->assertEquals($data['type'], $result['addresses'][0]['type']);
        $this->assertEquals('address line 1', $result['addresses'][0]['line1']);
        $this->assertEquals('address line 2', $result['addresses'][0]['line2']);
        $this->assertEquals('address line 3', $result['addresses'][0]['line3']);
        $this->assertEquals('London', $result['addresses'][0]['city']);
        $this->assertEquals('BX11LT', $result['addresses'][0]['postcode']);
    }

    public function testUserAddressValidation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'line2' => ['$ne' => '1'],
            'city' => 'London',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUserAddAddressDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'BX11LT',
        ]);
        $data = $this->verifyResponse(403);
    }

    public function testUserInvalidAddress()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'ZZ99 3CZ',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_INVALID_ADDRESS);
    }

    // helpers

    /**
     *
     */
    protected function generatePolicy($cognitoIdentityId, $user, $clearRateLimit = true)
    {
        if ($user) {
            $this->updateUserDetails($cognitoIdentityId, $user);
        }

        if ($clearRateLimit) {
            $this->clearRateLimit();
        }
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'serial_number' => 'foo',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->verifyResponse(200);

        return $crawler;
    }

    protected function updateUserDetails($cognitoIdentityId, $user)
    {
        $userUpdateUrl = sprintf('/api/v1/auth/user/%s', $user->getId());
        $birthday = new \DateTime('1980-01-01');
        static::putRequest(self::$client, $cognitoIdentityId, $userUpdateUrl, [
            'first_name' => 'foo',
            'last_name' => 'bar',
            'mobile_number' => static::generateRandomMobile(),
            'birthday' => $birthday->format(\DateTime::ATOM),
        ]);
        $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/user/%s/address', $user->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->verifyResponse(200);
    }

    protected function payPolicy($user, $policyId)
    {
        // Reload user to get address
        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->find($user->getId());

        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        $policy = $policyRepo->find($policyId);

        $payment = new JudoPayment();
        $dm->persist($payment);
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $policy->addPayment($payment);
        $user->addPolicy($policy);

        static::$policyService->create($policy);
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $dm->flush();
        $this->assertNotNull($payment->getId());
        $this->assertNotNull($policy->getUser());
        $this->assertNotNull($policy->getUser());
        $this->assertNotNull($policy->getUser()->getBillingAddress());
        $this->assertNotNull($policy->getUser()->getBillingAddress()->getLine1());
        /*
        $url = sprintf("/api/v1/auth/policy/%s/pay", $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_PENDING, $policyData['status']);
        $this->assertEquals($policyId, $policyData['id']);
        */
    }
}
