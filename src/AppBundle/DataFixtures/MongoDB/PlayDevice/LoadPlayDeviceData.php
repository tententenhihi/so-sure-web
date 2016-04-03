<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PlayDevice;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadPlayDeviceData implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $row = 0;
        $file = sprintf(
            "%s/../src/AppBundle/DataFixtures/supported_devices.csv",
            $this->container->getParameter('kernel.root_dir')
        );
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row > 0) {
                    $this->newPlayDevice($manager, $data[0], $data[1], $data[2], $data[3]);
                }
                if ($row % 1000 == 0) {
                    $manager->flush();
                }
                $row = $row + 1;
            }
            fclose($handle);
        }

        $manager->flush();
    }

    private function newPlayDevice($manager, $retailBranding, $marketingName, $device, $model)
    {
        $playDevice = new PlayDevice();
        $playDevice->init($retailBranding, $marketingName, $device, $model);
        $manager->persist($playDevice);
    }
}
