<?php

namespace AppBundle\Command;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Repository\Invitation\EmailInvitationRepository;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Invitation\EmailInvitation;

class ReinviteCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:reinvite')
            ->setDescription('Email reinvitations')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'DateTime to check'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = $input->getOption('date');
        if (!$date) {
            $date = new \DateTime();
        } else {
            $date = new \DateTime($date);
        }

        /** @var InvitationService $invitationService */
        $invitationService = $this->getContainer()->get('app.invitation');
        /** @var EmailInvitationRepository $repo */
        $repo = $this->dm->getRepository(EmailInvitation::class);
        $invitations = $repo->findSystemReinvitations($date);

        foreach ($invitations as $invitation) {
            /** @var EmailInvitation $invitation */
            print sprintf("Reinviting %s\n", $invitation->getEmail());
            $invitationService->reinvite($invitation);
        }
    }
}
