<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

class ReceperioService
{
    const PARTNER_ID = 415;
    const BASE_URL = "http://gapi.checkmend.com";

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $secretKey;

    /** @var string */
    protected $storeId;

    /**
     * @param LoggerInterface $logger
     * @param string          $secretKey
     * @param string          $storeId
     */
    public function __construct(LoggerInterface $logger, $secretKey, $storeId)
    {
        $this->logger = $logger;
        $this->secretKey = $secretKey;
        $this->storeId = $storeId;
    }

    /**
     * Checks imei against a blacklist
     *
     * @return boolean True if imei is ok
     */
    public function checkImei($imei)
    {
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == "352000067704506") {
            return false;
        }

        $this->send("/claimscheck/search", [
            'serial' => $imei,
            'storeid' => $this->storeId,
        ]);

        // for now, always ok the imei until we purchase db
        return true;
    }

    /**
     * Checks imei against a blacklist
     *
     * @return boolean True if imei is ok
     */
    public function makeModel($imei)
    {
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == "352000067704506") {
            return false;
        }

        $this->send("/makemodelext", [
            'serials' => [$imei],
            'storeid' => $this->storeId,
        ]);

        // for now, always ok the imei until we purchase db
        return true;
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
            print_r($body);

            return $body;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in checkImei: %s', $e->getMessage()));
        }
    }
    
    protected function sign($body)
    {
        return sha1(sprintf("%s%s", $this->secretKey, $body));        
    }

    /**
     * @param string $imei
     *
     * @return boolean
     */
    public function isImei($imei)
    {
        return $this->isLuhn($imei) && strlen($imei) == 15;
    }

    /**
     * @see http://stackoverflow.com/questions/4741580/imei-validation-function
     * @param string $n
     *
     * @return boolean
     */
    protected function isLuhn($n)
    {
        $str = '';
        foreach (str_split(strrev((string) $n)) as $i => $d) {
            $str .= $i %2 !== 0 ? $d * 2 : $d;
        }
        return array_sum(str_split($str)) % 10 === 0;
    }
}
