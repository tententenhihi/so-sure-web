<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20220107;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Additions20220107 extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20220107.csv', new \DateTime('2022-01-07'));
    }
}
