<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\Cancel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class CancelPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        /** @var Policy $policy */
        $policy = $builder->getData()->getPolicy();
        $data = [];
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_ACTUAL_FRAUD, 'Fraud (actual)');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_SUSPECTED_FRAUD, 'Fraud (suspected)');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_DISPOSSESSION, 'Dispossession');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_WRECKAGE, 'Wreckage');
        $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_UPGRADE, 'Upgrade');

        $preferred = [];
        if ($policy->isWithinCooloffPeriod(null, false) && !$policy->hasMonetaryClaimed(true)) {
            // if requested cancellation reason has already been set by the user, just allow cooloff
            // however, if not set, then allow subcategories
            if ($policy->getRequestedCancellationReason()) {
                $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_COOLOFF, 'Cooloff');
                $preferred[] = Policy::CANCELLED_COOLOFF;
            } else {
                foreach (Policy::$cooloffReasons as $cooloff) {
                    $value = Cancel::getEncodedCooloffReason($cooloff);
                    $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_COOLOFF, $value, $value);
                    $preferred[] = $value;
                }
            }
        } elseif ($policy->isWithinCooloffPeriod(null, true) && !$policy->hasMonetaryClaimed(true)) {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_USER_REQUESTED, 'User Requested');
            $preferred[] = Policy::CANCELLED_USER_REQUESTED;

            // if requested cancellation reason has already been set by the user, just allow cooloff
            // however, if not set, then allow subcategories
            if ($policy->getRequestedCancellationReason()) {
                $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_COOLOFF, 'Cooloff (Extended)');
            } else {
                foreach (Policy::$cooloffReasons as $cooloff) {
                    $value = Cancel::getEncodedCooloffReason($cooloff);
                    $data = $this->addCancellationReason(
                        $data,
                        $policy,
                        Policy::CANCELLED_COOLOFF,
                        sprintf('%s (Extended)', $value),
                        $value
                    );
                }
            }
        } else {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_USER_REQUESTED, 'User Requested');
        }

        if ($policy->getStatus() == Policy::STATUS_UNPAID) {
            $data = $this->addCancellationReason($data, $policy, Policy::CANCELLED_UNPAID, 'Unpaid');
            $preferred[] = Policy::CANCELLED_UNPAID;
        }

        $builder
            ->add('cancellationReason', ChoiceType::class, [
                'choices' => $data,
                'preferred_choices' => $preferred,
                'placeholder' => $policy->hasOpenClaim() ? 'OPEN CLAIM - DO NOT CANCEL' : 'Cancellation reason'
            ])
            ->add('cancel', SubmitType::class)
        ;

        if ($policy->hasOpenClaim()) {
            $builder->add('force', CheckboxType::class, [
                'required' => false,
            ]);
        }
    }

    private function addCancellationReason($data, Policy $policy, $reason, $name, $value = null)
    {
        if (!$value) {
            $value = $reason;
        }
        if ($policy->canCancel($reason, null, true)) {
            $data[$name] = $value;
        }

        return $data;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Cancel',
        ));
    }
}
