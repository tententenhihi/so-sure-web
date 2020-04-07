<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Form\Type\CompanyLeadType;
use AppBundle\Form\Type\EmailOptInType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Form\Type\MarketingEmailOptOutType;
use AppBundle\Repository\OptOut\EmailOptOutRepository;
use AppBundle\Service\InvitationService;
use AppBundle\Service\MailerService;
use AppBundle\Service\RateLimitService;
use AppBundle\Service\RequestService;
use AppBundle\Service\ClaimsService;
use PHPStan\Rules\Arrays\AppendedArrayItemTypeRule;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\LeadEmailType;
use AppBundle\Form\Type\RegisterUserType;
use AppBundle\Form\Type\PhoneMakeType;
use AppBundle\Form\Type\PhoneDropdownType;
use AppBundle\Form\Type\SmsAppLinkType;
use AppBundle\Form\Type\ClaimFnolEmailType;
use AppBundle\Form\Type\ClaimFnolType;

use AppBundle\Document\Form\Register;
use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\Form\PhoneDropdown;
use AppBundle\Document\Form\ClaimFnol;
use AppBundle\Document\Form\ClaimFnolEmail;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Lead;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\Opt;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Charge;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;

use AppBundle\Validator\Constraints\UkMobileValidator;

class DefaultController extends BaseController
{
    use PhoneTrait;
    use \Symfony\Component\Security\Http\Util\TargetPathTrait;

    /**
     * @Route("/", name="homepage", options={"sitemap" = true})
     * @Route("/replacement-24", name="replacement_24_landing")
     * @Route("/replacement-72", name="replacement_72_landing")
     * @Route("/reimagined", name="reimagined")
     * @Route("/hasslefree", name="hasslefree")
     */
    public function indexAction(Request $request)
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
            return $a->getCurrentYearlyPhonePrice()->getMonthlyPremiumPrice() <
            $b->getCurrentYearlyPhonePrice()->getMonthlyPremiumPrice() ? -1 : 1;
        });

        // Select the lowest
        $fromPrice = $fromPhones[0]->getCurrentYearlyPhonePrice()->getMonthlyPremiumPrice();

        $referral = $request->get('referral');
        $session = $this->get('session');

        // For Referrals
        if ($referral) {
            $session->set('referral', $referral);
            $this->get('logger')->debug(sprintf('Referral %s', $referral));
        }

        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');

        $template = 'AppBundle:Default:indexQuickQuote.html.twig';

        // A/B Email Optional
        $homepageEmailOptionalExp = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_EMAIL_OPTIONAL,
            ['email-optional', 'email'],
            SixpackService::LOG_MIXPANEL_ALL
        );

        if ($homepageEmailOptionalExp == 'email') {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
                'page' => 'homepage-email'
            ]);
        } else {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
                'page' => 'homepage-email-optional'
            ]);
        }

        $data = array(
            'referral'  => $referral,
            'phone'     => $this->getQuerystringPhone($request),
            'competitor' => $this->competitorsData(),
            'from_price' => $fromPrice
        );

        return $this->render($template, $data);
    }

    /**
     * @Route("/free-taste-card", name="free_taste_card")
     */
    public function freeTasteCard()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'tastecard']);

        $pageType = 'tastecard';

        $data = array(
            'page_type' => $pageType,
        );


        $template = 'AppBundle:Default:indexPromotions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/free-phone-case", name="free_phone_case")
     * @Route("/case", name="case")
     */
    public function freePhoneCase()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'freephonecase']);

        $pageType = 'phonecase';

        $data = array(
            'page_type' => $pageType,
        );

        $template = 'AppBundle:Default:indexPromotions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/marlow", name="marlow")
     */
    public function marlowAction()
    {
        return $this->redirectToRoute('promo', ['code' => 'MARLOW15']);
    }

    /**
     * @Route("/valentines-day-free-phone-case", name="valentines_day_free_phone_case")
     */
    public function valentinesDayCase()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'valentinesdayfreephonecase'
        ]);

        $pageType = 'vdayphonecase';

        $data = array(
            'page_type' => $pageType,
        );

        $template = 'AppBundle:Default:indexPromotions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/social-insurance", name="social_insurance", options={"sitemap" = true})
     */
    public function socialInsurance()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'social-insurance']);
        return $this->render('AppBundle:Default:socialInsurance.html.twig');
    }

    /**
     * @Route("/snapchat", name="snapchat")
     */
    public function snapchatLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'snapchat'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexSnapchat.html.twig', $data);
    }

    /**
     * @Route("/snapchat-b", name="snapchat-b")
     */
    public function snapchatbLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'snapchat-b'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexSnapchatB.html.twig', $data);
    }

    /**
     * @Route("/twitter", name="twitter")
     */
    public function twitterLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'twitter'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexTwitter.html.twig', $data);
    }

    /**
     * @Route("/facebook", name="facebook")
     */
    public function facebookLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'facebook'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexFacebook.html.twig', $data);
    }

    /**
     * @Route("/youtube", name="youtube")
     */
    public function youtubeLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'youtube'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexYoutube.html.twig', $data);
    }

    /**
     * @Route("/terms-test", name="terms_test")
     */
    public function termsTest()
    {
        return $this->render('AppBundle:Pdf:policyTermsV14.html.twig');
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

    /**
     * @Route("/topcashback", name="topcashback")
     * @Route("/vouchercodes", name="vouchercodes")
     * @Route("/quidco", name="quidco")
     * @Route("/ivip", name="ivip")
     * @Route("/reward-gateway", name="reward_gateway")
     * @Route("/money", name="money")
     * @Route("/money-free-phone-case", name="money_free_phone_case")
     * @Route("/starling-bank", name="starling_bank")
     * @Route("/starling-business", name="starling_business")
     * @Route("/comparison", name="comparison")
     * @Route("/vendi-app", name="vendi_app")
     * @Route("/so-sure-compared", name="so_sure_compared")
     * @Route("/moneyback", name="moneyback")
     */
    public function affiliateLanding(Request $request)
    {
        $data = [
            'competitor' => $this->competitorsData(),
        ];

        $template = 'AppBundle:Default:indexAffiliate.html.twig';

        if ($request->get('_route') == 'topcashback') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'topcashback',
                'affiliate_company' => 'TopCashback',
                'affiliate_company_logo' => 'so-sure_topcashback_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'SS',
            ];
        } elseif ($request->get('_route') == 'vouchercodes') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'vouchercodes',
                'affiliate_company' => 'VoucherCodes',
                'affiliate_company_logo' => 'so-sure_vouchercodes_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'END',
            ];
        } elseif ($request->get('_route') == 'quidco') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'quidco',
                'affiliate_company' => 'Quidco',
                'affiliate_company_logo' => 'so-sure_quidco_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'CC',
            ];
        } elseif ($request->get('_route') == 'ivip') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'ivip',
                'affiliate_company' => 'iVIP',
                'affiliate_company_logo' => 'so-sure_ivip_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
        } elseif ($request->get('_route') == 'reward_gateway') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'reward-gateway',
                'affiliate_company' => 'Reward Gateway',
                'competitor1' => 'PYB',
                'competitor2' => 'END',
                'competitor3' => 'SS',
            ];
        } elseif ($request->get('_route') == 'money') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'money',
                'affiliate_company' => 'money',
                'affiliate_company_logo' => 'so-sure_money_logo.png',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
        } elseif ($request->get('_route') == 'money_free_phone_case') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'money-free-phone-case',
                'affiliate_company' => 'money',
                'affiliate_company_logo' => 'so-sure_money_logo.png',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
        } elseif ($request->get('_route') == 'starling_bank') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'starling-bank',
                // 'affiliate_company' => 'Starling Bank',
                // 'affiliate_company_logo' => 'so-sure_money_logo.png',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
            $template = 'AppBundle:Default:indexStarlingBank.html.twig';
            $this->starlingOAuthSession($request);
        } elseif ($request->get('_route') == 'starling_business') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'starling-business',
                // 'affiliate_company' => 'Starling Bank',
                // 'affiliate_company_logo' => 'so-sure_money_logo.png',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
            $template = 'AppBundle:Default:starlingBusiness.html.twig';
            $this->starlingOAuthSession($request);
        } elseif ($request->get('_route') == 'comparison') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'comparison',
                'titleH1' => 'Mobile Insurance beyond compare',
                'leadP' => 'But if you do want to compare... <br> here\'s how we stack up against the competition 🤔',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
        } elseif ($request->get('_route') == 'vendi_app') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'vendi-app',
                'affiliate_company' => 'Vendi',
                'affiliate_company_logo' => 'so-sure_vendi_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
        } elseif ($request->get('_route') == 'so_sure_compared') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'so-sure-compared',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
        } elseif ($request->get('_route') == 'moneyback') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'moneyback',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'O2',
            ];
        }

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => $data['affiliate_page']]);

        return $this->render($template, $data);
    }

    /**
     * @Route("/select-phone-dropdown", name="select_phone_make_dropdown")
     * @Route("/select-phone-dropdown/{type}/{id}", name="select_phone_make_dropdown_type_id")
     * @Route("/select-phone-dropdown/{type}", name="select_phone_make_dropdown_type")
     * @Template()
     */
    public function selectPhoneMakeDropdownAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $phoneMake = new PhoneMake();
        if ($id) {
            $phone = $phoneRepo->find($id);
            if (!$phone) {
                throw $this->createNotFoundException('Invalid id');
            }

            $phoneMake->setMake($phone->getMake());
        }

        // throw new \Exception($id);

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_phone');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('select_phone_make_dropdown'),
            ])
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'memory');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw $this->createNotFoundException('Invalid id');
                    }
                    if ($phone->getMemory()) {
                        return $this->redirectToRoute('phone_insurance_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory(),
                        ]);
                    } else {
                        return $this->redirectToRoute('phone_insurance_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                        ]);
                    }
                }
            }
        }

        // throw new \Exception(print_r($this->getPhonesArray(), true));

        return [
            'form_phone' => $formPhone->createView(),
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/phone-dropdown", name="phone_make_dropdown")
     * @Route("/phone-dropdown/{type}/{id}", name="phone_make_dropdown_type_id")
     * @Route("/phone-dropdown/{type}", name="phone_make_dropdown_type")
     * @Template()
     */
    public function phoneMakeDropdownNewAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $phoneMake = new PhoneDropdown();
        if ($id) {
            $phone = $phoneRepo->find($id);
            $phoneMake->setMake($phone->getMake());
        }

        // throw new \Exception($id);

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_phone');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneDropdownType::class, $phoneMake, [
                'action' => $this->generateUrl('phone_make_dropdown'),
            ])
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'memory');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw new \Exception('unknown phone');
                    }
                    if ($phone->getMemory()) {
                        return $this->redirectToRoute('phone_insurance_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory(),
                        ]);
                    } else {
                        return $this->redirectToRoute('phone_insurance_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                        ]);
                    }
                }
            }
        }

        // throw new \Exception(print_r($this->getPhonesArray(), true));

        return [
            'form_phone' => $formPhone->createView(),
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/select-phone-search", name="select_phone_make_search")
     * @Route("/select-phone-search/{type}", name="select_phone_make_search_type")
     * @Route("/select-phone-search/{type}/{id}", name="select_phone_make_search_type_id")
     * @Template()
     */
    public function selectPhoneMakeSearchAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        if ($id) {
            $phone = $phoneRepo->find($id);
        }

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_phone', [], 301);
        }

        return [
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/search-phone", name="search_phone_data")
     * @Route("/search-phone-combined", name="search_phone_combined_data")
     */
    public function searchPhoneAction(Request $request)
    {
        $type = 'simple';
        if ($request->get('_route') == 'search_phone_combined_data') {
            $type = 'highlight';
        }

        return new JsonResponse(
            $this->getPhonesSearchArray($type)
        );
    }

    /**
     * @Route("/login-redirect", name="login_redirect")
     */
    public function loginRedirectAction()
    {
        if ($this->getUser()) {
            if ($this->isGranted(User::ROLE_EMPLOYEE)) {
                return $this->redirectToRoute('admin_home');
            } elseif ($this->isGranted('ROLE_CLAIMS')) {
                return $this->redirectToRoute('claims_policies');
            } elseif ($this->isGranted('ROLE_USER')) {
                return $this->redirectToRoute('user_home');
            }
        }

        return $this->redirectToRoute('homepage');
    }

    /**
     * @Route("/usa", name="launch_usa")
     * @Template
     */
    public function launchUSAAction()
    {
        return [];
    }

    /**
     * @Route("/help", name="help")
     * @Route("/help/{section}", name="help_section", requirements={"section"="[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/help/{section}/{article}", name="help_section_article",
     * requirements={"section"="[\+\-\.a-zA-Z0-9() ]+", "article"="[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/help/{section}/{article}/{sub}", name="help_section_article_sub",
     * requirements={"section"="[\+\-\.a-zA-Z0-9() ]+", "article"="[\+\-\.a-zA-Z0-9() ]+",
     * "sub"="[\+\-\.a-zA-Z0-9() ]+"})
     * @Template
     */
    public function helpAction()
    {
        return $this->redirectToRoute('faq', [], 301);
    }

    /**
     * @Route("/faq", name="faq", options={"sitemap" = true})
     * @Template
     */
    public function faqAction(Request $request)
    {
        $intercomEnabled = true;
        $hideCookieWarning = false;
        $hideNav = false;
        $hideFooter = false;

        $isSoSureApp = false;
        $session = $request->getSession();
        if ($session) {
            if ($session->get('sosure-app') == "1") {
                $isSoSureApp = true;
            }
            if ($request->headers->get('X-SOSURE-APP') == "1" || $request->get('X-SOSURE-APP') == "1") {
                $session->set('sosure-app', 1);
                $isSoSureApp = true;
            }
        }

        if ($isSoSureApp) {
            $intercomEnabled = false;
            $hideCookieWarning = true;
            $hideNav = true;
            $hideFooter = true;
        }

        $data = [
            'intercom_enabled' => $intercomEnabled,
            'hide_cookie_warning' => $hideCookieWarning,
            'hide_nav' => $hideNav,
            'hide_footer' => $hideFooter,
        ];
        return $this->render('AppBundle:Default:faq.html.twig', $data);
    }

    /**
     * @Route("/company-phone-insurance",
     *  name="company_phone_insurance", options={"sitemap" = true})
     * @Route("/company-phone-insurance/thank-you",
     *  name="company_phone_insurance_thanks")
     */
    public function companyAction(Request $request)
    {
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', CompanyLeadType::class)
            ->getForm();

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_COMPANY_PHONES);

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                $leadForm->handleRequest($request);
                if ($leadForm->isValid()) {
                    // @codingStandardsIgnoreStart
                    $body = sprintf(
                        "Name: %s\nCompany: %s\nEmail: %s\nContact #: %s\n# Phones: %s\nPurchasing Timeframe: %s\nMessage: %s",
                        $leadForm->getData()['name'],
                        $leadForm->getData()['company'],
                        $leadForm->getData()['email'],
                        $leadForm->getData()['phone'],
                        $leadForm->getData()['phones'],
                        $leadForm->getData()['timeframe'],
                        $leadForm->getData()['message']
                    );
                    // @codingStandardsIgnoreEnd

                    /** @var MailerService $mailer */
                    $mailer = $this->get('app.mailer');
                    $mailer->send(
                        'Company inquiry',
                        'sales@so-sure.com',
                        $body
                    );

                    $this->addFlash(
                        'success',
                        "Thanks. We'll be in touch shortly"
                    );

                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_COMPANY_LEAD_CAPTURE);

                    return $this->redirectToRoute('company_phone_insurance_thanks');
                } else {
                    $this->addFlash(
                        'error',
                        "Sorry, there was a problem validating your request. Please check below for any errors."
                    );
                }
            }
        }

        $data = [
            'lead_form' => $leadForm->createView(),
        ];

        return $this->render('AppBundle:Default:indexCompany.html.twig', $data);
    }

    /**
     * @Route("/claim", name="claim", options={"sitemap" = true})
     * @Route("/claim/login", name="claim_login")
     */
    public function claimAction(Request $request)
    {
        /** @var User $user */
        $user = $this->getUser();

        // causes admin's (or claims) too much confusion to be redirected to a 404
        if ($user && !$user->hasEmployeeRole() && !$user->hasClaimsRole()
            && ($user->hasActivePolicy() || $user->hasUnpaidPolicy())) {
            return $this->redirectToRoute('user_claim');
        }

        $claimFnolEmail = new ClaimFnolEmail();

        $claimEmailForm = $this->get('form.factory')
            ->createNamedBuilder('claim_email_form', ClaimFnolEmailType::class, $claimFnolEmail)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claim_email_form')) {
                $claimEmailForm->handleRequest($request);
                if ($claimEmailForm->isValid()) {
                    $repo = $this->getManager()->getRepository(User::class);
                    $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($claimFnolEmail->getEmail())]);

                    if ($user) {
                        /** @var ClaimsService $claimsService */
                        $claimsService = $this->get('app.claims');
                        $claimsService->sendUniqueLoginLink($user, $request->get('_route') == 'claim_login');
                    }

                    // @codingStandardsIgnoreStart
                    $message = $request->get('_route') == 'claim_login' ? "Thank you. For our policy holders, an email with further instructions on how to proceed with updating your claim has been sent to you. If you do not receive the email shortly, please check your spam folders and also verify that the email address matches your policy." : "Thank you. For our policy holders, an email with further instructions on how to proceed with your claim has been sent to you. If you do not receive the email shortly, please check your spam folders and also verify that the email address matches your policy.";

                    $this->addFlash('success', $message);
                }
            }
        }

        $data = [
            'claim_email_form' => $claimEmailForm->createView(),
        ];

        if ($request->get('_route') == 'claim_login') {
            return $this->redirectToRoute('claim', [], 301);
        }
        return $this->render('AppBundle:Default:claim.html.twig', $data);
    }

    /**
     * @Route("/claim/login/{tokenId}", name="claim_login_token")
     * @Template
     */
    public function claimLoginAction(Request $request, $tokenId = null)
    {
        $user = $this->getUser();

        if ($user) {
            return $this->redirectToRoute('user_claim');
        }

        if ($tokenId) {
            /** @var ClaimsService $claimsService */
            $claimsService = $this->get('app.claims');
            $userId = $claimsService->getUserIdFromLoginLinkToken($tokenId);
            if (!$userId) {
                // @codingStandardsIgnoreStart
                $this->addFlash(
                    'error',
                    "Sorry, it looks like your link as expired. Please re-enter the email address you have created your policy under and try again."
                );
                return $this->redirectToRoute('claim');
            }

            $dm = $this->getManager();
            $userRepo = $dm->getRepository(User::class);
            $user = $userRepo->find($userId);

            if ($user) {
                if ($user->isLocked() || !$user->isEnabled()) {
                    // @codingStandardsIgnoreStart
                    $this->addFlash(
                        'error',
                        "Sorry, it looks like your user account is locked or expired. Please email support@wearesosure.com"
                    );

                    return $this->redirectToRoute('claim');
                }

                $this->get('fos_user.security.login_manager')->loginUser(
                    $this->getParameter('fos_user.firewall_name'),
                    $user
                );

                return $this->redirectToRoute('user_claim');
            }
        }

        throw $this->createNotFoundException('Invalid link');
    }

    /**
     * @Route("/alpha", name="alpha")
     * @Template
     */
    public function alphaAction()
    {
        return array();
    }

    /**
     * @Route("/price/{id}", name="price_item")
     */
    public function priceItemAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if (!$phone) {
            return new JsonResponse([], 404);
        }

        return new JsonResponse([
            'price' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY),
        ]);
    }

    /**
     * @Route("/phone/{make}/{model}", name="phone_make_model")
     * @Template
     */
    public function phoneMakeModelAction($make, $model)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->findOneBy(['make' => $make, 'model' => $model]);
        if (!$phone) {
            return new RedirectResponse($this->generateUrl('phone_make', ['make' => $make]));
        }

        return array('phone' => $phone);
    }

    /**
     * @Route("/phone/{make}", name="phone_make")
     * @Template
     */
    public function phoneMakeAction($make)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(['make' => $make]);
        // TODO: Redirect to other phone

        return array('phones' => $phones);
    }

    /**
     * @Route("/apple-app-site-association", name="apple-app-site-assocaition")
     */
    public function appleAppAction()
    {
        $view = $this->renderView('AppBundle:Default:apple-app-site-association.json.twig');

        return new Response($view, 200, array('Content-Type'=>'application/json'));
    }

    /**
     * @Route("/optout", name="optout_old")
     * @Route("/communications", name="optout")
     * @Template()
     */
    public function optOutAction(Request $request)
    {
        if ($this->getUser()) {
            $hash = SoSure::encodeCommunicationsHash($this->getUser()->getEmail());

            return new RedirectResponse($this->generateUrl('optout_hash', ['hash' => $hash]));
        }

        $form = $this->createFormBuilder()
            ->add('email', EmailType::class, [
                'data' => $request->get('email')
            ])
            ->add('decline', SubmitType::class)
            ->getForm();

        $email = null;
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByIp(
                RateLimitService::DEVICE_TYPE_OPT,
                $request->getClientIp()
            )) {
                $this->addFlash(
                    'error',
                    'Too many requests! Please try again later'
                );

                return new RedirectResponse($this->generateUrl('homepage'));
            }

            $email = $form->getData()['email'];
            $hash = SoSure::encodeCommunicationsHash($email);

            /** @var MailerService $mailer */
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'Update your communication preferences',
                $email,
                'AppBundle:Email:user/optOutLink.html.twig',
                ['hash' => $hash],
                'AppBundle:Email:user/optOutLink.txt.twig',
                ['hash' => $hash]
            );

            $this->addFlash(
                'success',
                'Thanks! You should receive an email shortly.'
            );

            return new RedirectResponse($this->generateUrl('optout'));
        }

        return array(
            'form_optout' => $form->createView(),
        );
    }

    /**
     * @Route("/optout/{hash}", name="optout_hash_old")
     * @Route("/communications/{hash}", name="optout_hash")
     * @Template()
     */
    public function optOutHashAction(Request $request, $hash)
    {
        if (!$hash) {
            return new RedirectResponse($this->generateUrl('optout'));
        }
        $rateLimit = $this->get('app.ratelimit');
        if (!$rateLimit->allowedByIp(RateLimitService::DEVICE_TYPE_OPT, $request->getClientIp())) {
            $this->addFlash('error', 'Too many requests! Please try again later');
            return new RedirectResponse($this->generateUrl('homepage'));
        }
        $email = SoSure::decodeCommunicationsHash($hash);
        /** @var InvitationService $invitationService */
        $invitationService = $this->get('app.invitation');
        /** @var EmailOptOutRepository $optOutRepo */
        $optOutRepo = $this->getManager()->getRepository(EmailOptOut::class);
        /** @var EmailOptOut $optOut */
        $optOut = $optOutRepo->findOneBy(['email' => mb_strtolower($email)]);
        if (!$optOut) {
            $optOut = new EmailOptOut();
            $optOut->setEmail($email);
        }
        $marketingOptOutForm = $this->get('form.factory')
            ->createNamedBuilder(
                'marketing_optout_form',
                MarketingEmailOptOutType::class,
                null,
                ['checked' => $optOut->hasCategory(Opt::OPTOUT_CAT_MARKETING)]
            )
            ->getForm();
        $optOutForm = $this->get('form.factory')
            ->createNamedBuilder('optout_form', EmailOptOutType::class, $optOut)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('marketing_optout_form')) {
                $marketingOptOutForm->handleRequest($request);
                $categories = $marketingOptOutForm->getData()['categories'];
                if (in_array(Opt::OPTOUT_CAT_MARKETING, $categories)) {
                    $optOut->addCategory(Opt::OPTOUT_CAT_MARKETING);
                } else {
                    $optOut->removeCategory(Opt::OPTOUT_CAT_MARKETING);
                }
            } elseif ($request->request->has('optout_form')) {
                $optOutForm->handleRequest($request);
            } else {
                throw new \Exception("no form submitted");
            }
            $optOut->setLocation(Opt::OPT_LOCATION_PREFERENCES);
            $optOut->setIdentityLog($this->getIdentityLogWeb($request));
            if (mb_strtolower($email) != $optOut->getEmail()) {
                throw new \Exception(sprintf(
                    'Optout hacking attempt %s != %s',
                    $email,
                    $optOut->getEmail()
                ));
            }
            if (in_array(EmailOptOut::OPTOUT_CAT_INVITATIONS, $optOut->getCategories())) {
                $invitationService->rejectAllInvitations($email);
            }
            $dm = $this->getManager();
            $dm->persist($optOut);
            $dm->flush();
            $this->addFlash('success', 'Your preferences have been updated');
            return new RedirectResponse($this->generateUrl('optout_hash', ['hash' => $hash]));
        }
        return array(
            'email' => $email,
            'marketing_optout_form' => $marketingOptOutForm->createView(),
            'optout_form' => $optOutForm->createView(),
        );
    }

    /**
     * @Route("/mobile-otp", name="mobile_otp_web")
     */
    public function mobileOtp(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->validateFields(
            $data,
            ['mobileNumber', 'csrf']
        )) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        if (!$this->isCsrfTokenValid('mobile', $data['csrf'])) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid csrf', 422);
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $validator = new UkMobileValidator();
        $mobileNumber = $this->normalizeUkMobile($data['mobileNumber']);
        $user = $repo->findOneBy(["mobileNumber" => $mobileNumber]);

        if (!$user) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'User not found', 404);
        }

        if (!$user->isEnabled()) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_USER_RESET_PASSWORD,
                'User account is temporarily disabled - reset password',
                422
            );
        } elseif ($user->isLocked()) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_USER_SUSPENDED,
                'User account is suspended - contact us',
                422
            );
        }

        $sms = $this->get('app.sms');
        $code = $sms->setValidationCodeForUser($user);
        $status = $sms->sendTemplate(
            $mobileNumber,
            'AppBundle:Sms:login-code.txt.twig',
            ['code' => $code],
            $user->getLatestPolicy(),
            Charge::TYPE_SMS_VERIFICATION
        );

        if ($status) {
            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
        } else {
            $this->get('logger')->error('Error sending SMS.', ['mobile' => $mobileNumber]);
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_SEND_SMS, 'Error sending SMS', 422);
        }
    }

    /**
     * @Route("/mobile-login", name="mobile_login_web")
     */
    public function mobileLoginAction(Request $request)
    {
        $data = $request->request->all();
        if (!$this->validateFields(
            $data,
            ['mobileNumber', 'code', 'csrf']
        )) {
            throw new \InvalidArgumentException('Missing Parameters');
        }

        if (!$this->isCsrfTokenValid('mobile', $data['csrf'])) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $validator = new UkMobileValidator();
        $mobileNumber = $this->normalizeUkMobile($data['mobileNumber']);

        $user = $repo->findOneBy(['mobileNumber' => $mobileNumber]);
        if (!$user) {
            $this->addFlash(
                'error',
                "Sorry, we can't seem to find your user account. Please contact us if you need help."
            );

            return new RedirectResponse($this->generateUrl('fos_user_security_login'));
        }

        $code = $data['code'];
        $sms = $this->get('app.sms');
        if ($sms->checkValidationCodeForUser($user, $code)) {
            $user->setMobileNumberVerified(true);
            $dm->flush();
        } else {
            $this->addFlash(
                'error',
                "Sorry, your code is invalid or has expired, please try again or use the email login"
            );
            return new RedirectResponse($this->generateUrl('fos_user_security_login'));
        }

        $this->get('fos_user.security.login_manager')->loginUser(
            $this->getParameter('fos_user.firewall_name'),
            $user
        );

        return new RedirectResponse($this->generateUrl('user_home'));
    }

    /**
     * @Route("/iphone8", name="iphone8_redirect")
     */
    public function iPhone8RedirectAction()
    {
        return new RedirectResponse($this->generateUrl('phone_insurance_make_model', [
            'make' => 'apple',
            'model' => 'iphone+8',
            'utm_medium' => 'flyer',
            'utm_source' => 'sosure',
            'utm_campaign' => 'iPhone8',
        ]));
    }

    /**
     * @Route("/trinitiymaxwell", name="trinitiymaxwell_redirect")
     */
    public function tmAction()
    {
        return new RedirectResponse($this->generateUrl('homepage', [
            'utm_medium' => 'flyer',
            'utm_source' => 'sosure',
            'utm_campaign' => 'trinitiymaxwell',
        ]));
    }

    /**
     * @Route("/sitemap", name="sitemap")
     * @Template()
     */
    public function sitemapAction()
    {
        $dpn = $this->get('dpn_xml_sitemap.manager');
        $entities = $dpn->getSitemapEntries();
        uasort($entities, function ($a, $b) {
            $dirA = pathinfo($a->getUrl())['dirname'];
            $dirB = pathinfo($b->getUrl())['dirname'];
            if ($dirA != $dirB) {
                return $dirA > $dirB;
            }

            return $a->getUrl() > $b->getUrl();
        });
        return [
            'entities' => $entities,
        ];
    }
}
