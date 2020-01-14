<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 */
class Feature
{
    // TODO: Remove - unused as too integrated
    const FEATURE_RENEWAL = 'renewal';
    const FEATURE_PICSURE = 'picsure';
    const FEATURE_SALVA_POLICY_UPDATE = 'salva-policy-update';

    // TODO: Verify if still used
    const FEATURE_STARLING = 'starling';

    // Active features
    const FEATURE_DAVIES_IMPORT_ERROR_EMAIL = 'davies-import-error-email';
    const FEATURE_BACS = 'bacs';
    const FEATURE_CARD_OPTION_WITH_BACS = 'card-option-with-bacs';
    const FEATURE_CARD_SWAP_FROM_BACS = 'card-swap-from-bacs';
    const FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR = 'app-ios-imei-validation-colour';
    const FEATURE_APP_PICSURE_ACCELEROMETER = 'app-picsure-accelerometer';
    const FEATURE_APP_PICSURE_DOTCODE = 'app-picsure-dotcode';
    const FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION = 'app-facebook-userfriends-permission';
    const FEATURE_APP_BACS_ENABLED = 'app-bacs-enabled';
    const FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP = 'claims-default-direct-group';
    const FEATURE_RATE_LIMITING = 'rate-limiting';
    const FEATURE_PAYMENTS_BCC = 'bcc-payments';
    const FEATURE_JUDO_RECURRING = 'judo-recurring';
    const FEATURE_USER_PAYMENT_HISTORY = 'user-payment-history';
    const FEATURE_CHECKOUT = 'checkout';
    const FEATURE_APPLY_SIGN_UP_BONUS = 'apply-sign-up-bonus';
    const FEATURE_INVITE_PAGES_COMPETITION = 'invite-competition';

    // All Features should be here
    public static $features = [
        self::FEATURE_RENEWAL,
        self::FEATURE_PICSURE,
        self::FEATURE_DAVIES_IMPORT_ERROR_EMAIL,
        self::FEATURE_STARLING,
        self::FEATURE_SALVA_POLICY_UPDATE,
        self::FEATURE_BACS,
        self::FEATURE_CARD_OPTION_WITH_BACS,
        self::FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR,
        self::FEATURE_APP_PICSURE_ACCELEROMETER,
        self::FEATURE_APP_PICSURE_DOTCODE,
        self::FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION,
        self::FEATURE_APP_BACS_ENABLED,
        self::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP,
        self::FEATURE_RATE_LIMITING,
        self::FEATURE_PAYMENTS_BCC,
        self::FEATURE_JUDO_RECURRING,
        self::FEATURE_CARD_SWAP_FROM_BACS,
        self::FEATURE_USER_PAYMENT_HISTORY,
        self::FEATURE_CHECKOUT,
        self::FEATURE_APPLY_SIGN_UP_BONUS,
        self::FEATURE_INVITE_PAGES_COMPETITION
    ];

    // @codingStandardsIgnoreStart
    public static $descriptions = [
        self::FEATURE_RENEWAL => 'Create renewal policies - too integrated to turn off',
        self::FEATURE_PICSURE => 'pic-sure funcationlaity - too integrated to turn off',
        self::FEATURE_DAVIES_IMPORT_ERROR_EMAIL => 'Notify davies of errors',
        self::FEATURE_STARLING => 'Starling integration - unused?',
        self::FEATURE_SALVA_POLICY_UPDATE => 'Use salva update api call instead of cancel/create',
        self::FEATURE_BACS => 'Bacs functionality - too integrated to turn off',
        self::FEATURE_CARD_OPTION_WITH_BACS => 'Allow users to also pay by card in web purchase flow',
        self::FEATURE_CARD_SWAP_FROM_BACS => 'Allow user to swap from bacs to card',
        self::FEATURE_APP_IOS_IMEI_VALIDATION_COLOUR => 'Check the colour of the top left pixel in the screenshot and if it is too different from the iOS header colour it will return an error as an anti-fraud measure.',
        self::FEATURE_APP_PICSURE_ACCELEROMETER => 'If enabled it will use the accelerometer data to check any sudden movements while taking the picsure and prevent doing picsure if any are detected as an anti-fraud measure.',
        self::FEATURE_APP_PICSURE_DOTCODE => 'Display dotcode on iOS for on the background image. Allows us to validate the imei in cases of suspected hacking.',
        self::FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION => 'Request user friends from Facebook. Requires permission from Facebook we lost in 2018 (but could re-request)',
        self::FEATURE_APP_BACS_ENABLED => 'Can the mobile apps pass payments through BACS?',
        self::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP => 'Should direct group be the default claim handler for new claims. If changing update phone system as well.',
        self::FEATURE_RATE_LIMITING => 'Use rate limiting functionality for various items including recipero imei checks and policy creation.',
        self::FEATURE_PAYMENTS_BCC => 'Bcc payment failure emails (and related) to bcc@so-sure.com',
        self::FEATURE_JUDO_RECURRING => 'Perform Judopay token payments with the \'recurring\' flag set.',
        self::FEATURE_USER_PAYMENT_HISTORY => 'Allow users to view their payment history',
        self::FEATURE_CHECKOUT => 'Use Checkout instead of Judo for payments',
        self::FEATURE_APPLY_SIGN_UP_BONUS => 'Automatically apply any active sign-up bonuses to new users first policy',
        self::FEATURE_INVITE_PAGES_COMPETITION => 'Invite pages competition (Amazon voucher)'
    ];
    // @codingStandardsIgnoreEnd

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $name;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="250")
     * @MongoDB\Field(type="string")
     */
    protected $description;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $enabled;

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
    }

    public function toApiArray()
    {
        return [
            'name' => $this->getName(),
            'enabled' => $this->isEnabled() ? true : false,
        ];
    }
}
