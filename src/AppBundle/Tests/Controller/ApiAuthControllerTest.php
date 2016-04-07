<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;

/**
 * @group functional-net
 */
class ApiAuthControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    const VALID_IMEI = '356938035643809';
    const INVALID_IMEI = '356938035643808';
    const BLACKLISTED_IMEI = '352000067704506';

    protected static $testUser;
    protected static $testUser2;
    protected static $testUser3;
    protected static $client;
    protected static $userManager;
    protected static $dm;
    protected static $identity;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        self::$client = self::createClient();
        self::$identity = self::$client->getContainer()->get('app.cognito.identity');
        self::$dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        self::$userManager = self::$client->getContainer()->get('fos_user.user_manager');
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

    // auth

    /**
     *
     */
    public function testAuthRequiresIdentity()
    {
        $crawler = self::$client->request('POST', '/api/v1/auth/ping');
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    /**
     *
     */
    public function testGetIsAnon()
    {
        $crawler = self::$client->request('GET', '/api/v1/auth/ping');
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
    }

    // policy

    /**
     *
     */
    public function testNewPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertTrue(in_array('A0001', $data['phone']['devices']));
    }

    public function testNewPolicyMemoryExceeded()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 512,
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertEquals('128', $data['phone']['memory']);
    }

    public function testNewPolicyMemoryStandard()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 60,
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertEquals('64', $data['phone']['memory']);
    }

    public function testNewPolicyInvalidImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::INVALID_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
        ]);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyBlacklistedImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::BLACKLISTED_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
        ]);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED, $data['code']);
    }

    public function testNewPolicyMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);

        // missing imei
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        // missing memory
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        // missing device
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
            'make' => 'OnePlus',
            'memory' => 65,
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        // missing make
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
            'device' => 'A0001',
            'memory' => 65,
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyUnknownPhone()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);

        // missing imei
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
            'make' => 'foo',
            'device' => 'bar',
            'memory' => 65,
        ]);
        $this->assertEquals(404, self::$client->getResponse()->getStatusCode());
    }

    // policy/{id}

    /**
     *
     */
    public function testGetPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $createData = json_decode(self::$client->getResponse()->getContent(), true);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $getData = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertEquals($createData['id'], $getData['id']);
        $this->assertEquals($createData['imei'], $getData['imei']);
        $this->assertEquals($createData['user']['id'], $getData['user']['id']);
    }

    public function testGetPolicyUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/policy/1');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(404, self::$client->getResponse()->getStatusCode());
    }

    public function testGetPolicyUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getpolicy-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    // policy/{id}/dd

    /**
     *
     */
    public function testNewPolicyDdMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'sort_code' => '333333',
            'account_number' => '12345678',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'account_number' => '12345678',
            'account_name' => 'foo bar',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'sort_code' => '12345678',
            'account_name' => 'foo bar',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyDdUnknownPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'account_name' => 'foo bar',
        ]);
        $this->assertEquals(404, self::$client->getResponse()->getStatusCode());
    }

    /**
     *
     */
    public function testNewPolicyDdOk()
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
            'postcode' => 'ec1v 1rx',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $crawler = $this->createPolicy($cognitoIdentityId);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/dd", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'account_name' => 'foo bar',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
    }

    // policy/{id}/invitation

    /**
     *
     */
    public function testNewEmailInvitation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/invitation", $data['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
    }

    /**
     *
     */
    public function testNewSmsInvitation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/invitation", $data['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447775740466',
            'name' => 'functional test',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
    }

    // user/{id}

    /**
     *
     */
    public function testGetUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $data['email']);
    }

    public function testGetUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    public function testGetUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getuser-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    // put user/{id}

    /**
     *
     */
    public function testUpdateUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'first_name' => 'bar',
            'last_name' => 'foo',
            'email' => 'barfoo@auth-api.so-sure.com',
            'mobile_number' => '1234',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals('barfoo@auth-api.so-sure.com', $result['email']);
        $this->assertEquals('bar', $result['first_name']);
        $this->assertEquals('foo', $result['last_name']);
        $this->assertEquals('1234', $result['mobile_number']);
    }

    public function testUpdateUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser2->getId());
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'first_name' => 'bar',
        ]);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
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
            'postcode' => 'ec1v 1rx',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $result['email']);
        $this->assertTrue(isset($result['addresses']));
        $this->assertTrue(isset($result['addresses'][0]));
        $this->assertEquals($data['type'], $result['addresses'][0]['type']);
        $this->assertEquals($data['line1'], $result['addresses'][0]['line1']);
        $this->assertEquals($data['city'], $result['addresses'][0]['city']);
        $this->assertEquals($data['postcode'], $result['addresses'][0]['postcode']);
    }

    public function testUserAddAddressDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'ec1v 1rx',
        ]);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    // helpers

    /**
     *
     */
    protected function getAuthUser($user)
    {
        return static::authUser(self::$identity, $user);
    }

    protected function getUnauthIdentity()
    {
        return static::getIdentityString(self::$identity->getId());
    }

    protected function createPolicy($cognitoIdentityId)
    {
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
        ]);

        return $crawler;
    }
}
