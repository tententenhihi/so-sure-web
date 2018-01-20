<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-net
 */
class DefaultControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testIndex()
    {
        $crawler = self::$client->request('GET', '/');
        self::verifyResponse(200);
    }

    public function testTagManager()
    {
        $crawler = self::$client->request('GET', '/');
        self::verifyResponse(200);
        $tag = self::$client->getContainer()->getParameter('ga_tag_manager_env');
        $body = self::$client->getResponse()->getContent();
        
        // Not a perfect test, but unable to test js code via symfony client
        // This should at least detect if the custom tag manager code environment was accidental removed
        $this->assertTrue(stripos($body, $tag) !== false);
    }

    public function testIndexRedirect()
    {
        $crawler = self::$client->request('GET', '/', [], [], ['REMOTE_ADDR' => '70.248.28.23']);
        self::verifyResponse(302);
    }

    public function testIndexFacebookNoRedirect()
    {
        $crawler = self::$client->request('GET', '/', [], [], [
            'REMOTE_ADDR' => '70.248.28.23',
            'HTTP_User-Agent' => "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)"
        ]);
        self::verifyResponse(200);
    }

    public function testIndexTwitterNoRedirect()
    {
        $crawler = self::$client->request('GET', '/', [], [], [
            'REMOTE_ADDR' => '70.248.28.23',
            'HTTP_User-Agent' => "twitterbot"
        ]);
        self::verifyResponse(200);
    }

    public function testQuotePhoneRouteMakeModelMemory()
    {
        $url = self::$router->generate('quote_make_model_memory', [
            'make' => 'Apple',
            'model' => 'iPhone+6S',
            'memory' => 64,
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }

    public function testQuotePhoneSpaceRouteMakeModelMemory()
    {
        $url = self::$router->generate('quote_make_model_memory', [
            'make' => 'Apple',
            'model' => 'iPhone 6S',
            'memory' => 64,
        ]);
        $redirectUrl = self::$router->generate('quote_make_model_memory', [
            'make' => 'Apple',
            'model' => 'iPhone+6S',
            'memory' => 64,
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(301);
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testQuotePhoneRouteMakeModel()
    {
        $crawler = self::$client->request('GET', self::$router->generate('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone+6S',
        ]));
        self::verifyResponse(200);
    }

    public function testQuotePhoneSpaceRouteMakeModel()
    {
        $url = self::$router->generate('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone 6S',
        ]);
        $redirectUrl = self::$router->generate('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone+6S',
        ]);

        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(301);
        $this->assertTrue(self::$client->getResponse()->isRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testQuotePhone()
    {
        $repo = self::$dm->getRepository(Phone::class);
        $phone = $repo->findOneBy(['devices' => 'iPhone 6', 'memory' => 64]);

        $crawler = self::$client->request('GET', self::$router->generate('quote_phone', [
            'id' => $phone->getId()
        ]));
        self::verifyResponse(301);
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
        $this->assertContains(
            sprintf("£%.2f", $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice()),
            self::$client->getResponse()->getContent()
        );
    }

    public function testTextAppInvalidMobile()
    {
        $crawler = self::$client->request('GET', self::$router->generate('sms_app_link'));
        self::verifyResponse(200);

        $form = $crawler->selectButton('Text me a link')->form();
        $form['sms_app_link[mobileNumber]'] = '123';
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->assertContains(
            "valid UK Mobile Number",
            self::$client->getResponse()->getContent()
        );
    }

    public function testTextAppLeadPresent()
    {
        $lead = new Lead();
        $lead->setMobileNumber(static::generateRandomMobile());
        self::$dm->persist($lead);
        self::$dm->flush();

        $crawler = self::$client->request('GET', self::$router->generate('sms_app_link'));
        self::verifyResponse(200);

        $form = $crawler->selectButton('Text me a link')->form();
        $form['sms_app_link[mobileNumber]'] = str_replace('+44', '', $lead->getMobileNumber());
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->assertContains(
            "already sent you a link",
            self::$client->getResponse()->getContent()
        );
    }
    public function testPhoneSearchVSGadget()
    {
        $crawler = self::$client->request('GET', '/so-sure-vs-gadget-cover-phone-insurance');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchVSHalifax()
    {
        $crawler = self::$client->request('GET', '/so-sure-vs-halifax-phone-insurance');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchVSThree()
    {
        $crawler = self::$client->request('GET', '/so-sure-vs-three-phone-insurance');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }
    public function testPhoneSearchHomepageV1()
    {
        $crawler = self::$client->request('GET', '/?force=v1');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);
    }

    public function testPhoneSearchHomepageV2()
    {
        $crawler = self::$client->request('GET', '/?force=v2');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 2);
    }

    public function testPhoneSearch()
    {
        //prepare list of all phones for the search
        self::$client->request('GET', '/search-phone-combined');
        $data = self::$client->getResponse();
        $this->assertEquals(200, $data->getStatusCode());
        $phones = json_decode($data->getContent(), true);

        //find iphone 7 for the test
        $onephone = array_filter($phones, function ($item) {
            return ($item['name'] === 'Apple iPhone 7') ? true : false;
        });

        // number of expected additional variants in memory dropdown menu.
        $numLinks = 2;

        foreach ($onephone as $phone) {
            $testPhone = new Phone();
            $testPhone->setModel($phone['name']);
            $name = $testPhone->getEncodedModel();

            //fetch page for each phone and save internal linking to alternative memory models
            $alternate = [];

            foreach ($phone['sizes'] as $size) {
                $alternate[$size['memory']] = [];
                //final url after 301
                $expected_redirect = sprintf(
                    PurchaseControllerTest::SEARCH_URL2_TEMPLATE,
                    $name,
                    $size['memory']
                );
                //initial url from search form
                $initial_url = sprintf(
                    PurchaseControllerTest::SEARCH_URL1_TEMPLATE,
                    $size['id']
                );
                //expecting 301, and redirect to proper named url
                self::$client->request('GET', $initial_url);
                $data = self::$client->getResponse();
                $this->assertEquals(301, $data->getStatusCode());
                $this->assertEquals($expected_redirect, $data->getTargetUrl());

                //load each page and fetch dropdown links for memory alternatives
                $crawler = self::$client->followRedirect();
                $data = self::$client->getResponse();
                foreach ($crawler->filter('.memory-dropdown')->filter('li')->filter('a') as $li) {
                    $link = $li->getAttribute('href');
                    if ($link == '#') {
                        continue;
                    }
                    $alternate[$size['memory']][$li->nodeValue] = $li->getAttribute('href');
                }
            }

            foreach (array_keys($alternate) as $key) {
                $this->assertEquals($numLinks, count($alternate[$key]));
                $this->areLinksValid($name, $key, array_keys($alternate), $alternate[$key]);
            }
        }
    }
    public function areLinksValid($name, $key, $allKeys, $phoneLinks)
    {
        //each page contains link to different memory models of the current phone
        $arrayLinks = array_values($phoneLinks);
        unset($allKeys[array_search($key, $allKeys)]);
        foreach ($allKeys as $memory) {
            //check if valid link to each memory model is present
            $expected_url = sprintf(
                PurchaseControllerTest::SEARCH_URL2_TEMPLATE,
                $name,
                $memory
            );
            $this->assertTrue(in_array($expected_url, $arrayLinks));
        }
    }
}
