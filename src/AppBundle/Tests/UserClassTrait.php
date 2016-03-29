<?php

namespace AppBundle\Tests;

trait UserClassTrait
{
    public static function createUser($userManager, $email, $password)
    {
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setPlainPassword($password);
        $userManager->updateUser($user, true);

        return $user;
    }

    public static function authUser($cognito, $user)
    {
        list($identityId, $token) = $cognito->getCognitoIdToken($user, $cognito->getId());

        return $identityId;
    }

    public static function getIdentityString($cognitoIdentityId)
    {
        // @codingStandardsIgnoreStart
        $identity = sprintf('{cognitoIdentityPoolId=eu-west-1:e7a6cfd2-c60f-4a04-a7a0-79eec2150720, accountId=812402538357, cognitoIdentityId=%s, caller=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials, apiKey=null, sourceIp=62.253.24.189, cognitoAuthenticationType=unauthenticated, cognitoAuthenticationProvider=null, userArn=arn:aws:sts::812402538357:assumed-role/Cognito_sosureUnauth_Role/CognitoIdentityCredentials, userAgent=aws-sdk-iOS/2.3.5 iPhone-OS/9.2.1 en_GB, user=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials}"', $cognitoIdentityId);
        // @codingStandardsIgnoreEnd

        return $identity;
    }

    public static function postRequest($client, $cognitoIdentityId, $url, $body)
    {
        return $client->request(
            'POST',
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json',
            ),
            json_encode(array(
                'body' => $body,
                'identity' => static::getIdentityString($cognitoIdentityId)
            ))
        );
    }
}
