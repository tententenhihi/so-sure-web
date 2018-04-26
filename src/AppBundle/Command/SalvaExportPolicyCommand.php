<?php

namespace AppBundle\Command;

use AppBundle\Service\SalvaExportService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class SalvaExportPolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:salva:export:policy')
            ->setDescription('Export all policies to salva')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date'
            )
            ->addOption(
                's3',
                null,
                InputOption::VALUE_NONE,
                'Upload to s3'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $s3 = true === $input->getOption('s3');
        $date = new \DateTime($input->getOption('date'));
        /** @var SalvaExportService $salva */
        $salva = $this->getContainer()->get('app.salva');
        $data = $salva->exportPolicies($s3, $date);
        $output->write(implode(PHP_EOL, $data));
        $output->writeln('');
    }
}
