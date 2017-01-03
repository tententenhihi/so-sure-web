<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;

class ValidatePolicyCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:policy:validate')
            ->setDescription('Validate policy payments')
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy prefix'
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Pretent its this date'
            )
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Show scheduled payments for a policy'
            )
            ->addOption(
                'update-pot-value',
                null,
                InputOption::VALUE_NONE,
                'If policy number is present, update pot value if required'
            )
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'Do not send email report if present'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lines = [];
        $date = $input->getOption('date');
        $prefix = $input->getOption('prefix');
        $policyNumber = $input->getOption('policyNumber');
        $updatePotValue = $input->getOption('update-pot-value');
        $skipEmail = $input->getOption('skip-email');
        $validateDate = null;
        if ($date) {
            $validateDate = new \DateTime($date);
        }

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $policyRepo = $dm->getRepository(Policy::class);

        if ($policyNumber) {
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }

            $valid = $policy->isPolicyPaidToDate(true, $validateDate);
            $lines[] = sprintf('Policy %s %s paid to date', $policyNumber, $valid ? 'is' : 'is NOT');
            if (!$valid) {
                $lines[] = $this->failurePaymentMessage($policy, $prefix, $validateDate);
            }

            $valid = $policy->hasCorrectPolicyStatus($validateDate);
            if ($valid === false) {
                $lines[] = $this->failureStatusMessage($policy, $prefix, $validateDate);
            }

            $valid = $policy->isPotValueCorrect();
            $lines[] = sprintf(
                'Policy %s %s the correct pot value',
                $policyNumber,
                $valid ? 'has' : 'does NOT have'
            );
            if (!$valid) {
                $lines[] = $this->failurePotValueMessage($policy);
                if ($updatePotValue) {
                    $policy->updatePotValue();
                    $dm->flush();
                    $lines[] = 'Updated pot value';
                    $lines[] = $this->failurePotValueMessage($policy);
                }
            }

            $valid = $policy->arePolicyScheduledPaymentsCorrect($prefix, $validateDate);
            $lines[] = sprintf(
                'Policy %s %s correct scheduled payments',
                $policyNumber,
                $valid ? 'has' : 'does NOT have'
            );
            if (!$valid) {
                $lines[] = $this->failureScheduledPaymentsMessage($policy, $prefix, $validateDate);
            }
        } else {
            $policies = $policyRepo->findAll();
            foreach ($policies as $policy) {
                if ($prefix && !$policy->hasPolicyPrefix($prefix)) {
                    continue;
                }
                // TODO: Run financials for cancelled policies as well
                if ($policy->isCancelled()) {
                    continue;
                }
                if ($policy->isPolicyPaidToDate(true, $validateDate) === false) {
                    $lines[] = sprintf(
                        'Policy %s is not paid to date. Next attempt on %s. If unpaid, policy will be cancelled on %s',
                        $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
                        $policy->getNextScheduledPayment() ?
                            $policy->getNextScheduledPayment()->getScheduled()->format(\DateTime::ATOM) :
                            'unknown',
                        $policy->getPolicyExpirationDate() ?
                            $policy->getPolicyExpirationDate()->format(\DateTime::ATOM) :
                            'unknown'
                    );
                    $lines[] = $this->failurePaymentMessage($policy, $prefix, $validateDate);
                }
                if ($policy->isPotValueCorrect() === false) {
                    $lines[] = sprintf('Policy %s has incorrect pot value', $policy->getPolicyNumber());
                    $lines[] = $this->failurePotValueMessage($policy);
                }
                if ($policy->arePolicyScheduledPaymentsCorrect($prefix, $validateDate) === false) {
                    $lines[] = sprintf('Policy %s has incorrect scheduled payments', $policy->getPolicyNumber());
                    $lines[] = $this->failureScheduledPaymentsMessage($policy, $prefix, $validateDate);
                }
                if ($policy->hasCorrectPolicyStatus($validateDate) === false) {
                    $lines[] = $this->failureStatusMessage($policy, $prefix, $validateDate);
                }
            }
        }

        if (!$skipEmail) {
            $mailer = $this->getContainer()->get('app.mailer');
            $mailer->send('Policy Validation', 'tech@so-sure.com', implode('<br />', $lines));
        }

        $output->writeln(implode(PHP_EOL, $lines));
        $output->writeln('Finished');
    }

    private function failureStatusMessage($policy, $prefix, $date)
    {
        return sprintf(
            'Policy %s has unexpected status %s',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $policy->getStatus()
        );
    }

    private function failurePaymentMessage($policy, $prefix, $date)
    {
        $totalPaid = $policy->getTotalSuccessfulPayments($date);
        $expectedPaid = $policy->getTotalExpectedPaidToDate($date);
        return sprintf(
            'Policy %s Paid £%0.2f Expected £%0.2f',
            $policy->getPolicyNumber() ? $policy->getPolicyNumber() : $policy->getId(),
            $totalPaid,
            $expectedPaid
        );
    }

    private function failurePotValueMessage($policy)
    {
        return sprintf(
            'Policy %s Pot Value £%0.2f Expected £%0.2f Promo Pot Value £%0.2f Expected £%0.2f',
            $policy->getPolicyNumber(),
            $policy->getPotValue(),
            $policy->calculatePotValue(),
            $policy->getPromoPotValue(),
            $policy->calculatePotValue(true)
        );
    }

    private function failureScheduledPaymentsMessage($policy, $prefix, $date)
    {
        $scheduledPayments = $policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $totalScheduledPayments = ScheduledPayment::sumScheduledPaymentAmounts($scheduledPayments, $prefix);

        return sprintf(
            'Policy %s Total Premium £%0.2f Payments Made £%0.2f Scheduled Payments £%0.2f',
            $policy->getPolicyNumber(),
            $policy->getYearlyPremiumPrice(),
            $policy->getTotalSuccessfulPayments($date),
            $totalScheduledPayments
        );
    }
}
