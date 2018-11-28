<?php

namespace AppBundle\Controller;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\File\PaymentRequestUploadFile;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Note\CallNote;
use AppBundle\Document\Note\Note;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Form\Type\AdminEmailOptOutType;
use AppBundle\Form\Type\BacsCreditType;
use AppBundle\Form\Type\CallNoteType;
use AppBundle\Form\Type\PaymentRequestUploadFileType;
use AppBundle\Form\Type\UploadFileType;
use AppBundle\Form\Type\UserHandlingTeamType;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\BacsService;
use AppBundle\Service\FraudService;
use AppBundle\Service\JudopayService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\ReceperioService;
use AppBundle\Service\ReportingService;
use AppBundle\Service\RouterService;
use AppBundle\Service\SalvaExportService;
use AppBundle\Service\AffiliateService;
use Doctrine\ODM\MongoDB\Query\Builder;
use Gedmo\Loggable\Document\Repository\LogEntryRepository;
use Grpc\Call;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Validator\Constraints as Assert;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Gedmo\Loggable\Document\LogEntry;
use AppBundle\Classes\ClientUrl;
use AppBundle\Classes\SoSure;
use AppBundle\Classes\Salva;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Address;
use AppBundle\Document\CustomerCompany;
use AppBundle\Document\Charge;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Reward;
use AppBundle\Document\Invoice;
use AppBundle\Document\SCode;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Stats;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Form\AdminMakeModel;
use AppBundle\Document\Form\Roles;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\JudoFile;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\File\LloydsFile;
use AppBundle\Document\File\ImeiUploadFile;
use AppBundle\Document\File\ScreenUploadFile;
use AppBundle\Document\Form\Cancel;
use AppBundle\Document\Form\Imei;
use AppBundle\Document\Form\BillingDay;
use AppBundle\Document\Form\Chargebacks;
use AppBundle\Form\Type\AddressType;
use AppBundle\Form\Type\BillingDayType;
use AppBundle\Form\Type\CancelPolicyType;
use AppBundle\Form\Type\DirectBacsReceiptType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\ClaimSearchType;
use AppBundle\Form\Type\ChargebacksType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\ImeiType;
use AppBundle\Form\Type\NoteType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Form\Type\AdminSmsOptOutType;
use AppBundle\Form\Type\PartialPolicyType;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PhoneSearchType;
use AppBundle\Form\Type\JudoFileType;
use AppBundle\Form\Type\PicSureSearchType;
use AppBundle\Form\Type\FacebookType;
use AppBundle\Form\Type\BarclaysFileType;
use AppBundle\Form\Type\LloydsFileType;
use AppBundle\Form\Type\ImeiUploadFileType;
use AppBundle\Form\Type\ScreenUploadFileType;
use AppBundle\Form\Type\PendingPolicyCancellationType;
use AppBundle\Form\Type\UserDetailType;
use AppBundle\Form\Type\UserEmailType;
use AppBundle\Form\Type\UserPermissionType;
use AppBundle\Form\Type\UserHighRiskType;
use AppBundle\Form\Type\ClaimFlagsType;
use AppBundle\Form\Type\AdminMakeModelType;
use AppBundle\Form\Type\UserRoleType;
use AppBundle\Exception\RedirectException;
use AppBundle\Service\PushService;
use AppBundle\Event\PicsureEvent;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use CensusBundle\Document\Postcode;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_EMPLOYEE')")
 */
class AdminEmployeeController extends BaseController implements ContainerAwareInterface
{
    use DateTrait;
    use CurrencyTrait;
    use ImeiTrait;
    use ContainerAwareTrait;

    /**
     * @Route("", name="admin_home")
     * @Template
     */
    public function indexAction()
    {
        return ['randomImei' => self::generateRandomImei()];
    }

    /**
     * @Route("/phones", name="admin_phones")
     * @Template
     */
    public function phonesAction(Request $request)
    {
        $expectedClaimFrequency = $this->getParameter('expected_claim_frequency');
        $phoneService = $this->get('app.phone');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $makes = $repo->findActiveMakes();
        $phones = $repo->createQueryBuilder();
        $phones = $phones->field('make')->notEqual('ALL');

        $searchForm = $this->get('form.factory')
            ->createNamedBuilder('email_form', PhoneSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $newPhoneForm = $this->get('form.factory')
            ->createNamedBuilder('new_phone_form')
            ->add('os', ChoiceType::class, [
                'required' => true,
                'choices' => Phone::$osTypes,
            ])
            ->add('make', TextType::class)
            ->add('model', TextType::class)
            ->add('add', SubmitType::class)
            ->getForm();
        $rootDir = $this->getParameter('kernel.root_dir');
        $additionalPhonesForm = $this->get('form.factory')
            ->createNamedBuilder('additional_phones_form')
            ->add('file', ChoiceType::class, [
                'required' => true,
                'choices' => $phoneService->getAdditionalPhones($rootDir),
            ])
            ->add('load', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('new_phone_form')) {
                $newPhoneForm->handleRequest($request);
                if ($newPhoneForm->isValid()) {
                    $data = $newPhoneForm->getData();
                    $phone = new Phone();
                    $phone->setMake($data['make']);
                    $phone->setModel($data['model']);
                    $phone->setOs($data['os']);
                    $phone->setActive(false);
                    $dm->persist($phone);
                    $dm->flush();
                    $this->addFlash('success', sprintf(
                        'Added phone. %s',
                        $phone
                    ));

                    return new RedirectResponse($this->generateUrl('admin_phones'));
                }
            } elseif ($request->request->has('additional_phones_form')) {
                if ($this->getUser()->hasRole('ROLE_ADMIN')) {
                    $additionalPhonesForm->handleRequest($request);
                    if ($additionalPhonesForm->isValid()) {
                        $additionalPhones = $phoneService->getAdditionalPhonesInstance(
                            $additionalPhonesForm->get('file')->getData()
                        );
                        if ($additionalPhones !== null) {
                            $additionalPhones->setContainer($this->container);
                            $additionalPhones->load($dm);

                            $this->addFlash('success', sprintf(
                                'Loaded additional phones: %s',
                                $additionalPhonesForm->get('file')->getData()
                            ));
                        } else {
                            $this->addFlash('error', sprintf(
                                'Error loading additional phones: %s',
                                $additionalPhonesForm->get('file')->getData()
                            ));
                        }
                    }
                } else {
                    $this->addFlash(
                        'error',
                        'You don\'t have the permissions to load additional phones'
                    );
                }

                return new RedirectResponse($this->generateUrl('admin_phones'));
            }
        }

        $searchForm->handleRequest($request);
        $data = $searchForm->get('os')->getData();

        $phones = $phones->field('os')->in($data);
        $data = filter_var($searchForm->get('active')->getData(), FILTER_VALIDATE_BOOLEAN);
        $phones = $phones->field('active')->equals($data);
        $rules = $searchForm->get('rules')->getData();
        if ($rules == 'missing') {
            $phones = $phones->field('suggestedReplacement')->exists(false);
            $phones = $phones->field('replacementPrice')->lte(0);
        } elseif ($rules == 'retired') {
            $retired = \DateTime::createFromFormat('U', time());
            $retired->sub(new \DateInterval(sprintf('P%dM', Phone::MONTHS_RETIREMENT + 1)));
            $phones = $phones->field('releaseDate')->lte($retired);
        } elseif ($rules == 'loss') {
            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                if ($phone->policyProfit($expectedClaimFrequency) < 0) {
                    $phoneIds[] = $phone->getId();
                }
            }
            $phones->field('id')->in($phoneIds);
        } elseif ($rules == 'price') {
            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                if (abs($phone->policyProfit($expectedClaimFrequency)) > 30) {
                    $phoneIds[] = $phone->getId();
                }
            }
            $phones->field('id')->in($phoneIds);
        } elseif ($rules == 'brightstar') {
            $replacementPhones = clone $phones;
            $phones = $phones->field('replacementPrice')->lte(0);
            $phones = $phones->field('initialPrice')->gte(300);
            $year = \DateTime::createFromFormat('U', time());
            $year->sub(new \DateInterval('P1Y'));
            $phones = $phones->field('releaseDate')->gte($year);

            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                $phoneIds[] = $phone->getId();
            }
            foreach ($replacementPhones->getQuery()->execute() as $phone) {
                if ($phone->getSuggestedReplacement() &&
                    $phone->getSuggestedReplacement()->getMemory() < $phone->getMemory()) {
                    $phoneIds[] = $phone->getId();
                }
            }

            $phones = $replacementPhones->field('id')->in($phoneIds);
        } elseif ($rules == 'replacement') {
            $phones = $phones->field('suggestedReplacement')->exists(true);
        }
        $phones = $phones->sort('make', 'asc');
        $phones = $phones->sort('model', 'asc');
        $phones = $phones->sort('memory', 'asc');
        $pager = $this->pager($request, $phones);

        $now = \DateTime::createFromFormat('U', time());
        $oneDay = $this->addBusinessDays($now, 1);
        return [
            'phones' => $pager->getCurrentPageResults(),
            'form' => $searchForm->createView(),
            'pager' => $pager,
            'new_phone' => $newPhoneForm->createView(),
            'makes' => $makes,
            'additional_phones' => $additionalPhonesForm->createView(),
            'one_day' => $oneDay,
        ];
    }

    /**
     * @Route("/phones/download", name="admin_phones_download")
     */
    public function adminPhonesDownload()
    {
        /** @var RouterService $router */
        $router = $this->get('app.router');
        /** @var PhoneRepository $repo */
        $repo = $this->getManager()->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();

        $lines = [];
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $lines[] = sprintf(
                '"%s", "%s"',
                $phone->__toString(),
                $router->generateUrl('purchase_phone_make_model_memory', [
                    'make' => $phone->getMake(),
                    'model' => $phone->getEncodedModel(),
                    'memory' => $phone->getMemory()
                ])
            );
        }
        $data = implode(PHP_EOL, $lines);

        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), 'so-sure-phones.csv');
        file_put_contents($tmpFile, $data);

        $headers = [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="so-sure-phones.csv"',
        ];

        return StreamedResponse::create(
            function () use ($tmpFile) {
                $stream = fopen($tmpFile, 'r');
                echo stream_get_contents($stream);
                flush();
            },
            200,
            $headers
        );
    }

    /**
     * @Route("/policies", name="admin_policies")
     * @Template("AppBundle::Claims/claimsPolicies.html.twig")
     */
    public function adminPoliciesAction(Request $request)
    {
        $callNote = new \AppBundle\Document\Form\CallNote();
        $callNote->setUser($this->getUser());
        $callForm = $this->get('form.factory')
            ->createNamedBuilder('call_form', CallNoteType::class, $callNote)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('call_form')) {
                $callForm->handleRequest($request);
                if ($callForm->isValid()) {
                    /** @var PolicyRepository $repo */
                    $repo = $this->getManager()->getRepository(Policy::class);
                    /** @var Policy $policy */
                    $policy = $repo->find($callNote->getPolicyId());
                    if ($policy) {
                        $policy->addNotesList($callNote->toCallNote());
                        $this->getManager()->flush();

                        $this->addFlash('success', 'Recorded call');
                    } else {
                        $this->addFlash('error', 'Unable to record call');
                    }

                    return new RedirectResponse($request->getUri());
                }
            }
        }
        try {
            $data = $this->searchPolicies($request);
        } catch (RedirectException $e) {
            return new RedirectResponse($e->getMessage());
        }
        return array_merge($data, [
            'policy_route' => 'admin_policy',
            'call_form' => $callForm->createView(),
        ]);
    }

    /**
     * @Route("/policies/called-list", name="admin_policies_called_list")
     */
    public function adminPoliciesCalledListAction(Request $request)
    {
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);

        /** @var Builder $policiesQb */
        $policiesQb = $policyRepo->createQueryBuilder()
            ->eagerCursor(true)
            ->field('user')->prime(true);
        $policiesQb = $policiesQb->addAnd(
            $policiesQb->expr()->field('notesList.type')->equals('call')
        );

        $now = new \DateTime();
        $year = $now->format('Y');
        $weekNum = $now->format('W');

        $startWeek = new \DateTime();
        $startWeek->setISODate($year, $weekNum - 1);
        $endWeek = new \DateTime();
        $endWeek->setISODate($year, $weekNum);
        if ($request->get('week') == 'now') {
            $startWeek = new \DateTime();
            $startWeek->setISODate($year, $weekNum);
            $endWeek = new \DateTime();
            $endWeek->setISODate($year, $weekNum + 1);
        }

        $policiesQb = $policiesQb->addAnd(
            $policiesQb->expr()->field('notesList.date')->gte($startWeek)
        );
        $policiesQb = $policiesQb->addAnd(
            $policiesQb->expr()->field('notesList.date')->lt($endWeek)
        );
        $policies = $policiesQb->getQuery()->execute();

        $response = new StreamedResponse();
        $response->setCallback(function () use ($policies) {
            $handle = fopen('php://output', 'w+');

            // Add the header of the CSV file
            fputcsv($handle, [
                'Date',
                'Name',
                'Email',
                'Policy Number',
                'Phone Number',
                'Claim',
                'Cost of claims',
                'Termination Date',
                'Days Before Termination',
                'Present status',
                'Call',
                'Note',
                'Voicemail',
                'Other Actions',
                'All actions',
                'Category',
                'Termination week number',
                'Call week number',
                'Call month',
                'Cancellation month',
            ]);
            foreach ($policies as $policy) {
                /** @var Policy $policy */
                /** @var CallNote $note */
                $note = $policy->getLatestNoteByType(Note::TYPE_CALL);
                $approvedClaims = $policy->getApprovedClaims(true);
                $claimsCost = 0;
                foreach ($approvedClaims as $approvedClaim) {
                    /** @var Claim $approvedClaim */
                    $claimsCost += $approvedClaim->getTotalIncurred();
                }
                $line = [
                    $note->getDate()->format('Y-m-d'),
                    $policy->getUser()->getName(),
                    $policy->getUser()->getEmail(),
                    $policy->getPolicyNumber(),
                    $policy->getUser()->getMobileNumber(),
                    count($approvedClaims),
                    $claimsCost,
                    $policy->getPolicyExpirationDate()->format('Y-m-d'),
                    'FORMULA',
                    $policy->getStatus(),
                    'Yes',
                    $note->getResult(),
                    $note->getVoicemail() ? 'Yes' : '',
                    $note->getOtherActions(),
                    $note->getActions(true),
                    $note->getCategory(),
                    $policy->getPolicyExpirationDate()->format('W'),
                    $note->getDate()->format('W'),
                    $note->getDate()->format('M'),
                    $policy->getPolicyExpirationDate()->format('M'),
                ];
                fputcsv(
                    $handle, // The file pointer
                    $line
                );
            }

            fclose($handle);
        });

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="so-sure-connections.csv"');

        return $response;
    }

    /**
     * @Route("/users", name="admin_users")
     * @Template("AppBundle::AdminEmployee/adminUsers.html.twig")
     */
    public function adminUsersAction(Request $request)
    {
        $emailForm = $this->get('form.factory')
            ->createNamedBuilder('email_form')
            ->add('email', EmailType::class)
            ->add('create', SubmitType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('email_form')) {
                $emailForm->handleRequest($request);
                if ($emailForm->isValid()) {
                    $email = $this->getDataString($emailForm->getData(), 'email');
                    $dm = $this->getManager();
                    $userManager = $this->get('fos_user.user_manager');
                    $user = $userManager->createUser();
                    $user->setEnabled(true);
                    $user->setEmail($email);
                    $dm->persist($user);
                    $dm->flush();
                    $this->addFlash('success', sprintf(
                        'Created User. <a href="%s">%s</a>',
                        $this->generateUrl('admin_user', ['id' => $user->getId()]),
                        $email
                    ));
                }
            }
        }

        try {
            $data = $this->searchUsers($request);
        } catch (RedirectException $e) {
            return new RedirectResponse($e->getMessage());
        }
        return array_merge($data, [
            'policy_route' => 'admin_policy',
            'email_form' => $emailForm->createView(),
        ]);
    }

    /**
     * @Route("/optout", name="admin_optout")
     * @Template
     */
    public function adminOptOutAction(Request $request)
    {
        $dm = $this->getManager();

        $emailOptOut = new EmailOptOut();
        $emailOptOut->setLocation(EmailOptOut::OPT_LOCATION_ADMIN);
        $smsOptOut = new SmsOptOut();
        $smsOptOut->setLocation(EmailOptOut::OPT_LOCATION_ADMIN);

        $emailForm = $this->get('form.factory')
            ->createNamedBuilder('email_form', AdminEmailOptOutType::class, $emailOptOut)
            ->getForm();
        $smsForm = $this->get('form.factory')
            ->createNamedBuilder('sms_form', AdminSmsOptOutType::class, $smsOptOut)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('email_form')) {
                $emailForm->handleRequest($request);
                if ($emailForm->isValid()) {
                    $dm->persist($emailOptOut);
                    $dm->flush();

                    return new RedirectResponse($this->generateUrl('admin_optout'));
                } else {
                    $this->addFlash('error', sprintf(
                        'Unable to add optout. %s',
                        (string) $emailForm->getErrors()
                    ));
                }
            } elseif ($request->request->has('sms_form')) {
                $smsForm->handleRequest($request);
                if ($smsForm->isValid()) {
                    $dm->persist($smsOptOut);
                    $dm->flush();

                    return new RedirectResponse($this->generateUrl('admin_optout'));
                } else {
                    $this->addFlash('error', sprintf(
                        'Unable to add optout. %s',
                        (string) $smsForm->getErrors()
                    ));
                }
            }
        }
        $repo = $dm->getRepository(EmailOptOut::class);
        $oupouts = $repo->findAll();

        return [
            'optouts' => $oupouts,
            'email_form' => $emailForm->createView(),
            'sms_form' => $smsForm->createView(),
        ];
    }

    /**
     * @Route("/imei-form/{id}", name="imei_form")
     * @Template
     */
    public function imeiFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $imei = new Imei();
        $imei->setPolicy($policy);
        $imeiForm = $this->get('form.factory')
            ->createNamedBuilder('imei_form', ImeiType::class, $imei)
            ->setAction($this->generateUrl(
                'imei_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('imei_form')) {
                $imeiForm->handleRequest($request);
                if ($imeiForm->isValid()) {
                    $policy->adjustImei($imei->getImei(), false);

                    $policy->addNoteDetails($imei->getNote(), $this->getUser());

                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Policy %s imei updated.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $imeiForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/policy/{id}", name="admin_policy")
     * @Template("AppBundle::Admin/claimsPolicy.html.twig")
     */
    public function claimsPolicyAction(Request $request, $id)
    {
        /** @var PolicyService $policyService */
        $policyService = $this->get('app.policy');
        /** @var FraudService $fraudService */
        $fraudService = $this->get('app.fraud');
        /** @var ReceperioService $imeiService */
        $imeiService = $this->get('app.imei');
        $invitationService = $this->get('app.invitation');
        $dm = $this->getManager();
        /** @var PhonePolicyRepository $repo */
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $cancel = new Cancel();
        $cancel->setPolicy($policy);
        $cancelForm = $this->get('form.factory')
            ->createNamedBuilder('cancel_form', CancelPolicyType::class, $cancel)
            ->getForm();
        $pendingCancelForm = $this->get('form.factory')
            ->createNamedBuilder('pending_cancel_form', PendingPolicyCancellationType::class, $policy)
            ->getForm();
        $noteForm = $this->get('form.factory')
            ->createNamedBuilder('note_form', NoteType::class)
            ->getForm();
        $facebookForm = $this->get('form.factory')
            ->createNamedBuilder('facebook_form', FacebookType::class, $policy)
            ->getForm();
        $receperioForm = $this->get('form.factory')
            ->createNamedBuilder('receperio_form')->add('rerun', SubmitType::class)
            ->getForm();
        $phoneForm = $this->get('form.factory')
            ->createNamedBuilder('phone_form', PhoneType::class, $policy)
            ->getForm();
        $chargebacks = new Chargebacks();
        $chargebacks->setPolicy($policy);
        $chargebacksForm = $this->get('form.factory')
            ->createNamedBuilder('chargebacks_form', ChargebacksType::class, $chargebacks)
            ->getForm();
        $bacsPayment = new BacsPayment();
        $bacsPayment->setSource(Payment::SOURCE_ADMIN);
        $bacsPayment->setManual(true);
        $bacsPayment->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsPayment->setSuccess(true);
        $bacsPayment->setDate(\DateTime::createFromFormat('U', time()));
        $bacsPayment->setAmount($policy->getPremium()->getYearlyPremiumPrice());
        $bacsPayment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);

        $bacsForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_form', DirectBacsReceiptType::class, $bacsPayment)
            ->getForm();
        $createForm = $this->get('form.factory')
            ->createNamedBuilder('create_form')
            ->add('create', SubmitType::class)
            ->getForm();
        $connectForm = $this->get('form.factory')
            ->createNamedBuilder('connect_form')
            ->add('email', EmailType::class)
            ->add('connect', SubmitType::class)
            ->getForm();
        $imeiUploadFile = new ImeiUploadFile();
        $imeiUploadForm = $this->get('form.factory')
            ->createNamedBuilder('imei_upload', ImeiUploadFileType::class, $imeiUploadFile)
            ->getForm();
        $screenUploadFile = new ScreenUploadFile();
        $screenUploadForm = $this->get('form.factory')
            ->createNamedBuilder('screen_upload', ScreenUploadFileType::class, $screenUploadFile)
            ->getForm();
        $userTokenForm = $this->get('form.factory')
            ->createNamedBuilder('usertoken_form')
            ->add('regenerate', SubmitType::class)
            ->getForm();
        $billing = new BillingDay();
        $billing->setPolicy($policy);
        $billingForm = $this->get('form.factory')
            ->createNamedBuilder('billing_form', BillingDayType::class, $billing)
            ->getForm();
        $resendEmailForm = $this->get('form.factory')
            ->createNamedBuilder('resend_email_form')->add('resend', SubmitType::class)
            ->getForm();
        $regeneratePolicyScheduleForm = $this->get('form.factory')
            ->createNamedBuilder('regenerate_policy_schedule_form')->add('regenerate', SubmitType::class)
            ->getForm();
        $makeModel = new AdminMakeModel();
        $makeModelForm = $this->get('form.factory')
            ->createNamedBuilder('makemodel_form', AdminMakeModelType::class, $makeModel)
            ->getForm();
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claimFlags = $this->get('form.factory')
            ->createNamedBuilder('claimflags', ClaimFlagsType::class, $claim)
            ->getForm();
        $debtForm = $this->get('form.factory')
            ->createNamedBuilder('debt_form')->add('debt', SubmitType::class)
            ->getForm();
        $picsureForm = $this->get('form.factory')
            ->createNamedBuilder('picsure_form')
            ->add('approve', SubmitType::class)
            ->add('preapprove', SubmitType::class)
            ->getForm();
        $swapPaymentPlanForm = $this->get('form.factory')
            ->createNamedBuilder('swap_payment_plan_form')->add('swap', SubmitType::class)
            ->getForm();
        $payPolicyForm = $this->get('form.factory')
            ->createNamedBuilder('pay_policy_form')
            ->add('monthly', SubmitType::class)
            ->add('yearly', SubmitType::class)
            ->getForm();
        $cancelDirectDebitForm = $this->get('form.factory')
            ->createNamedBuilder('cancel_direct_debit_form')
            ->add('cancel', SubmitType::class)
            ->getForm();
        $paymentRequestFile = new PaymentRequestUploadFile();
        $paymentRequestFile->setPolicy($policy);
        $runScheduledPaymentForm = $this->get('form.factory')
            ->createNamedBuilder('run_scheduled_payment_form', PaymentRequestUploadFileType::class, $paymentRequestFile)
            ->getForm();
        $bacsRefund = new BacsPayment();
        $bacsRefund->setSource(Payment::SOURCE_ADMIN);
        $bacsRefund->setPolicy($policy);
        $bacsRefund->setAmount($policy->getPremiumInstallmentPrice(true));
        $bacsRefund->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $bacsRefund->setStatus(BacsPayment::STATUS_PENDING);
        $bacsRefundForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_refund_form', BacsCreditType::class, $bacsRefund)
            ->getForm();
        $salvaUpdateForm = $this->get('form.factory')
            ->createNamedBuilder('salva_update_form')
            ->add('update', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cancel_form')) {
                $cancelForm->handleRequest($request);
                if ($cancelForm->isValid()) {
                    $claimCancel = $policy->canCancel($cancel->getCancellationReason(), null, true);
                    if ($policy->canCancel($cancel->getCancellationReason()) ||
                        ($claimCancel && $cancel->getForce())) {
                        if ($cancel->getRequestedCancellationReason()) {
                            $policy->setRequestedCancellationReason($cancel->getRequestedCancellationReason());
                        }
                        $policyService->cancel(
                            $policy,
                            $cancel->getCancellationReason(),
                            true,
                            null,
                            true
                        );
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s was cancelled.', $policy->getPolicyNumber())
                        );
                    } elseif ($claimCancel && !$cancel->getForce()) {
                        $this->addFlash('error', sprintf(
                            'Unable to cancel Policy %s due to %s as override was not enabled',
                            $policy->getPolicyNumber(),
                            $cancel->getCancellationReason()
                        ));
                    } else {
                        $this->addFlash('error', sprintf(
                            'Unable to cancel Policy %s due to %s',
                            $policy->getPolicyNumber(),
                            $cancel->getCancellationReason()
                        ));
                    }

                    return $this->redirectToRoute('admin_policies');
                }
            } elseif ($request->request->has('pending_cancel_form')) {
                $pendingCancelForm->handleRequest($request);
                if ($pendingCancelForm->isValid()) {
                    if ($pendingCancelForm->get('clear')->isClicked()) {
                        $policy->setPendingCancellation(null);
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s is no longer scheduled to be cancelled', $policy->getPolicyNumber())
                        );
                    } else {
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s is scheduled to be cancelled', $policy->getPolicyNumber())
                        );
                    }
                    $dm->flush();

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('phone_form')) {
                $phoneForm->handleRequest($request);
                if ($phoneForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Policy %s phone updated.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('note_form')) {
                $noteForm->handleRequest($request);
                if ($noteForm->isValid()) {
                    $policy->addNoteDetails($noteForm->getData()['notes'], $this->getUser());
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Added note to Policy %s.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('facebook_form')) {
                $facebookForm->handleRequest($request);
                if ($facebookForm->isValid()) {
                    $policy->getUser()->resetFacebook();
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Policy %s facebook cleared.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('receperio_form')) {
                $receperioForm->handleRequest($request);
                if ($receperioForm->isValid()) {
                    if ($policy->getImei()) {
                        $imeiService->checkImei($policy->getPhone(), $policy->getImei(), $policy->getUser());
                        $policy->addCheckmendCertData($imeiService->getCertId(), $imeiService->getResponseData());

                        // clear out the cache - if we're re-checking it likely
                        // means that recipero has updated their data
                        $imeiService->clearMakeModelCheckCache($policy->getSerialNumber());
                        $imeiService->clearMakeModelCheckCache($policy->getImei());

                        $serialNumber = $policy->getSerialNumber();
                        if (!$serialNumber) {
                            $serialNumber= $policy->getImei();
                        }
                        $imeiService->checkSerial(
                            $policy->getPhone(),
                            $serialNumber,
                            $policy->getImei(),
                            $policy->getUser()
                        );
                        $policy->addCheckmendSerialData($imeiService->getResponseData());
                        $dm->flush();
                        $this->addFlash(
                            'warning',
                            '(Re)ran Receperio Checkes. Check results below.'
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Unable to run receperio checks (no imei number)'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('resend_email_form')) {
                $resendEmailForm->handleRequest($request);
                if ($resendEmailForm->isValid()) {
                    $policyService->resendPolicyEmail($policy);
                    $this->addFlash(
                        'success',
                        'Resent the policy email.'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('bacs_form')) {
                $bacsForm->handleRequest($request);
                if ($bacsForm->isValid()) {
                    // non-manual payments should be scheduled
                    if (!$bacsPayment->isManual()) {
                        $bacsPayment->setStatus(BacsPayment::STATUS_PENDING);
                        if (!$policy->getUser()->hasBacsPaymentMethod()) {
                            $this->get('logger')->warning(sprintf(
                                'Payment (Policy %s) is scheduled, however no bacs account for user',
                                $policy->getId()
                            ));
                        }
                    }
                    $policy->addPayment($bacsPayment);

                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Added Payment'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('create_form')) {
                $createForm->handleRequest($request);
                if ($createForm->isValid()) {
                    $policyService->create($policy, null, true);
                    $this->addFlash(
                        'success',
                        'Created Policy'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('connect_form')) {
                $connectForm->handleRequest($request);
                if ($connectForm->isValid()) {
                    $invitation = $invitationService->inviteByEmail(
                        $policy,
                        $connectForm->getData()['email'],
                        null,
                        true
                    );
                    $invitationService->accept(
                        $invitation,
                        $invitation->getInvitee()->getFirstPolicy(),
                        null,
                        true
                    );
                    $this->addFlash(
                        'success',
                        'Connected Users'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('imei_upload')) {
                $imeiUploadForm->handleRequest($request);
                if ($imeiUploadForm->isSubmitted() && $imeiUploadForm->isValid()) {
                    $dm = $this->getManager();
                    // we're assuming that a manaual check is done to verify
                    $policy->setPhoneVerified(true);
                    $imeiUploadFile->setPolicy($policy);
                    $imeiUploadFile->setBucket('policy.so-sure.com');
                    $imeiUploadFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $policy->addPolicyFile($imeiUploadFile);
                    $dm->persist($imeiUploadFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('screen_upload')) {
                $screenUploadForm->handleRequest($request);
                if ($screenUploadForm->isSubmitted() && $screenUploadForm->isValid()) {
                    $dm = $this->getManager();
                    // we're assuming that a manaual check is done to verify
                    $policy->setScreenVerified(true);
                    $screenUploadFile->setPolicy($policy);
                    $screenUploadFile->setBucket('policy.so-sure.com');
                    $screenUploadFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $policy->addPolicyFile($screenUploadFile);
                    $dm->persist($screenUploadFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('usertoken_form')) {
                $userTokenForm->handleRequest($request);
                if ($userTokenForm->isSubmitted() && $userTokenForm->isValid()) {
                    $policy->getUser()->resetToken();
                    $dm = $this->getManager();
                    $dm->flush();

                    $identity = $this->get('app.cognito.identity');
                    if ($identity->deleteLastestMobileToken($policy->getUser())) {
                        $this->addFlash(
                            'success',
                            'Reset user token & deleted cognito credentials'
                        );
                    } else {
                        $this->addFlash(
                            'success',
                            'Reset user token. No cognito credentials present'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('billing_form')) {
                $billingForm->handleRequest($request);
                if ($billingForm->isValid()) {
                    $policyService->adjustScheduledPayments($policy);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated billing date'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('regenerate_policy_schedule_form')) {
                $regeneratePolicyScheduleForm->handleRequest($request);
                if ($regeneratePolicyScheduleForm->isValid()) {
                    $policyService->generatePolicyTerms($policy);
                    $policyService->generatePolicySchedule($policy);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Re-generated Policy Terms & Schedule'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('makemodel_form')) {
                $makeModelForm->handleRequest($request);
                if ($makeModelForm->isValid()) {
                    $imeiValidator = $this->get('app.imei');
                    $phone = new Phone();
                    $imeiValidator->checkSerial(
                        $phone,
                        $makeModel->getSerialNumberOrImei(),
                        null,
                        $policy->getUser(),
                        null,
                        false
                    );
                    $this->addFlash(
                        'success',
                        sprintf('%s', json_encode($imeiValidator->getResponseData()))
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                } else {
                    $this->addFlash('error', 'Unable to run make/model check');
                }
            } elseif ($request->request->has('chargebacks_form')) {
                $chargebacksForm->handleRequest($request);
                if ($chargebacksForm->isValid()) {
                    if ($chargeback = $chargebacks->getChargeback()) {
                        // To appear for the correct account month, should be when we assign
                        // the chargeback to the policy
                        $chargeback->setDate(\DateTime::createFromFormat('U', time()));

                        if ($this->areEqualToTwoDp(
                            $chargeback->getAmount(),
                            $policy->getPremiumInstallmentPrice(true)
                        )) {
                            $chargeback->setRefundTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                        } else {
                            $this->addFlash(
                                'error',
                                sprintf(
                                    'Unable to determine commission to refund for chargeback %s',
                                    $chargeback->getReference()
                                )
                            );
                        }

                        $policy->addPayment($chargeback);
                        $dm->flush();
                        $this->addFlash(
                            'success',
                            sprintf('Added chargeback %s to policy', $chargeback->getReference())
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Unknown chargeback'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('debt_form')) {
                $debtForm->handleRequest($request);
                if ($debtForm->isValid()) {
                    $policy->setDebtCollector(Policy::DEBT_COLLECTOR_WISE);
                    $dm->flush();
                    $email = null;
                    $customerSubject = null;

                    if ($policy->getDebtCollector() == Policy::DEBT_COLLECTOR_WISE) {
                        $email = 'debts@awise.demon.co.uk';
                        $customerSubject = 'Wise has now been authorised to chase your debt to so-sure';
                    }

                    if ($email) {
                        $mailer = $this->get('app.mailer');
                        $mailer->sendTemplate(
                            'Debt Collection Request',
                            $email,
                            'AppBundle:Email:policy/debtCollection.html.twig',
                            ['policy' => $policy],
                            'AppBundle:Email:policy/debtCollection.txt.twig',
                            ['policy' => $policy],
                            null,
                            'bcc@so-sure.com'
                        );

                        $mailer->sendTemplate(
                            $customerSubject,
                            $policy->getUser()->getEmail(),
                            'AppBundle:Email:policy/debtCollectionCustomer.html.twig',
                            ['policy' => $policy],
                            'AppBundle:Email:policy/debtCollectionCustomer.txt.twig',
                            ['policy' => $policy],
                            null,
                            'bcc@so-sure.com'
                        );

                        $this->addFlash(
                            'success',
                            sprintf('Emailed debt collector and set flag on policy')
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('picsure_form')) {
                $picsureForm->handleRequest($request);
                if ($picsureForm->isValid()) {
                    if ($policy->getPolicyTerms()->isPicSureEnabled() && !$policy->isPicSureValidated()) {
                        if ($picsureForm->get('approve')->isClicked()) {
                            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED, $this->getUser());
                        } elseif ($picsureForm->get('preapprove')->isClicked()) {
                            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_PREAPPROVED, $this->getUser());
                        } else {
                            throw new \Exception('Unknown button click');
                        }
                        $policy->setPicSureApprovedDate(\DateTime::createFromFormat('U', time()));
                        $dm->flush();
                        $this->addFlash(
                            'success',
                            sprintf('Set pic-sure to %s', $policy->getPicSureStatus())
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Policy is not a pic-sure policy or policy is already pic-sure (pre)approved'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('swap_payment_plan_form')) {
                $swapPaymentPlanForm->handleRequest($request);
                if ($swapPaymentPlanForm->isValid()) {
                    $policyService->swapPaymentPlan($policy);
                    // @codingStandardsIgnoreStart
                    $this->addFlash(
                        'success',
                        'Payment Plan has been swapped. For now, please manually adjust final scheduled payment to current date.'
                    );
                    // @codingStandardsIgnoreEnd

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('pay_policy_form')) {
                $payPolicyForm->handleRequest($request);
                if ($payPolicyForm->isValid()) {
                    $date = \DateTime::createFromFormat('U', time());
                    $phone = $policy->getPhone();
                    $currentPrice = $phone->getCurrentPhonePrice();
                    if ($currentPrice && $payPolicyForm->get('monthly')->isClicked()) {
                        $amount = $currentPrice->getMonthlyPremiumPrice(null, $date);
                    } elseif ($currentPrice && $payPolicyForm->get('yearly')->isClicked()) {
                        $amount = $currentPrice->getYearlyPremiumPrice(null, $date);
                    } else {
                        throw new \Exception('1 or 12 payments only');
                    }
                    $premium = $policy->getPremium();
                    if ($premium &&
                        !$this->areEqualToTwoDp($amount, $premium->getAdjustedStandardMonthlyPremiumPrice()) &&
                        !$this->areEqualToTwoDp($amount, $premium->getAdjustedYearlyPremiumPrice())) {
                        throw new \Exception(sprintf(
                            'Current price does not match policy price for %s',
                            $policy->getId()
                        ));
                    }

                    /** @var JudopayService $judopay */
                    $judopay = $this->get('app.judopay');
                    $details = $judopay->runTokenPayment(
                        $policy->getPayerOrUser(),
                        $amount,
                        $date->getTimestamp(),
                        $policy->getId()
                    );
                    try {
                        /** @var JudoPaymentMethod $judoPaymentMethod */
                        $judoPaymentMethod = $policy->getPayerOrUser()->getPaymentMethod();
                        $judopay->add(
                            $policy,
                            $details['receiptId'],
                            $details['consumer']['consumerToken'],
                            $details['cardDetails']['cardToken'],
                            Payment::SOURCE_TOKEN,
                            $judoPaymentMethod->getDeviceDna(),
                            $date
                        );
                        // @codingStandardsIgnoreStart
                        $this->addFlash(
                            'success',
                            'Policy is now paid for. Pdf generation may take a few minutes. Refresh the page to verify.'
                        );
                        // @codingStandardsIgnoreEnd
                    } catch (PaymentDeclinedException $e) {
                        $this->addFlash(
                            'danger',
                            'Payment was declined'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('cancel_direct_debit_form')) {
                $cancelDirectDebitForm->handleRequest($request);
                if ($cancelDirectDebitForm->isValid()) {
                    /** @var BacsService $bacsService */
                    $bacsService = $this->get('app.bacs');
                    /** @var BacsPaymentMethod $bacsPaymentMethod */
                    $bacsPaymentMethod = $policy->getPayerOrUser()->getPaymentMethod();
                    $bacsService->queueCancelBankAccount(
                        $bacsPaymentMethod->getBankAccount(),
                        $policy->getPayerOrUser()->getId()
                    );
                    $this->addFlash('success', sprintf(
                        'Direct Debit Cancellation has been queued.'
                    ));
                }
            } elseif ($request->request->has('run_scheduled_payment_form')) {
                $runScheduledPaymentForm->handleRequest($request);
                if ($runScheduledPaymentForm->isValid()) {
                    $scheduledPayment = $policy->getNextScheduledPayment();
                    if ($scheduledPayment && $paymentRequestFile) {
                        $paymentRequestFile->setBucket(SoSure::S3_BUCKET_POLICY);
                        $paymentRequestFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                        $policy->addPolicyFile($paymentRequestFile);
                        $scheduledPayment->adminReschedule();

                        $this->getManager()->flush();

                        $this->addFlash('success', sprintf(
                            'Rescheduled scheduled payment for %s',
                            $scheduledPayment->getScheduled() ?
                                    $scheduledPayment->getScheduled()->format('d M Y') :
                                    '?'
                        ));
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('bacs_refund_form')) {
                $bacsRefundForm->handleRequest($request);
                if ($bacsRefundForm->isValid()) {
                    $bacsRefund->setAmount(0 - abs($bacsRefund->getAmount()));
                    $bacsRefund->calculateSplit();
                    $bacsRefund->setRefundTotalCommission($bacsRefund->getTotalCommission());
                    $this->getManager()->persist($bacsRefund);
                    $this->getManager()->flush();
                }
            } elseif ($request->request->has('salva_update_form')) {
                $salvaUpdateForm->handleRequest($request);
                if ($salvaUpdateForm->isValid()) {
                    /** @var SalvaExportService $salvaService */
                    $salvaService = $this->get('app.salva');
                    $salvaService->queuePolicy($policy, SalvaExportService::QUEUE_UPDATED);

                    $this->addFlash('success', 'Queued Salva Policy Update');
                }
            }
        }
        $checks = $fraudService->runChecks($policy);
        $now = \DateTime::createFromFormat('U', time());

        /** @var LogEntryRepository $logRepo */
        $logRepo = $this->getManager()->getRepository(LogEntry::class);
        $previousPicSureStatuses = $logRepo->findBy([
            'objectId' => $policy->getId(),
            'data.picSureStatus' => ['$nin' => [null, PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED]],
        ], ['loggedAt' => 'desc'], 1);
        $previousPicSureStatus = null;
        if (count($previousPicSureStatuses) > 0) {
            $previousPicSureStatus = $previousPicSureStatuses[0];
        }

        $previousInvalidPicSureStatuses = $logRepo->findBy([
            'objectId' => $policy->getId(),
            'data.picSureStatus' => PhonePolicy::PICSURE_STATUS_INVALID
        ]);
        $hadInvalidPicSureStatus = false;
        if (count($previousInvalidPicSureStatuses) > 0) {
            $hadInvalidPicSureStatus = true;
        }


        return [
            'policy' => $policy,
            'cancel_form' => $cancelForm->createView(),
            'pending_cancel_form' => $pendingCancelForm->createView(),
            'note_form' => $noteForm->createView(),
            'phone_form' => $phoneForm->createView(),
            'formClaimFlags' => $claimFlags->createView(),
            'facebook_form' => $facebookForm->createView(),
            'receperio_form' => $receperioForm->createView(),
            'bacs_form' => $bacsForm->createView(),
            'create_form' => $createForm->createView(),
            'connect_form' => $connectForm->createView(),
            'imei_upload_form' => $imeiUploadForm->createView(),
            'screen_upload_form' => $screenUploadForm->createView(),
            'usertoken_form' => $userTokenForm->createView(),
            'billing_form' => $billingForm->createView(),
            'resend_email_form' => $resendEmailForm->createView(),
            'regenerate_policy_schedule_form' => $regeneratePolicyScheduleForm->createView(),
            'makemodel_form' => $makeModelForm->createView(),
            'chargebacks_form' => $chargebacksForm->createView(),
            'debt_form' => $debtForm->createView(),
            'picsure_form' => $picsureForm->createView(),
            'swap_payment_plan_form' => $swapPaymentPlanForm->createView(),
            'pay_policy_form' => $payPolicyForm->createView(),
            'cancel_direct_debit_form' => $cancelDirectDebitForm->createView(),
            'run_scheduled_payment_form' => $runScheduledPaymentForm->createView(),
            'bacs_refund_form' => $bacsRefundForm->createView(),
            'salva_update_form' => $salvaUpdateForm->createView(),
            'fraud' => $checks,
            'policy_route' => 'admin_policy',
            'policy_history' => $this->getSalvaPhonePolicyHistory($policy->getId()),
            'user_history' => $this->getUserHistory($policy->getUser()->getId()),
            'suggested_cancellation_date' => $now->add(new \DateInterval('P30D')),
            'claim_types' => Claim::$claimTypes,
            'phones' => $dm->getRepository(Phone::class)->findActiveInactive()->getQuery()->execute(),
            'now' => \DateTime::createFromFormat('U', time()),
            'previousPicSureStatus' => $previousPicSureStatus,
            'hadInvalidPicSureStatus' => $hadInvalidPicSureStatus,
        ];
    }

    /**
     * @Route("/user/{id}", name="admin_user")
     * @Template("AppBundle::Claims/user.html.twig")
     */
    public function adminUserAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->find($id);

        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }
        $censusDM = $this->getCensusManager();
        $postcodeRepo = $censusDM->getRepository(PostCode::class);
        $postcode = null;
        $census = null;
        $income = null;
        if ($user->getBillingAddress()) {
            $search = $this->get('census.search');
            $postcode = $search->getPostcode($user->getBillingAddress()->getPostcode());
            $census = $search->findNearest($user->getBillingAddress()->getPostcode());
            $income = $search->findIncome($user->getBillingAddress()->getPostcode());
        }

        $resetForm = $this->get('form.factory')
            ->createNamedBuilder('reset_form')
            ->add('reset', SubmitType::class)
            ->getForm();
        $userDetailForm = $this->get('form.factory')
            ->createNamedBuilder('user_detail_form', UserDetailType::class, $user)
            ->getForm();
        $userEmailForm = $this->get('form.factory')
            ->createNamedBuilder('user_email_form', UserEmailType::class, $user)
            ->getForm();
        $userPermissionForm = $this->get('form.factory')
            ->createNamedBuilder('user_permission_form', UserPermissionType::class, $user)
            ->getForm();
        $userHighRiskForm = $this->get('form.factory')
            ->createNamedBuilder('user_high_risk_form', UserHighRiskType::class, $user)
            ->getForm();
        $makeModel = new AdminMakeModel();
        $makeModelForm = $this->get('form.factory')
            ->createNamedBuilder('makemodel_form', AdminMakeModelType::class, $makeModel)
            ->getForm();
        $address = $user->getBillingAddress();
        if (!$address) {
            $address = new Address();
        }
        $userAddressForm = $this->get('form.factory')
            ->createNamedBuilder('user_address_form', AddressType::class, $address)
            ->getForm();
        $policyData = new SalvaPhonePolicy();
        $policyForm = $this->get('form.factory')
            ->createNamedBuilder('policy_form', PartialPolicyType::class, $policyData)
            ->getForm();
        $sanctionsForm = $this->get('form.factory')
            ->createNamedBuilder('sanctions_form')
            ->add('confirm', SubmitType::class)
            ->getForm();
        $role = new Roles();
        $role->setRoles($user->getRoles());
        $roleForm = $this->get('form.factory')
            ->createNamedBuilder('user_role_form', UserRoleType::class, $role)
            ->getForm();
        $handlingTeamForm = $this->get('form.factory')
            ->createNamedBuilder('handling_team_form', UserHandlingTeamType::class, $user)
            ->getForm();
        $deleteForm = $this->get('form.factory')
            ->createNamedBuilder('delete_form')
            ->add('delete', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('user_role_form')) {
                $roleForm->handleRequest($request);
                if ($roleForm->isValid()) {
                    $newRoles = $role->getRoles();
                    $user->setRoles($newRoles);
                    $this->get('fos_user.user_manager')->updateUser($user);
                    $this->addFlash(
                        'success',
                        'Role(s) updated'
                    );
                    return new RedirectResponse($this->generateUrl('admin_user', ['id' => $id]));
                }
            } elseif ($request->request->has('reset_form')) {
                $resetForm->handleRequest($request);
                if ($resetForm->isValid()) {
                    if (null === $user->getConfirmationToken()) {
                        /** @var \FOS\UserBundle\Util\TokenGeneratorInterface $tokenGenerator */
                        $tokenGenerator = $this->get('fos_user.util.token_generator');
                        $user->setConfirmationToken($tokenGenerator->generateToken());
                    }

                    $this->container->get('fos_user.mailer')->sendResettingEmailMessage($user);
                    $user->setPasswordRequestedAt(\DateTime::createFromFormat('U', time()));
                    $this->get('fos_user.user_manager')->updateUser($user);

                    $this->addFlash(
                        'success',
                        'Reset email was sent.'
                    );

                    return new RedirectResponse($this->generateUrl('admin_user', ['id' => $id]));
                }
            } elseif ($request->request->has('policy_form')) {
                $policyForm->handleRequest($request);
                if ($policyForm->isValid()) {
                    $imeiValidator = $this->get('app.imei');
                    if (!$imeiValidator->isImei($policyData->getImei()) ||
                        $imeiValidator->isLostImei($policyData->getImei()) ||
                        $imeiValidator->isDuplicatePolicyImei($policyData->getImei())) {
                        $this->addFlash(
                            'error',
                            'Imei is invalid, lost, or duplicate'
                        );

                        return $this->redirectToRoute('admin_user', ['id' => $id]);
                    }

                    if (!$user->hasValidDetails() || !$user->hasValidBillingDetails()) {
                            $this->addFlash(
                                'error',
                                'User is missing details (mobile/address/etc)'
                            );

                            return $this->redirectToRoute('admin_user', ['id' => $id]);
                    }

                    $policyService = $this->get('app.policy');
                    $serialNumber = $policyData->getSerialNumber();

                    $missingSerialNumber = false;
                    if ($policyData->getPhone()->isApple() && !$this->isAppleSerialNumber($serialNumber)) {
                        $missingSerialNumber = true;

                        # Admin's can create without serial number if necessary
                        if (!$this->getUser()->hasRole('ROLE_ADMIN')) {
                            $this->addFlash(
                                'error',
                                'Missing Serial Number - unable to create policy'
                            );

                            return $this->redirectToRoute('admin_user', ['id' => $id]);
                        }
                    }

                    // For phones without a serial number, run check on imei
                    if (!$serialNumber) {
                        $serialNumber = $policyData->getImei();
                    }

                    $newPolicy = $policyService->init(
                        $user,
                        $policyData->getPhone(),
                        $policyData->getImei(),
                        $serialNumber
                    );

                    $dm->persist($newPolicy);
                    $dm->flush();

                    if ($missingSerialNumber) {
                        $this->addFlash(
                            'warning',
                            'Created Partial Policy - Missing Expected Serial Number'
                        );
                    } else {
                        $this->addFlash(
                            'success',
                            'Created Partial Policy'
                        );
                    }

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_detail_form')) {
                $userDetailForm->handleRequest($request);
                if ($userDetailForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated User'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                } else {
                    $errors = 'Unknown';
                    try {
                        $this->validateObject($user);
                    } catch (\Exception $e) {
                        $errors = $e->getMessage();
                    }
                    $this->addFlash(
                        'error',
                        sprintf('Failed to update user. Error: %s', $errors)
                    );
                }
            } elseif ($request->request->has('user_address_form')) {
                $userAddressForm->handleRequest($request);
                if ($userAddressForm->isValid()) {
                    $user->setBillingAddress($address);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated User Address'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_email_form')) {
                $userEmailForm->handleRequest($request);
                if ($userEmailForm->isValid()) {
                    $userRepo = $this->getManager()->getRepository(User::class);
                    $existingUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($user->getEmail())]);
                    if ($existingUser) {
                        // @codingStandardsIgnoreStart
                        $this->addFlash(
                            'error',
                            'Sorry, but that email already exists in our system. Please contact us to resolve this issue.'
                        );
                        // @codingStandardsIgnoreEnd

                        return $this->redirectToRoute('admin_user', ['id' => $id]);
                    }

                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Changed User Email'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_permission_form')) {
                $userPermissionForm->handleRequest($request);
                if ($userPermissionForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated User Permissions'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_high_risk_form')) {
                $userHighRiskForm->handleRequest($request);
                if ($userHighRiskForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated User High Risk'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('makemodel_form')) {
                $makeModelForm->handleRequest($request);
                if ($makeModelForm->isValid()) {
                    $imeiValidator = $this->get('app.imei');
                    $phone = new Phone();
                    $imeiValidator->checkSerial(
                        $phone,
                        $makeModel->getSerialNumberOrImei(),
                        null,
                        $user,
                        null,
                        false
                    );
                    $this->addFlash(
                        'success',
                        sprintf('%s', json_encode($imeiValidator->getResponseData()))
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                } else {
                    $this->addFlash('error', 'Unable to run make/model check');
                }
            } elseif ($request->request->has('sanctions_form')) {
                $sanctionsForm->handleRequest($request);
                if ($sanctionsForm->isValid()) {
                    foreach ($user->getSanctionsMatches() as $match) {
                        $match->setManuallyVerified(true);
                    }
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Verified Sanctions'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('handling_team_form')) {
                $handlingTeamForm->handleRequest($request);
                if ($handlingTeamForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated Handling Team'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('delete_form')) {
                $deleteForm->handleRequest($request);
                if ($deleteForm->isValid()) {
                    /** @var FOSUBUserProvider $userService */
                    $userService = $this->get('app.user');
                    $userService->deleteUser($user);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Deleted User'
                    );

                    return $this->redirectToRoute('admin_users');
                }
            }
        }

        return [
            'user' => $user,
            'reset_form' => $resetForm->createView(),
            'policy_form' => $policyForm->createView(),
            'role_form' => $roleForm->createView(),
            'user_detail_form' => $userDetailForm->createView(),
            'user_email_form' => $userEmailForm->createView(),
            'user_address_form' => $userAddressForm->createView(),
            'user_permission_form' => $userPermissionForm->createView(),
            'user_high_risk_form' => $userHighRiskForm->createView(),
            'makemodel_form' => $makeModelForm->createView(),
            'sanctions_form' => $sanctionsForm->createView(),
            'handling_team_form' => $handlingTeamForm->createView(),
            'delete_form' => $deleteForm->createView(),
            'postcode' => $postcode,
            'census' => $census,
            'income' => $income,
            'policy_route' => 'admin_policy',
        ];
    }

    /**
     * @Route("/claims", name="admin_claims")
     * @Template("AppBundle::Claims/claims.html.twig")
     */
    public function adminClaimsAction(Request $request)
    {
        return $this->searchClaims($request);
    }

    /**
     * @Route("/claim/{number}", name="admin_claim_number")
     */
    public function adminClaimNumberAction($number)
    {
        $dm = $this->getManager();
        /** @var ClaimRepository $repo */
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->findOneBy(['number' => $number]);
        if (!$claim) {
            throw $this->createNotFoundException('Policy not found');
        }

        return $this->redirectToRoute('admin_policy', ['id' => $claim->getPolicy()->getId()]);
    }

    /**
     * @Route("/policy/download/{id}", name="admin_download_file")
     * @Route("/policy/download/{id}/attachment", name="admin_download_file_attachment")
     */
    public function policyDownloadFileAction(Request $request, $id)
    {
        return $this->policyDownloadFile(
            $id,
            $request->get('_route') == 'admin_download_file_attachment'
        );
    }

    /**
     * @Route("/phone/{id}/alternatives", name="admin_phone_alternatives")
     * @Method({"GET"})
     */
    public function phoneAlternativesAction($id)
    {
        return $this->phoneAlternatives($id);
    }

    /**
     * @Route("/claim/notes/{id}", name="admin_claim_notes", requirements={"id":"[0-9a-f]{24,24}"})
     * @Method({"POST"})
     */
    public function claimsNotesAction(Request $request, $id)
    {
        return $this->claimsNotes($request, $id);
    }

    /**
     * @Route("/scheduled-payments", name="admin_scheduled_payments")
     * @Route("/scheduled-payments/{year}/{month}", name="admin_scheduled_payments_date")
     * @Template
     */
    public function adminScheduledPaymentsAction($year = null, $month = null)
    {
        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = new \DateTime(sprintf('%d-%d-01', $year, $month));
        $end = $this->endOfMonth($date);

        $dm = $this->getManager();
        $scheduledPaymentRepo = $dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $scheduledPaymentRepo->findMonthlyScheduled($date);
        $total = 0;
        $totalJudo = 0;
        $totalBacs = 0;
        foreach ($scheduledPayments as $scheduledPayment) {
            /** @var ScheduledPayment $scheduledPayment */
            if (in_array(
                $scheduledPayment->getStatus(),
                [ScheduledPayment::STATUS_SCHEDULED, ScheduledPayment::STATUS_SUCCESS]
            )) {
                $total += $scheduledPayment->getAmount();
                if ($scheduledPayment->getPolicy()->getUser()->hasBacsPaymentMethod()) {
                    $totalBacs += $scheduledPayment->getAmount();
                } else {
                    $totalJudo += $scheduledPayment->getAmount();
                }
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'end' => $end,
            'scheduledPayments' => $scheduledPayments,
            'total' => $total,
            'totalJudo' => $totalJudo,
            'totalBacs' => $totalBacs,
        ];
    }

    /**
     * @Route("/pl", name="admin_quarterly_pl")
     * @Route("/pl/{year}/{month}", name="admin_quarterly_pl_date")
     * @Template
     */
    public function adminQuarterlyPLAction(Request $request, $year = null, $month = null)
    {
        if ($request->get('_route') == "admin_quarterly_pl") {
            $now = \DateTime::createFromFormat('U', time());
            $now = $now->sub(new \DateInterval('P1Y'));
            return new RedirectResponse($this->generateUrl('admin_quarterly_pl_date', [
                'year' => $now->format('Y'),
                'month' => $now->format('m'),
            ]));
        }
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $year, $month),
            new \DateTimeZone(SoSure::TIMEZONE)
        );

        $data = [];

        $reporting = $this->get('app.reporting');
        $report = $reporting->getQuarterlyPL($date);

        return ['data' => $report];
    }

    /**
     * @Route("/underwriting", name="admin_underwriting")
     * @Route("/underwriting/{year}/{month}", name="admin_underwriting_date")
     * @Template
     */
    public function adminUnderWritingAction(Request $request, $year = null, $month = null)
    {
        if ($request->get('_route') == "admin_underwriting") {
            $now = \DateTime::createFromFormat('U', time());
            $now = $now->sub(new \DateInterval('P1Y'));
            return new RedirectResponse($this->generateUrl('admin_underwriting_date', [
                'year' => $now->format('Y'),
                'month' => $now->format('m'),
            ]));
        }
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $year, $month),
            new \DateTimeZone(SoSure::TIMEZONE)
        );

        $data = [];

        /** @var ReportingService $reporting */
        $reporting = $this->get('app.reporting');
        $report = $reporting->getUnderWritingReporting($date);

        return ['data' => $report];
    }

    /**
     * @Route("/pl/print/{year}/{month}", name="admin_quarterly_pl_print")
     */
    public function adminAccountsPrintAction($year, $month)
    {
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $year, $month),
            new \DateTimeZone(SoSure::TIMEZONE)
        );

        $templating = $this->get('templating');
        $snappyPdf = $this->get('knp_snappy.pdf');
        $snappyPdf->setOption('orientation', 'Portrait');
        $snappyPdf->setOption('page-size', 'A4');
        $reporting = $this->get('app.reporting');
        $report = $reporting->getQuarterlyPL($date);
        $html = $templating->render('AppBundle:Pdf:adminQuarterlyPL.html.twig', [
            'data' => $report,
        ]);

        return new Response(
            $snappyPdf->getOutputFromHtml($html),
            200,
            array(
                'Content-Type'          => 'application/pdf',
                'Content-Disposition'   => sprintf('attachment; filename="so-sure-pl-%d-%d.pdf"', $year, $month)
            )
        );
    }

    /**
     * @Route("/connections", name="admin_connections")
     * @Template
     */
    public function connectionsAction()
    {
        return [
            'data' => $this->getConnectionData(),
        ];
    }

    /**
     * @Route("/connections/print", name="admin_connections_print")
     * @Template
     */
    public function connectionsPrintAction()
    {
        $response = new StreamedResponse();
        $response->setCallback(function () {
            $handle = fopen('php://output', 'w+');

            // Add the header of the CSV file
            fputcsv($handle, [
                'Policy Number',
                'Policy Inception Date',
                'Number of Connections',
                'Connection Date 1',
                'Connection Date 2',
                'Connection Date 3',
                'Connection Date 4',
                'Connection Date 5',
                'Connection Date 6',
                'Connection Date 7',
                'Connection Date 8',
            ]);
            foreach ($this->getConnectionData() as $policy) {
                $line = array_merge([
                    $policy['number'],
                    $policy['date'],
                    $policy['connection_count'],
                ], $policy['connections']);
                fputcsv(
                    $handle, // The file pointer
                    $line
                );
            }

            fclose($handle);
        });

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="so-sure-connections.csv"');

        return $response;
    }

    /**
     * @Route("/imei", name="admin_imei")
     * @Template
     */
    public function imeiAction(Request $request)
    {
        $dm = $this->getManager();
        $logRepo = $dm->getRepository(LogEntry::class);
        $chargeRepo = $dm->getRepository(Charge::class);

        $form = $this->createFormBuilder()
            ->add('imei', TextType::class, array(
                'label' => "IMEI",
            ))
            ->add('search', SubmitType::class, array(
                'label' => "Search",
            ))
            ->getForm();
        $history = null;
        $charges = null;

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $imei = trim($form->getData()['imei']);
            $history = $logRepo->findBy([
                'data.imei' => $imei
            ]);
            $unsafeCharges = $chargeRepo->findBy(['details' => $imei]);
            foreach ($unsafeCharges as $unsafeCharge) {
                try {
                    // attempt to access user
                    if ($unsafeCharge->getUser() && $unsafeCharge->getUser()->getName()) {
                        $charges[] = $unsafeCharge;
                    }
                } catch (\Exception $e) {
                    $user = new User();
                    $user->setFirstName('Deleted');
                    $unsafeCharge->setUser($user);
                    $charges[] = $unsafeCharge;
                }
            }

            if (!$this->isImei($imei)) {
                $otherImei = 'unknown - invalid length';
                if (mb_strlen($imei) >= 14) {
                    $otherImei = $this->luhnGenerate(mb_substr($imei, 0, 14));
                }
                $this->addFlash('error', sprintf(
                    sprintf('Invalid IMEI. Did you mean %s?', $otherImei)
                ));
            }
        }

        return [
            'history' => $history,
            'charges' => $charges,
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/detected-imei", name="admin_detected_imei")
     * @Template
     */
    public function detectedImeiAction()
    {
        $redis = $this->get('snc_redis.default');
        /*
                $redis->lpush('DETECTED-IMEI', json_encode([
                    'detected_imei' => 'a123',
                    'suggested_imei' => 'a456',
                    'bucket' => 'a',
                    'key' => 'key',
                ]));
        */
        $imeis = [];
        if ($imei = $redis->lpop('DETECTED-IMEI')) {
            $imeis[] = json_decode($imei, true);
            $redis->lpush('DETECTED-IMEI', $imei);
        }
        return [
            'imeis' => $imeis,
        ];
    }

    private function getConnectionData()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(StandardConnection::class);
        $connections = $repo->findAll();
        $data = [];
        foreach ($connections as $connection) {
            if (!isset($data[$connection->getSourcePolicy()->getId()])) {
                $data[$connection->getSourcePolicy()->getId()] = [
                    'id' => $connection->getSourcePolicy()->getId(),
                    'date' => $connection->getSourcePolicy()->getStart() ?
                        $connection->getSourcePolicy()->getStart()->format('d M Y') :
                        '',
                    'number' => $connection->getSourcePolicy()->getPolicyNumber(),
                    'connections' => [],
                    'connections_details' => [],
                    'isCancelled' => $connection->getSourcePolicy()->isCancelled(),
                ];
            }
            $data[$connection->getSourcePolicy()->getId()]['connections'][] = $connection->getDate() ?
                $connection->getDate()->format('d M Y') :
                '';
            $data[$connection->getSourcePolicy()->getId()]['connections_details'][] = [
                'date' => $connection->getDate() ? $connection->getDate()->format('d M Y') : '',
                'value' => $connection->getValue(),
            ];
        }

        usort($data, function ($a, $b) {
            return $a['date'] >= $b['date'];
        });

        foreach ($data as $key => $policy) {
            $data[$key]['connection_count'] = count($policy['connections']);
            $data[$key]['connections'] = array_slice($policy['connections'], 0, 8);
        }

        return $data;
    }

    /**
     * @Route("/rewards", name="admin_rewards")
     * @Template
     */
    public function rewardsAction(Request $request)
    {
        $connectForm = $this->get('form.factory')
            ->createNamedBuilder('connectForm')
            ->add('email', EmailType::class)
            ->add('amount', TextType::class)
            ->add('rewardId', HiddenType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $rewardForm = $this->get('form.factory')
            ->createNamedBuilder('rewardForm')
            ->add('firstName', TextType::class)
            ->add('lastName', TextType::class)
            ->add('code', TextType::class)
            ->add('email', EmailType::class)
            ->add('defaultValue', TextType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $dm = $this->getManager();
        $rewardRepo = $dm->getRepository(Reward::class);
        $userRepo = $dm->getRepository(User::class);
        $rewards = $rewardRepo->findAll();

        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('connectForm')) {
                    $connectForm->handleRequest($request);
                    if ($connectForm->isValid()) {
                        if ($sourceUser = $userRepo->findOneBy([
                                'emailCanonical' => mb_strtolower($connectForm->getData()['email'])
                            ])) {
                            $reward = $rewardRepo->find($connectForm->getData()['rewardId']);
                            $invitationService = $this->get('app.invitation');
                            foreach ($sourceUser->getValidPolicies() as $policy) {
                                $invitationService->addReward(
                                    $policy,
                                    $reward,
                                    $this->toTwoDp($connectForm->getData()['amount'])
                                );
                            }
                            $this->addFlash('success', sprintf(
                                'Added reward connection'
                            ));

                            return new RedirectResponse($this->generateUrl('admin_rewards'));
                        } else {
                            throw new \InvalidArgumentException(sprintf(
                                'Unable to add reward bonus. %s does not exist as a user',
                                $connectForm->getData()['email']
                            ));
                        }
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add reward connection. %s',
                            (string) $connectForm->getErrors()
                        ));
                    }
                } elseif ($request->request->has('rewardForm')) {
                    $rewardForm->handleRequest($request);
                    if ($rewardForm->isValid()) {
                        $userManager = $this->get('fos_user.user_manager');
                        $user = $userManager->createUser();
                        $user->setEnabled(true);
                        $user->setEmail($this->getDataString($rewardForm->getData(), 'email'));
                        $user->setFirstName($this->getDataString($rewardForm->getData(), 'firstName'));
                        $user->setLastName($this->getDataString($rewardForm->getData(), 'lastName'));
                        $reward = new Reward();
                        $reward->setUser($user);
                        $reward->setDefaultValue($this->getDataString($rewardForm->getData(), 'defaultValue'));
                        $dm->persist($user);
                        $dm->persist($reward);

                        $code = $this->getDataString($rewardForm->getData(), 'code');
                        if (mb_strlen($code) > 0) {
                            $scode = new SCode();
                            $scode->setCode($code);
                            $scode->setReward($reward);
                            $scode->setType(SCode::TYPE_REWARD);
                            $dm->persist($scode);
                        }
                        $dm->flush();
                        $this->addFlash('success', sprintf(
                            'Added reward'
                        ));

                        return new RedirectResponse($this->generateUrl('admin_rewards'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add reward. %s',
                            (string) $rewardForm->getErrors()
                        ));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return [
            'rewards' => $rewards,
            'connectForm' => $connectForm->createView(),
            'rewardForm' => $rewardForm->createView(),
        ];
    }

    /**
     * @Route("/company", name="admin_company")
     * @Template
     */
    public function companyAction(Request $request)
    {
        $belongForm = $this->get('form.factory')
            ->createNamedBuilder('belongForm')
            ->add('email', EmailType::class)
            ->add('companyId', HiddenType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $companyForm = $this->get('form.factory')
            ->createNamedBuilder('companyForm')
            ->add('name', TextType::class)
            ->add('address1', TextType::class)
            ->add('address2', TextType::class, ['required' => false])
            ->add('address3', TextType::class, ['required' => false])
            ->add('city', TextType::class)
            ->add('postcode', TextType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $dm = $this->getManager();
        $companyRepo = $dm->getRepository(CustomerCompany::class);
        $userRepo = $dm->getRepository(User::class);
        $companies = $companyRepo->findAll();

        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('belongForm')) {
                    $belongForm->handleRequest($request);
                    if ($belongForm->isValid()) {
                        $user = $userRepo->findOneBy([
                            'emailCanonical' => mb_strtolower($belongForm->getData()['email'])
                        ]);
                        if (!$user) {
                            $userManager = $this->get('fos_user.user_manager');
                            $user = $userManager->createUser();
                            $user->setEnabled(true);
                            $user->setEmail($this->getDataString($belongForm->getData(), 'email'));
                            $dm->persist($user);
                        }
                        $company = $companyRepo->find($belongForm->getData()['companyId']);
                        if (!$company) {
                            throw new \InvalidArgumentException(sprintf(
                                'Unable to add user (%s) to company. Company is missing',
                                $belongForm->getData()['email']
                            ));
                        }
                        $company->addUser($user);
                        if (!$user->getBillingAddress()) {
                            $user->setBillingAddress($company->getAddress());
                        }
                        $dm->flush();
                        $this->addFlash('success', sprintf(
                            'Added %s to %s',
                            $user->getName(),
                            $company->getName()
                        ));

                        return new RedirectResponse($this->generateUrl('admin_company'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add user to company. %s',
                            (string) $belongForm->getErrors()
                        ));
                    }
                } elseif ($request->request->has('companyForm')) {
                    $companyForm->handleRequest($request);
                    if ($companyForm->isValid()) {
                        $company = new CustomerCompany();
                        $company->setName($this->getDataString($companyForm->getData(), 'name'));
                        $address = new Address();
                        $address->setLine1($this->getDataString($companyForm->getData(), 'address1'));
                        $address->setLine2($this->getDataString($companyForm->getData(), 'address2'));
                        $address->setLine3($this->getDataString($companyForm->getData(), 'address3'));
                        $address->setCity($this->getDataString($companyForm->getData(), 'city'));
                        $address->setPostcode($this->getDataString($companyForm->getData(), 'postcode'));
                        $company->setAddress($address);
                        $dm->persist($company);
                        $dm->flush();
                        $this->addFlash('success', sprintf(
                            'Added company'
                        ));

                        return new RedirectResponse($this->generateUrl('admin_company'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add company. %s',
                            (string) $companyForm->getErrors()
                        ));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return [
            'companies' => $companies,
            'belongForm' => $belongForm->createView(),
            'companyForm' => $companyForm->createView(),
        ];
    }

    /**
     * @Route("/policy-breakdown", name="admin_policy_breakdown")
     * @Template
     */
    public function breakdownAction()
    {
        $policyService = $this->get('app.policy');
        return [
            'data' => $policyService->getBreakdownData(),
        ];
    }

    /**
     * @Route("/policy-breakdown/print", name="admin_policy_breakdown_print")
     * @Template
     */
    public function breakdownPrintAction()
    {
        $policyService = $this->get('app.policy');
        $now = \DateTime::createFromFormat('U', time());

        return new Response(
            $policyService->getBreakdownPdf(),
            200,
            array(
                'Content-Type'          => 'application/pdf',
                'Content-Disposition'   =>
                    sprintf('attachment; filename="so-sure-policy-breakdown-%s.pdf"', $now->format('Y-m-d'))
            )
        );
    }

    /**
     * @Route("/phone/{id}/higlight", name="admin_phone_highlight")
     * @Method({"POST"})
     */
    public function phoneHighlightAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            if ($phone->isHighlight()) {
                $phone->setHighlight(false);
                $message = 'Phone is no longer highlighted';
            } else {
                $phone->setHighlight(true);
                $message = 'Phone is now highlighted';
            }
            $dm->flush();
            $this->addFlash(
                'success',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/{id}/newhighdemand", name="admin_phone_newhighdemand")
     * @Method({"POST"})
     */
    public function phoneNewHighDemandAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            if ($phone->isNewHighDemand()) {
                $phone->setNewHighDemand(false);
                $message = 'Phone is no longer set to new high demand';
            } else {
                $phone->setNewHighDemand(true);
                $message = 'Phone is now set to new high demand';
            }
            $dm->flush();
            $this->addFlash(
                'success',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/checkpremium/{price}", name="admin_phone_check_premium_price")
     * @Method({"POST"})
     */
    public function phoneCheckPremium(Request $request, $price)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('access_token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $phone = new Phone();
        $phone->setInitialPrice($price);
        try {
            $response['calculatedPremium'] = $phone->getSalvaBinderMonthlyPremium();
        } catch (\Exception $e) {
            $this->get('logger')->error(
                sprintf("Error in call to getSalvaBinderMonthlyPremium."),
                ['exception' => $e]
            );
            $response['calculatedPremium'] = 'no data';
        }
        return new Response(json_encode($response));
    }

    /**
     * @Route("/payments", name="admin_payments")
     * @Route("/payments/{year}/{month}", name="admin_payments_date")
     * @Template
     */
    public function paymentsAction($year = null, $month = null)
    {
        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));
        /** @var ReportingService $reporting */
        $reporting = $this->get('app.reporting');
        $data = $reporting->payments($date);

        return [
            'data' => $data,
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * @Route("/picsure", name="admin_picsure")
     * @Route("/picsure/{id}/approve", name="admin_picsure_approve")
     * @Route("/picsure/{id}/reject", name="admin_picsure_reject")
     * @Route("/picsure/{id}/invalid", name="admin_picsure_invalid")
     * @Template
     */
    public function picsureAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = null;
        if ($id) {
            /** @var PhonePolicy $policy */
            $policy = $repo->find($id);
        }
        $picSureSearchForm = $this->get('form.factory')
            ->createNamedBuilder('search_form', PicSureSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $picSureSearchForm->handleRequest($request);

        if ($request->get('_route') == "admin_picsure_approve") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED, $this->getUser());
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'pic-sure is successfully validated',
                $policy->getUser()->getEmail(),
                'AppBundle:Email:picsure/accepted.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:picsure/accepted.txt.twig',
                ['policy' => $policy]
            );

            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Your pic-sure image has been approved and your phone is now validated.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            if (count($picsureFiles) > 0) {
                $this->get('event_dispatcher')->dispatch(
                    PicsureEvent::EVENT_APPROVED,
                    new PicsureEvent($policy, $picsureFiles[0])
                );
            } else {
                $this->get('logger')->error(sprintf("Missing picture file in policy %s.", $policy->getId()));
            }

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        } elseif ($request->get('_route') == "admin_picsure_reject") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED, $this->getUser());
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'pic-sure failed to validate your phone',
                $policy->getUser()->getEmail(),
                'AppBundle:Email:picsure/rejected.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:picsure/rejected.txt.twig',
                ['policy' => $policy]
            );
            if ($policy->isWithinCooloffPeriod()) {
                $mailer->sendTemplate(
                    'Please cancel (cooloff) policy due to pic-sure rejection',
                    'support@wearesosure.com',
                    'AppBundle:Email:picsure/adminRejected.html.twig',
                    ['policy' => $policy]
                );
                $this->addFlash('error', sprintf(
                    'Policy <a href="%s">%s</a> should be cancelled (intercom support message also sent).',
                    $this->get('app.router')->generateUrl('admin_policy', ['id' => $policy->getId()]),
                    $policy->getPolicyNumber()
                ));
            }
            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Your pic-sure image has been rejected. If you phone was damaged prior to your policy purchase, then it is crimial fraud to claim on our policy. Please contact us if you have purchased this policy by mistake.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            if (count($picsureFiles) > 0) {
                $this->get('event_dispatcher')->dispatch(
                    PicsureEvent::EVENT_REJECTED,
                    new PicsureEvent($policy, $picsureFiles[0])
                );
            } else {
                $this->get('logger')->error(sprintf("Missing picture file in policy %s.", $policy->getId()));
            }

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        } elseif ($request->get('_route') == "admin_picsure_invalid") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID, $this->getUser());
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'Sorry, we need another pic-sure',
                $policy->getUser()->getEmail(),
                'AppBundle:Email:picsure/invalid.html.twig',
                ['policy' => $policy, 'additional_message' => $request->get('message')],
                'AppBundle:Email:picsure/invalid.txt.twig',
                ['policy' => $policy, 'additional_message' => $request->get('message')]
            );
            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Sorry, but we were not able to see your phone clearly enough to determine if the phone is undamaged. Please try uploading your pic-sure photo again making sure that the phone is clearly visible in the photo.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            if (count($picsureFiles) > 0) {
                $this->get('event_dispatcher')->dispatch(
                    PicsureEvent::EVENT_INVALID,
                    new PicsureEvent($policy, $picsureFiles[0])
                );
            } else {
                $this->get('logger')->error(sprintf("Missing picture file in policy %s.", $policy->getId()));
            }

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        }

        $status = $request->get('status');
        $data = $picSureSearchForm->get('status')->getData();
        $qb = $repo->createQueryBuilder()
            ->field('picSureStatus')->equals($data)
            ->sort('picSureApprovedDate', 'desc')
            ->sort('created', 'desc');
        $pager = $this->pager($request, $qb);
        return [
            'policies' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'status' => $data,
            'picsure_search_form' => $picSureSearchForm->createView(),
        ];
    }

    /**
     * @Route("/picsure/image/{file}", name="admin_picsure_image", requirements={"file"=".*"})
     * @Template()
     */
    public function picsureImageAction($file)
    {
        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3policy_fs');
        $environment = $this->getParameter('kernel.environment');
        $file = str_replace(sprintf('%s/', $environment), '', $file);

        if (!$filesystem->has($file)) {
            throw $this->createNotFoundException(sprintf('URL not found %s', $file));
        }

        $mimetype = $filesystem->getMimetype($file);
        return StreamedResponse::create(
            function () use ($file, $filesystem) {
                $stream = $filesystem->readStream($file);
                echo stream_get_contents($stream);
                flush();
            },
            200,
            array('Content-Type' => $mimetype)
        );
    }

    /**
     * @Route("/affiliate", name="admin_affiliate")
     * @Template
     */
    public function affiliateAction(Request $request)
    {

        $time_range = [
            30 => 30,
            60 => 60,
            90 => 90
        ];

        $lead_sources = [
            'invitation' => 'invitation',
            'scode' => 'scode',
            'affiliate' => 'affiliate'
        ];

        $companyForm = $this->get('form.factory')
            ->createNamedBuilder('companyForm')
            ->add('name', TextType::class)
            ->add('address1', TextType::class)
            ->add('address2', TextType::class, ['required' => false])
            ->add('address3', TextType::class, ['required' => false])
            ->add('city', TextType::class)
            ->add('postcode', TextType::class)
            ->add('cpa', NumberType::class, ['constraints' => [new Assert\Range(['min' => 0, 'max' => 20])]])
            ->add('days', ChoiceType::class, ['required' => true, 'choices' => $time_range])
            ->add('campaignSource', TextType::class, ['required' => false])
            ->add('leadSource', ChoiceType::class, ['required' => false, 'choices' => $lead_sources])
            ->add('leadSourceDetails', TextType::class, ['required' => false ])
            ->add('next', SubmitType::class)
            ->getForm();

        $dm = $this->getManager();
        $companyRepo = $dm->getRepository(AffiliateCompany::class);
        $userRepo = $dm->getRepository(User::class);
        $companies = $companyRepo->findAll();

        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('companyForm')) {
                    $companyForm->handleRequest($request);
                    if ($companyForm->isValid()) {
                        $company = new AffiliateCompany();
                        $company->setName($this->getDataString($companyForm->getData(), 'name'));
                        $address = new Address();
                        $address->setLine1($this->getDataString($companyForm->getData(), 'address1'));
                        $address->setLine2($this->getDataString($companyForm->getData(), 'address2'));
                        $address->setLine3($this->getDataString($companyForm->getData(), 'address3'));
                        $address->setCity($this->getDataString($companyForm->getData(), 'city'));
                        $postcode = $this->getDataString($companyForm->getData(), 'postcode');
                        try {
                            $address->setPostcode($postcode);
                        } catch (\InvalidArgumentException $e) {
                            throw new \InvalidArgumentException("{$postcode} is not a valid post code.");
                        }
                        $company->setAddress($address);
                        $company->setDays($this->getDataString($companyForm->getData(), 'days'));
                        $company->setCampaignSource($this->getDataString($companyForm->getData(), 'campaignSource'));
                        $company->setLeadSource($this->getDataString($companyForm->getData(), 'leadSource'));
                        $company->setLeadSourceDetails(
                            $this->getDataString($companyForm->getData(), 'leadSourceDetails')
                        );
                        $company->setCPA($this->getDataString($companyForm->getData(), 'cpa'));
                        $dm->persist($company);
                        $dm->flush();
                        $this->addFlash('success', 'Added affiliate');

                        return new RedirectResponse($this->generateUrl('admin_affiliate'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add company. %s',
                            (string) $companyForm->getErrors()
                        ));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return [
            'companies' => $companies,
            'companyForm' => $companyForm->createView(),
        ];
    }

    /**
     * @Route("/affiliate/charge/{id}/{year}/{month}", name="admin_affiliate_charge")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliateChargeAction($id, $year = null, $month = null)
    {
        $now = \DateTime::createFromFormat('U', time());
        $year = $year ?: $now->format('Y');
        $month = $month ?: $now->format('m');
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $chargeRepo = $dm->getRepository(Charge::class);
        $affiliate = $affiliateRepo->find($id);
        if ($affiliate) {
            $charges = $chargeRepo->findMonthly($date, 'affiliate', false, $affiliate);
            return ['affiliate' => $affiliate,
                'charges' => $charges,
                'cost' => $affiliate->getCpa() * count($charges),
                'month' => $month,
                'year' => $year,
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/affiliate/pending/{id}", name="admin_affiliate_pending")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliatePendingAction($id)
    {
        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $affiliate = $affiliateRepo->find($id);
        $affiliateService = $this->get("app.affiliate");
        if ($affiliate) {
            return [
                'affiliate' => $affiliate,
                'pending' => $affiliateService->getMatchingUsers($affiliate, [User::AQUISITION_PENDING])
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/affiliate/potential/{id}", name="admin_affiliate_potential")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliatePotentialAction($id)
    {
        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $affiliate = $affiliateRepo->find($id);
        $affiliateService = $this->get("app.affiliate");
        if ($affiliate) {
            return [
                'affiliate' => $affiliate,
                'potential' => $affiliateService->getMatchingUsers($affiliate, [User::AQUISITION_POTENTIAL])
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/affiliate/lost/{id}", name="admin_affiliate_lost")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliateLostAction($id)
    {
        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $affiliate = $affiliateRepo->find($id);
        $affiliateService = $this->get("app.affiliate");
        if ($affiliate) {
            return [
                'affiliate' => $affiliate,
                'lost' => $affiliateService->getMatchingUsers($affiliate, [User::AQUISITION_LOST])
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/promotions", name="admin_promotions")
     * @Template("AppBundle:AdminEmployee:promotions.html.twig")
     */
    public function promotionsAction(Request $request)
    {
        $dm = $this->getManager();
        $promotionRepository = $dm->getRepository(Promotion::class);
        $promotionForm = $this->get('form.factory')
            ->createNamedBuilder('promotionForm')
            ->add('name', TextType::class)
            ->add('condition', ChoiceType::class, ['choices' => Promotion::CONDITIONS])
            ->add('reward', ChoiceType::class, ['choices' => Promotion::REWARDS])
            ->add('conditionDays', NumberType::class, ['constraints' => [new Assert\Range(['min' => 0, 'max' => 90])]])
            ->add('conditionEvents', NumberType::class, ['constraints' => [new Assert\Range(['min' => 1, 'max' => 50])], 'required' => false])
            ->add('rewardAmount', NumberType::class, ['constraints' => [new Assert\Range(['min' => 1, 'max' => 50])], 'required' => false])
            ->add('next', SubmitType::class)
            ->getForm();
        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('promotionForm')) {
                    $promotionForm->handleRequest($request);
                    if ($promotionForm->isValid()) {
                        $promotion = new Promotion();
                        $promotion->setName($this->getDataString($promotionForm->getData(), 'name'));
                        $promotion->setCondition($this->getDataString($promotionForm->getData(), 'condition'));
                        $promotion->setReward($this->getDataString($promotionForm->getData(), 'reward'));
                        $promotion->setConditionDays($this->getDataString($promotionForm->getData(), 'conditionDays'));
                        $promotion->setConditionEvents($this->getDataString($promotionForm->getData(), 'conditionEvents'));
                        $promotion->setRewardAmount($this->getDataString($promotionForm->getData(), 'rewardAmount'));
                        $promotion->setStart(new \DateTime());
                        $promotion->setActive(true);
                        $dm->persist($promotion);
                        $dm->flush();
                        $this->addFlash('success', 'Added Promotion');
                        return new RedirectResponse($this->generateUrl('admin_promotions'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add company. %s',
                            (string) $promotionForm->getErrors()
                        ));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        // TODO: order them so that inactive come after active, but beside that it's ordered by newness.
        $promotions = $promotionRepository->findAll();
        return ["promotions" => $promotions, "promotionForm" => $promotionForm->createView()];
    }

    /**
     * @Route("/promotion/{id}", name="admin_promotion")
     * @Template("AppBundle:AdminEmployee:promotion.html.twig")
     */
    public function promotionAction($id)
    {
        $dm = $this->getManager();
        $promotionRepository = $dm->getRepository(Promotion::class);
        $promotion = $promotionRepository->find($id);
        return ["promotion" => $promotion];
    }
}
