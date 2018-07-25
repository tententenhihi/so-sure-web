<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\ClaimFnolDamage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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

class ClaimFnolDamageType extends AbstractType
{

    /**
     * @var ClaimsService
     */
    private $claimsService;

    /**
     * @param ClaimsService $claimsService
     */
    public function __construct(ClaimsService $claimsService)
    {
        $this->claimsService = $claimsService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('typeDetails', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Please choose...',
                'choices' => [
                    'Broken screen' => Claim::DAMAGE_BROKEN_SCREEN,
                    'Water damage' => Claim::DAMAGE_WATER,
                    'Out of warranty breakdown' => Claim::DAMAGE_OUT_OF_WARRANTY,
                    'Other' => Claim::DAMAGE_OTHER,
                ],
            ])
            ->add('typeDetailsOther', TextType::class, ['required' => false])
            ->add('monthOfPurchase', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Month Bought...',
                'choices' => [
                    'January' => 'January',
                    'February' => 'February',
                    'March' => 'March',
                    'April' => 'April',
                    'May' => 'May',
                    'June' => 'June',
                    'July' => 'July',
                    'August' => 'August',
                    'September' => 'September',
                    'October' => 'October',
                    'November' => 'November',
                    'December' => 'December',
                ],
            ])
            ->add('yearOfPurchase', TextType::class, ['required' => false])
            ->add('phoneStatus', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Please choose...',
                'choices' => [
                    'New' => Claim::PHONE_STATUS_NEW,
                    'Refurbished' => Claim::PHONE_STATUS_REFURBISHED,
                    'Second hand' => Claim::PHONE_STATUS_SECOND_HAND,
                ],
            ])
            ->add('save', SubmitType::class)
            ->add('confirm', SubmitType::class)
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
            if ($claim->needPictureOfPhone()) {
                $form->add('pictureOfPhone', FileType::class, ['required' => false]);
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var ClaimFnolDamage $data */
            $data = $event->getData();

            $now = new \DateTime();
            $timestamp = $now->format('U');

            if ($filename = $data->getProofOfUsage()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-usage-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfUsage($s3key);
            }
            if ($filename = $data->getProofOfPurchase()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('proof-of-purchase-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setProofOfPurchase($s3key);
            }
            if ($filename = $data->getPictureOfPhone()) {
                $s3key = $this->claimsService->uploadS3(
                    $filename,
                    sprintf('picture-of-phone-%s', $timestamp),
                    $data->getClaim()->getPolicy()->getUser()->getId(),
                    $filename->guessExtension()
                );
                $data->setPictureOfPhone($s3key);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnolDamage',
        ));
    }
}