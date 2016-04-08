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
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Sns;
use AppBundle\Document\User;
use AppBundle\Document\Invitation\Invitation;

use AppBundle\Classes\ApiErrorCode;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @Route("/api/v1/auth")
 */
class ApiAuthController extends BaseController
{
    /**
     * @Route("/ping", name="api_auth_ping")
     * @Method({"GET", "POST"})
     */
    public function pingAuthAction()
    {
        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }

    /**
     * @Route("/policy", name="api_auth_new_policy")
     * @Method({"POST"})
     */
    public function newPolicyAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['imei', 'make', 'device', 'memory', 'validation_data'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $imeiValidator = $this->get('app.imei');
            $jwtValidator = $this->get('app.jwt');
            $user = $this->getUser();
            $this->denyAccessUnlessGranted('edit', $user);

            if (!$user->hasValidDetails()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS,
                    'User must have firstname/lastname, email & mobile number present before policy can be created',
                    422
                );
            }

            $imei = str_replace(' ', '', $data['imei']);
            if (!$imeiValidator->isImei($imei)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_INVALID,
                    'Imei fails validation checks',
                    422
                );
            }

            if (!$imeiValidator->checkImei($imei)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED,
                    'Imei is blacklisted',
                    422
                );
            }

            $phone = $this->getPhone($data['make'], $data['device'], $data['memory']);
            if (!$phone) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to provide policy for this phone',
                    404
                );
            }

            // TODO: check we're not already insuring the same imei (only for policy state active,pending)

            try {
                $jwtValidator->validate(
                    $this->getCognitoIdentityId($request),
                    (string) $data['validation_data'],
                    ['imei' => $imei]
                );
            } catch (\InvalidArgumentException $argE) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION,
                    'Validation data is not valid',
                    422
                );
            }

            $policy = new PhonePolicy();
            $policy->setImei($imei);
            $policy->setPhone($phone);
            $policy->setPhoneData(json_encode([
                'make' => $data['make'],
                'device' => $data['device'],
                'memory' => $data['memory'],
            ]));
            $user->addPolicy($policy);

            $dm = $this->getManager();
            $dm->persist($policy);
            $dm->flush();

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api newPolicy. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}", name="api_auth_get_policy")
     * @Method({"POST"})
     */
    public function getPolicyAction($id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            $this->denyAccessUnlessGranted('view', $policy);

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getPolicy. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/dd", name="api_auth_new_policy_dd")
     * @Method({"POST"})
     */
    public function newPolicyDdAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['sort_code', 'account_number', 'account_name'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $accountNames = explode(" ", $data['account_name']);
            if (count($accountNames) != 2) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_POLICY_INVALID_ACCOUNT_NAME,
                    'Account name is firstname lastname',
                    422
                );
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            $this->denyAccessUnlessGranted('edit', $policy);

            $gocardless = $this->get('app.gocardless');
            $gocardless->add($policy, $accountNames[0], $accountNames[1], $data['sort_code'], $data['account_number']);

            return new JsonResponse($policy->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api newPolicyDD. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/{id}/invitation", name="api_auth_new_invitation")
     * @Method({"POST"})
     */
    public function newInvitationAction(Request $request, $id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find policy',
                    404
                );
            }
            $this->denyAccessUnlessGranted('send-invitation', $policy);

            $invitationService = $this->get('app.invitation');
            // avoid sending email/sms invitations if testing
            if ($request->get('debug')) {
                $invitationService->setDebug(true);
            }

            $data = json_decode($request->getContent(), true)['body'];
            $email = isset($data['email']) ? $data['email'] : null;
            $mobile = isset($data['mobile']) ? $data['mobile'] : null;
            $name = isset($data['name']) ? $data['name'] : null;
            try {
                $invitation  = null;
                if ($email) {
                    $invitation = $invitationService->email($policy, $email, $name);
                } elseif ($mobile) {
                    $invitation = $invitationService->sms($policy, $mobile, $name);
                } else {
                    // TODO: General
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_NOT_FOUND,
                        'Missing data found',
                        422
                    );
                }

                if (!$invitation) {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_INVITATION_OPTOUT,
                        'Person has opted out of invitations',
                        422
                    );
                }

                return new JsonResponse($invitation->toApiArray());
            } catch (\InvalidArgumentException $argEx) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_INVITATION_DUPLICATE,
                    'Duplicate invitation',
                    422
                );
            }
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            print_r(get_class($e));
            $this->get('logger')->error(sprintf('Error in api newInvitation. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/secret", name="api_auth_secret")
     * @Method({"GET", "POST"})
     */
    public function secretAuthAction()
    {
        // TODO: This should be stored in redis against the sourceIp and time limited
        return new JsonResponse(['secret' => $this->getParameter('api_secret')]);
    }

    /**
     * @Route("/user/{id}", name="api_auth_get_user")
     * @Method({"POST"})
     */
    public function getUserAction($id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            $this->denyAccessUnlessGranted('view', $user);

            return new JsonResponse($user->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getUser. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}", name="api_auth_update_user")
     * @Method({"PUT"})
     */
    public function updateUserAction(Request $request, $id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'User not found', 404);
            }

            $this->denyAccessUnlessGranted('edit', $user);

            $data = json_decode($request->getContent(), true)['body'];
            $userChanged = false;

            // TODO: Validate phone number
            if (isset($data['mobile_number']) && strlen($data['mobile_number']) > 0) {
                $user->setMobileNumber($data['mobile_number']);
                $userChanged = true;
            }

            // TODO: Validate email
            if (isset($data['email']) && strlen($data['email']) > 0) {
                // TODO: Send email to both old & new email addresses
                $user->setEmail($data['email']);
                $userChanged = true;
            }
            if (isset($data['first_name']) && strlen($data['first_name']) > 0) {
                $user->setFirstName($data['first_name']);
                $userChanged = true;
            }
            if (isset($data['last_name']) && strlen($data['last_name']) > 0) {
                $user->setLastName($data['last_name']);
                $userChanged = true;
            }

            if ($userChanged) {
                $dm->flush();
            }

            return new JsonResponse($user->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api updateUser. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}/address", name="api_auth_add_user_address")
     * @Method({"POST"})
     */
    public function addUserAddressAction(Request $request, $id)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['type', 'line1', 'city', 'postcode'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);

            $this->denyAccessUnlessGranted('view', $user);

            $address = new Address();
            $address->setType($data['type']);
            $address->setLine1($data['line1']);
            $address->setLine2(isset($data['line2']) ? $data['line2'] : null);
            $address->setLine3(isset($data['line3']) ? $data['line3'] : null);
            $address->setCity($data['city']);
            $address->setPostcode($data['postcode']);
            $user->addAddress($address);

            $dm->persist($address);
            $dm->flush();

            return new JsonResponse($user->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api addUserAddress. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
