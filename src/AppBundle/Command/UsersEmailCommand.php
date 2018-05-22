<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Repository\UserRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\JudopayService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;

class UsersEmailCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:users:email')
            ->setDescription('Email all users')
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'do not send warning email'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $skipEmail = true === $input->getOption('skip-email');
        /** @var UserRepository $repo */
        $repo = $this->getManager()->getRepository(User::class);
        $users = $repo->findAll();
        $output->writeln(sprintf('%d users', count($users)));
        foreach ($users as $user) {
            /** @var User $user */
            $this->emailUser($user);
            $output->writeln($user->getId());
        }

        $output->writeln('Finished');
    }

    private function emailUser(User $user)
    {
        $hash = SoSure::encodeCommunicationsHash($user->getEmail());
        /** @var MailerService $mailer */
        $mailer = $this->getContainer()->get('app.mailer');
        $mailer->sendTemplate(
            'Updated Privacy Policy',
            $user->getEmail(),
            'AppBundle:Email:user/updatedPrivacyPolicy.html.twig',
            ['user' => $user, 'hash' => $hash],
            'AppBundle:Email:user/updatedPrivacyPolicy.txt.twig',
            ['user' => $user, 'hash' => $hash]
        );


    }
}
