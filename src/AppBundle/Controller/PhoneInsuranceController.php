<?php

namespace AppBundle\Controller;

use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\InvalidFullNameException;
use AppBundle\Exception\ValidationException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\LeadEmailType;
use AppBundle\Form\Type\RegisterUserType;
use AppBundle\Form\Type\PhoneMakeType;
use AppBundle\Form\Type\SmsAppLinkType;

use AppBundle\Document\Form\Register;
use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\PolicyTerms;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\IntercomService;

use AppBundle\Classes\GoCompare;

class PhoneInsuranceController extends BaseController
{
    use PhoneTrait;

    /**
     * @Route("/phone-insurance/water-damage", name="phone_insurance_water_damage")
     * @Route("/mobile-insurance/water-damage", name="mobile_insurance_water_damage")
     * @Template()
     */
    public function waterDamageAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_water_damage') {
            return $this->redirectToRoute('phone_insurance_water_damage', [], 301);
        }

        return array();
    }

    /**
     * @Route("/phone-insurance/theft", name="phone_insurance_theft")
     * @Route("/mobile-insurance/theft", name="mobile_insurance_theft")
     * @Template()
     */
    public function theftAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_theft') {
            return $this->redirectToRoute('phone_insurance_theft', [], 301);
        }

        return array();
    }

    /**
     * @Route("/phone-insurance/loss", name="phone_insurance_loss")
     * @Route("/mobile-insurance/loss", name="mobile_insurance_loss")
     * @Template()
     */
    public function lossAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_loss') {
            return $this->redirectToRoute('phone_insurance_loss', [], 301);
        }

        return array();
    }

    /**
     * @Route("/phone-insurance/cracked-screen", name="phone_insurance_cracked_screen")
     * @Route("/mobile-insurance/cracked-screen", name="mobile_insurance_cracked_screen")
     * @Template()
     */
    public function crackedScreenAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_cracked_screen') {
            return $this->redirectToRoute('phone_insurance_cracked_screen', [], 301);
        }

        return array();
    }

    /**
     * @Route("/phone-insurance/broken-phone", name="phone_insurance_broken_phone")
     * @Route("/mobile-insurance/broken-phone", name="mobile_insurance_broken_phone")
     * @Template()
     */
    public function brokenPhoneAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_broken_phone') {
            return $this->redirectToRoute('phone_insurance_broken_phone', [], 301);
        }

        return array();
    }

    /**
     * SEO Pages - Phone Insurance
     * @Route("/phone-insurance", name="phone_insurance", options={"sitemap" = true})
     */
    public function phoneInsuranceAction()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;

        // To display lowest monthly premium
        $fromPhones = $repo->findBy([
            'active' => true,
        ]);

        $fromPhones = array_filter($fromPhones, function ($phone) {
            return $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
        });

        // Sort by cheapest
        usort($fromPhones, function ($a, $b) {
            return $a->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice() <
            $b->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice() ? -1 : 1;
        });

        // Select the lowest
        $fromPrice = $fromPhones[0]->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice();

        $data = [
            'from_price' => $fromPrice,
            'from_phones' => $fromPhones,
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'O2',
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsurance.html.twig', $data);
    }

    /**
     * Route for Quote ID
     * @Route("/phone-insurance/{id}", name="quote_phone", requirements={"id":"[0-9a-f]{24,24}"})
    */
    public function phoneInsuranceIdAction($id = null, $make = null, $model = null, $memory = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);

        if ($id) {
            /** @var Phone $phone */
            $phone = $repo->find($id);
            return $this->redirectToRoute('phone_insurance_make_model_memory', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical(),
                'memory' => $phone->getMemory(),
            ], 301);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for id: %s make: %s model: %s mem: %s',
                $id,
                $make,
                $model,
                $memory
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }
    }

    /**
     * SEO Pages - Phone Insurance > Make
     * @Route("/phone-insurance/{make}",
     * name="phone_insurance_make", requirements={"make":"[a-zA-Z]+"})
     */
    public function phoneInsuranceMakeAction($make = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;

        $phones = $repo->findBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make)
        ]);

        if (count($phones) != 0) {
            $phone = $phones[0];
        } else {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
            ]);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find make page for: %s',
                $make
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        // To display in Popular Models sections
        $topPhones = $repo->findBy([
            'active' => true,
            'topPhone' => true,
            'makeCanonical' => mb_strtolower($make)
        ]);

        // To display lowest monthly premium
        $fromPhones = $repo->findBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make)
        ]);

        $fromPhones = array_filter($phones, function ($phone) {
            return $phone->getCurrentMonthlyPhonePrice();
        });

        // Sort by cheapest
        usort($fromPhones, function ($a, $b) {
            return $a->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice() <
            $b->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice() ? -1 : 1;
        });

        // Select the lowest
        $fromPrice = $fromPhones[0]->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice();

        $data = [
            'phone' => $phone,
            'top_phones' => $topPhones,
            'from_price' => $fromPrice,
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'O2',
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMake.html.twig', $data);
    }

    /**
     * SEO Pages - Phone Insurance > Make > Model
     * @Route("/phone-insurance/{make}/{model}", name="phone_insurance_make_model",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     */
    public function phoneInsuranceMakeModelAction($make = null, $model = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);

        $phones = $repo->findBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make),
            'modelCanonical' => mb_strtolower($decodedModel)
        ]);

        if (count($phones) != 0 && mb_stripos($model, ' ') === false) {
            $phone = $phones[0];
        } else {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModelHyph),
            ]);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for make/model page - make: %s model: %s',
                $make,
                $model
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        // Model template control
        // Hyphenate Model for images/template
        $modelHyph = str_replace('+', '-', $model);
        // List all available hero images otherwise switch to genric
        $availableImages = [
            'iphone-x',
            'iphone-xr',
            'iphone-xs',
            'iphone-7',
            'iphone-8',
            'galaxy-s8',
            'galaxy-s9',
            'galaxy-note-9',
            'pixel',
            'pixel-3-xl',
        ];
        // TODO: use make in template names
        $templateOverides = [
            'nokia 6'
        ];
        $templateOveride = $make." ".$model;
        $hideSection = false;
        $templateModel = $modelHyph.'.html.twig';
        $template = 'AppBundle:PhoneInsurance/Phones:'.$templateModel;

        // Check if template exists else default
        if (!$this->get('templating')->exists($template) or in_array($templateOveride, $templateOverides)) {
            $hideSection = true;
            $template = 'AppBundle:PhoneInsurance:phoneInsuranceMakeModel.html.twig';
        }

        $data = [
            'phone' => $phone,
            'phone_price' => $phone->getCurrentMonthlyPhonePrice(),
            'img_url' => $modelHyph,
            'available_images' => $availableImages,
            'hide_section' => $hideSection,
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'O2',
        ];

        return $this->render($template, $data);
    }

    /**
     * SEO/Quote Page - Phone Insurance > Make > Model > Memory
     * @Route("/phone-insurance/{make}+{model}+{memory}GB",
     * name="phone_insurance_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function phoneInsuranceMakeModelMemoryAction(
        Request $request,
        $make = null,
        $model = null,
        $memory = null
    ) {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);

        $phone = $repo->findOneBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make),
            'modelCanonical' => mb_strtolower($decodedModel),
            'memory' => (int) $memory
        ]);
        // check for historical urls
        if (!$phone || mb_stripos($model, ' ') !== false) {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModelHyph),
                'memory' => (int) $memory
            ]);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for make/model/memory page - make: %s model: %s mem: %s',
                $make,
                $model,
                $memory
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        $quoteUrl = $this->setPhoneSession($request, $phone);

        // In-store
        $instore = $this->get('session')->get('store');

        $buyForm = $this->makeBuyButtonForm('buy_form', 'buy');
        $buyBannerForm = $this->makeBuyButtonForm('buy_form_banner');
        $buyBannerTwoForm = $this->makeBuyButtonForm('buy_form_banner_two');

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('buy_form')) {
                $buyForm->handleRequest($request);
                if ($buyForm->isValid()) {
                    $properties = [];
                    if ($buyForm->get('buy')->isClicked()) {
                        $properties['Location'] = 'main';
                    }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
                    }

                    return $this->redirectToRoute('purchase');
                }
            } elseif ($request->request->has('buy_form_banner')) {
                $buyBannerForm->handleRequest($request);
                if ($buyBannerForm->isValid()) {
                    $properties = [];
                    $properties['Location'] = 'seeFullDetailsMobile';

                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
                    }

                    return $this->redirectToRoute('purchase');
                }
            } elseif ($request->request->has('buy_form_banner_two')) {
                $buyBannerTwoForm->handleRequest($request);
                if ($buyBannerTwoForm->isValid()) {
                    $properties = [];
                    $properties['Location'] = 'seeFullDetailsDesktop';

                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
                    } else {
                        return $this->redirectToRoute('purchase');
                    }
                }
            }
        }

        // if no price, will be sample policy of £100 annually
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
        $maxPot = $price ? $price->getMaxPot() : 80;
        $maxConnections = $price ? $price->getMaxConnections() : 8;
        $annualPremium = $price ? $price->getYearlyPremiumPrice() : 100;
        $maxComparision = $phone->getMaxComparision() ? $phone->getMaxComparision() : 80;
        $expIntercom = null;

        // only need to run this once - if its a post, then ignore
        if ('GET' === $request->getMethod() && $price) {
            $event = MixpanelService::EVENT_QUOTE_PAGE;
            $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                'Device Selected' => $phone->__toString(),
                'Monthly Cost' => $price->getMonthlyPremiumPrice(),
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'First Device Selected' => $phone->__toString(),
                'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
            ], true);
        }

        $priceService = $this->get('app.price');

        // A/B UK Flag Test
        // To Test use url param ?force=flag / ?force=no-flag
        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_UK_FLAG);

        $data = [
            'phone' => $phone,
            'prices' => $priceService->userPhonePriceStreams(null, $phone, new \DateTime()),
            'buy_form' => $buyForm->createView(),
            'buy_form_banner' => $buyBannerForm->createView(),
            'buy_form_banner_two'   => $buyBannerTwoForm->createView(),
            'phones' => $repo->findBy(
                [
                    'active' => true,
                    'makeCanonical' => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($decodedModel)
                ],
                ['memory' => 'asc']
            ),
            'instore' => $instore,
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'O2',
        ];
        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMakeModelMemory.html.twig', $data);
    }


    /**
     * ONLY Used for admin ????
     * @Route("/purchase-phone/{make}+{model}+{memory}GB", name="purchase_phone_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function purchasePhoneAction(Request $request, $make = null, $model = null, $memory = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $phone = $repo->findOneBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make),
            'modelCanonical' => mb_strtolower($decodedModel),
            'memory' => (int) $memory
        ]);
        if (!$phone) {
            throw $this->createNotFoundException('Unable to locate phone');
        }

        $quoteUrl = $this->setPhoneSession($request, $phone);
        if ($request->get('_route') == 'purchase_phone_make_model_memory') {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, [
                'Location' => 'offsite'
            ]);

            // Multipolicy should skip user details
            if ($this->getUser() && $this->getUser()->hasPolicy()) {
                // don't check for partial partial as quote phone may be different from partial policy phone
                return $this->redirectToRoute('purchase_step_phone');
            } else {
                return $this->redirectToRoute('purchase', [], 301);
            }
        }
    }

    /**
     * @Route("/quote-me/{id}", name="quote_me", requirements={"id":"[0-9a-f]{1,24}"})
     * @Route("/quote-me/{make}+{model}+{memory}GB", name="quote_me_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function quoteMe(Request $request, $id = null, $make = null, $model = null, $memory = null)
    {
        // For generic use by insurance aggregator sites
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $decodedModel = Phone::decodeModel($model);
        $phone = null;

        if ($id) {
            if ($request->query->get('aggregator')) {
                // If aggregator set, look for aggregator ID instead of phone ID
                if ($request->query->get('aggregator') == 'GoCompare') {
                    $goCompare = new GoCompare();
                    if (array_key_exists($id, $goCompare::$models)) {
                        /** @var Phone $phone */
                        $phone = $repo->findOneBy([
                            'active' => true,
                            'makeCanonical' => mb_strtolower($goCompare::$models[$id]['make']),
                            'modelCanonical' => mb_strtolower($goCompare::$models[$id]['model']),
                            'memory' => (int) $goCompare::$models[$id]['memory']
                        ]);
                    }
                }
            } else {
                /** @var Phone $phone */
                $phone = $repo->find($id);
            }
        }
        if ($memory) {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModel),
                'memory' => (int) $memory
            ]);
        }
        if ($phone) {
            $response = new JsonResponse([
                'phoneId' => $phone->getId(),
                'price' => [
                    'monthlyPremium' => $phone->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice(),
                    'yearlyPremium' => $phone->getCurrentYearlyPhonePrice()->getYearlyPremiumPrice()
                ],
                'productOverrides' => [
                    'excesses' => $phone->getCurrentMonthlyPhonePrice()->getExcess() ?
                        $phone->getCurrentMonthlyPhonePrice()->getExcess()->toApiArray() :
                        [],
                    'picsureExcesses' => $phone->getCurrentMonthlyPhonePrice()->getPicSureExcess() ?
                        $phone->getCurrentMonthlyPhonePrice()->getPicSureExcess()->toApiArray() :
                        []
                ],
                'purchaseUrlRedirect' => $this->getParameter('web_base_url').'/phone-insurance/'.
                    str_replace(
                        ' ',
                        '+',
                        $phone->getMake().'+'.$phone->getModel().'+'.$phone->getMemory()
                    ).'GB'
            ]);
            return $response;
        }

        throw $this->createNotFoundException('Phone not found');
    }

    /**
     * @Route("/list-phones", name="list_phones")
     */
    public function listPhones(Request $request)
    {
        // For generic use by insurance aggregator sites
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();
        $list = [];

        foreach ($phones as $phone) {
            // Loop through each phone and make an array for the response
            $aggregatorId = '';
            if ($request->query->get('aggregator')) {
                // If aggregator set, look for aggregator ID (if applicable)
                if ($request->query->get('aggregator') == 'GoCompare') {
                    $goCompare = new GoCompare();
                    foreach ($goCompare::$models as $index => $model) {
                        if ($model['make'] == $phone->getMake()
                            && $model['model'] == $phone->getModel()
                            && $model['memory'] == $phone->getMemory()
                        ) {
                            $aggregatorId = $index;
                        }
                    }
                }
            }
            $list[] = [
                'id'            => $phone->getId(),
                'make'          => $phone->getMake(),
                'model'         => $phone->getModel(),
                'memory'        => $phone->getMemory(),
                'aggregatorId'  => $aggregatorId
            ];
        }
        $response = new JsonResponse($list);
        return $response;
    }

    private function makeBuyButtonForm(string $formName, string $buttonName = 'buy')
    {
        return $this->get('form.factory')
            ->createNamedBuilder($formName)
            ->add($buttonName, SubmitType::class)
            ->add('slider_used', HiddenType::class)
            ->getForm();
    }

    private function getAllPhones($make)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(
            ['make' => $make, 'active' => true, 'highlight' => true],
            ['releaseDate' => 'desc', 'initialPrice' => 'desc']
        );
        if (count($phones) == 0) {
            throw $this->createNotFoundException('No phones with make are available');
        }

        return $phones;
    }

    private function sortPhoneNamesByMemory($phones): array
    {
        $phonesMem = [];
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            if (!isset($phonesMem[$phone->getName()])) {
                $phonesMem[$phone->getName()] = [
                    'make' => $phone->getMake(),
                    'model' => $phone->getModel(),
                    'currentPhonePrice' => $phone->getCurrentPhonePrice(),
                    'imageUrlWithFallback' => $phone->getImageUrlWithFallback(),
                ];
            }
            $phonesMem[$phone->getName()]['mem'][$phone->getMemory()] = $this->generateUrl(
                'phone_insurance_make_model_memory',
                [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getModelCanonical(),
                    'memory' => $phone->getMemory()
                ]
            );
            ksort($phonesMem[$phone->getName()]['mem']);
        }

        return $phonesMem;
    }

    private function competitorsData()
    {
        $competitor = [
            'PYB' => [
                'name' => 'Protect Your Bubble',
                'days' => '<strong>1 - 5</strong> days <div>depending on stock</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'From approved retailers only',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 4.5,
            ],
            'GC' => [
                'name' => 'Gadget<br>Cover',
                'days' => '<strong>5 - 7</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'From approved retailers only',
                'phoneage' => '<strong>18 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 2,
            ],
            'SS' => [
                'name' => 'Simplesurance',
                'days' => '<strong>3 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => '<i class="far fa-times fa-2x"></i>',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1,
            ],
            'CC' => [
                'name' => 'CloudCover',
                'days' => '<strong>3 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => '<i class="far fa-times fa-2x"></i>',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 3,
            ],
            'END' => [
                'name' => 'Endsleigh',
                'days' => '<strong>1 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-check',
                'oldphones' => '<i class="far fa-check fa-2x"></i>',
                'phoneage' => '<strong>3 years</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1,
            ],
            'LICI' => [
                'name' => 'Loveit<br>coverIt.co.uk',
                'days' => '<strong>1 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => '<i class="far fa-times fa-2x"></i>',
                'phoneage' => '<strong>3 years</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 2,
            ],
            'O2' => [
                'name' => 'O2',
                'days' => '<strong>1 - 7</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'From 02 only',
                'phoneage' => '<strong>29 days</strong> <div>O2 phones only</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1.5,
            ],
        ];

        return $competitor;
    }
}
