<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\ClaimFnolTheftLoss;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Service\ClaimsService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolTheftLossType extends AbstractType
{

    /**
     * @var ClaimsService
     */
    private $claimsService;

    /**
     * @var ReceperioService
     */
    private $receperio;

    /**
     * @param ClaimsService $claimsService
     */
    public function __construct(ClaimsService $claimsService, ReceperioService $receperio)
    {
        $this->claimsService = $claimsService;
        $this->receperio = $receperio;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('hasContacted', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Please choose..',
                'choices' => [
                    'Yes' => true,
                    'No' => false,
                ],
            ])
            ->add('contactedPlace', TextType::class, ['required' => false])
            ->add('blockedDate', DateType::class, [
                  'required' => false,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('reportedDate', DateType::class, [
                  'required' => false,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
            ])
            ->add('save', SubmitType::class)
            ->add('confirm', SubmitType::class)
            ->add('crimeReferenceNumber', TextType::class, ['required' => false])
            ->add('force', ChoiceType::class, [
                'choices' => $this->receperio->getForces(),
                'required' => false,
                'placeholder' => 'Select a Police Force',
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var Claim $claim */
            $claim = $event->getData()->getClaim();

            if ($claim->needProofOfUsage()) {
                $form->add('proofOfUsage', FileType::class, ['required' => false]);
            }
            if ($claim->needProofOfPurchase()) {
                $form->add('proofOfPurchase', FileType::class, ['required' => false]);
            }
            if ($claim->needProofOfBarring()) {
                $form->add('proofOfBarring', FileType::class, ['required' => false]);
            }
            if ($claim->getType() == Claim::TYPE_THEFT) {
                $form->add('reportType', HiddenType::class);
            } else {
                // don't use needProofOfLoss here to determine display as would then require 2 steps to submit
                $form->add('proofOfLoss', FileType::class, ['required' => false]);
                $form->add('reportType', ChoiceType::class, [
                    'required' => false,
                    'placeholder' => 'Please choose...',
                    'choices' => [
                        'Police station' => Claim::REPORT_POLICE_STATION,
                        'Online' => Claim::REPORT_ONLINE,
                    ],
                ]);
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var ClaimFnolTheftLoss $data */
            $data = $event->getData();

            $now = new \DateTime();
            $timestamp = $now->format('U');

            if ($filename = $data->getProofOfUsage()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-usage-%s-%06d', $timestamp, rand(1, 999999)),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfUsage($s3key);
            }
            if ($filename = $data->getProofOfBarring()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-barring-%s-%06d', $timestamp, rand(1, 999999)),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfBarring($s3key);
            }
            if ($filename = $data->getProofOfPurchase()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-purchase-%s-%06d', $timestamp, rand(1, 999999)),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfPurchase($s3key);
            }
            if ($filename = $data->getProofOfLoss()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-loss-%s-%06d', $timestamp, rand(1, 999999)),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfLoss($s3key);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnolTheftLoss',
        ));
    }
}
