<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class MixpanelCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:mixpanel')
            ->setDescription('Run mixpanel test')
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'email address of user'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getOption('email');
        $user = null;
        if ($email) {
            $user = $this->getUser($email);
        }
        $results = $this->getMixpanel()->track("button clicked", array("label" => "sign-up"), $user);
        $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
    }
    
    private function getMixpanel()
    {
        return $this->getContainer()->get('app.mixpanel');
    }

    private function getUser($email)
    {
        $repo = $this->getUserRepository();
        $user = $repo->findOneBy(['emailCanonical' => strtolower($email)]);
        if (!$user) {
            throw new \Exception('unable to find user');
        }

        return $user;
    }

    private function getUserRepository()
    {
        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $repo = $dm->getRepository(User::class);

        return $repo;
    }
}
