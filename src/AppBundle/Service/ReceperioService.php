<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use AppBundle\Document\Phone;

class ReceperioService extends BaseImeiService
{
    const PARTNER_ID = 415;
    const BASE_URL = "http://gapi.checkmend.com";

    /** @var string */
    protected $secretKey;

    /** @var string */
    protected $storeId;

    /**
     * @param string $secretKey
     */
    public function setSecretKey($secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * @param string $storeId
     */
    public function setStoreId($storeId)
    {
        $this->storeId = $storeId;
    }

    /**
     * Checks imei against a blacklist
     *
     * @param Phone  $phone
     * @param string $imei
     *
     * @return boolean True if imei is ok
     */
    public function checkImei(Phone $phone, $imei)
    {
        // TODO: Cache

        \AppBundle\Classes\NoOp::noOp([$phone]);
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == "352000067704506") {
            return false;
        }

        try {
            $response = $this->send("/claimscheck/search", [
                'serial' => $imei,
                'storeid' => $this->storeId,
            ]);
            $this->logger->info(sprintf("Claimscheck search for %s -> %s", $imei, print_r($response, true)));
            $data = json_decode($response, true );

            // for now, always ok the imei until we purchase db
            return $data['checkmendstatus']['result'] == 'green';
        } catch (\Exception $e) {
            // TODO: automate a future retry check
            $this->logger->error(sprintf("Unable to check imei %s Ex: %s", $imei, $e->getMessage()));

            // For now, if there are any issues, assume true and run a manual retry later
            return true;
        }
    }

    /**
     * Validate that the serial number matches the expected phone details
     *
     * @param Phone  $phone
     * @param string $serialNumber
     *
     * @return boolean True if imei is ok
     */
    public function checkSerial(Phone $phone, $serialNumber)
    {
        // TODO: Cache

        \AppBundle\Classes\NoOp::noOp([$phone]);
        if ($serialNumber == "111111") {
            return false;
        }

        try {
            $response = $this->send("/makemodelext", [
                'serials' => [$serialNumber],
                'storeid' => $this->storeId,
            ]);
            $this->logger->info(sprintf(
                "Claimscheck serial verification for %s -> %s",
                $serialNumber,
                print_r($response, true)
            ));
            $data = json_decode($response, true);

            if (count($data['makes']) != 1) {
                $this->logger->error(sprintf(
                    "Unable to check serial number %s. Data: %s",
                    $serialNumber,
                    print_r($data, true)
                ));

                return true;
            }
            $make = $data['makes'][0];
            if (count($make['models']) != 1) {
                $this->logger->error(sprintf(
                    "Unable to check serial number %s. Data: %s",
                    $serialNumber,
                    print_r($data, true)
                ));

                return true;
            }
            $model = $make['models'][0];
            if (!$model['storage']) {
                $this->logger->error(sprintf(
                    "Unable to check serial number %s. Data: %s",
                    $serialNumber,
                    print_r($data, true)
                ));

                return true;
            }

            return $model['storage'] == sprintf('%sGB', $phone->getMemory());
        } catch (\Exception $e) {
            // TODO: automate a future retry check
            $this->logger->error(sprintf("Unable to check serial number %s Ex: %s", $serialNumber, $e->getMessage()));

            // For now, if there are any issues, assume true and run a manual retry later
            return true;
        }
    }

    protected function send($url, $data)
    {
        try {
            $body = json_encode($data);
            $client = new Client();
            $url = sprintf("%s%s", self::BASE_URL, $url);
            $res = $client->request('POST', $url, [
                'json' => $data,
                'auth' => [self::PARTNER_ID, $this->sign($body)],
                'headers' => ['Accept' => 'application/json'],
            ]);
            $body = (string) $res->getBody();
            //print_r($body);

            return $body;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in checkImei: %s', $e->getMessage()));
        }
    }
    
    protected function sign($body)
    {
        return sha1(sprintf("%s%s", $this->secretKey, $body));
    }
}
