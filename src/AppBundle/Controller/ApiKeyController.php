<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\PhoneType;

use AppBundle\Document\Address;
use AppBundle\Document\Phone;
use AppBundle\Document\Sns;
use AppBundle\Document\User;
use AppBundle\Document\PolicyTerms;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/api/v1/key")
 */
class ApiKeyController extends BaseController
{
    /**
     * @Route("/quote", name="api_key_quote")
     * @Method({"GET"})
     */
    public function quoteAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['make'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $make = $this->getRequestString($request, 'make');
            $model = $this->getRequestString($request, 'model');

            $dm = $this->getManager();
            $repo = $dm->getRepository(Phone::class);
            if ($make && $model) {
                $query = ['make' => $make, 'model' => $model, 'active' => true];
            } elseif ($make) {
                $query = ['make' => $make, 'active' => true];
            }

            $phones = $repo->findBy($query);

            $quotes = [];
            foreach ($phones as $phone) {
                $currentPhonePrice = $phone->getCurrentPhonePrice();
                if (!$currentPhonePrice) {
                    continue;
                }

                // If there is an end date, then quote should be valid until then
                $quoteValidTo = $currentPhonePrice->getValidTo();
                if (!$quoteValidTo) {
                    $quoteValidTo = new \DateTime();
                    $quoteValidTo->add(new \DateInterval('P1D'));
                }

                $promoAddition = 0;
                $isPromoLaunch = false;

                $quotes[] = [
                    'monthly_premium' => $currentPhonePrice->getMonthlyPremiumPrice(),
                    'monthly_loss' => 0,
                    'yearly_premium' => $currentPhonePrice->getYearlyPremiumPrice(),
                    'yearly_loss' => 0,
                    'phone' => $phone->toApiArray(),
                    'connection_value' => $currentPhonePrice->getInitialConnectionValue($promoAddition),
                    'max_connections' => $currentPhonePrice->getMaxConnections($promoAddition, $isPromoLaunch),
                    'max_pot' => $currentPhonePrice->getMaxPot($isPromoLaunch),
                    'valid_to' => $quoteValidTo->format(\DateTime::ATOM),
                ];
            }

            $response = [
                'quotes' => $quotes,
                'device_found' => false,
            ];

            return new JsonResponse($response);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api quoteAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
