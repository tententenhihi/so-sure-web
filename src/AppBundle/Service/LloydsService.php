<?php
namespace AppBundle\Service;

use AppBundle\Document\File\LloydsFile;
use AppBundle\Document\File\UploadFile;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\CurrencyTrait;

class LloydsService
{
    use CurrencyTrait;

    const PAYMENT_TYPE_UNKNOWN = 'unknown';
    const PAYMENT_TYPE_BARCLAYS_STANDARD = 'barclays';
    const PAYMENT_TYPE_BARCLAYS_FPI = 'barclays-fpi';
    const PAYMENT_TYPE_BACS = 'bacs';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    /**
     * @param UploadFile $lloydsFile
     * @return array
     */
    public function processCsv(UploadFile $lloydsFile)
    {
        /** @var LloydsFile $lloydsFile */
        $filename = $lloydsFile->getFile();

        $data = $this->processActualCsv($filename);

        $lloydsFile->addMetadata('total', $data['total']);
        $lloydsFile->setDate($data['date']);
        $lloydsFile->setDailyReceived($data['dailyBarclaysReceived']);
        $lloydsFile->setDailyProcessing($data['dailyBarclaysProcessing']);
        $lloydsFile->setDailyBacs($data['dailyBacs']);

        return $data;
    }

    public function processActualCsv($filename)
    {
        $header = null;
        $lines = array();
        $dailyBarclaysReceived = array();
        $dailyBarclaysProcessing = array();
        $dailyBacs = array();

        $total = 0;
        $maxDate = null;

        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    // header has a trailing , :P
                    unset($row[count($row) - 1]);
                    $header = $row;
                } else {
                    $line = array_combine($header, $row);
                    // Exclude lines like this:
                    // 10/10/2016,,'XX-XX-XX,XXXXXXXX,INTEREST (GROSS) ,,0.03,609.61
                    // 11/10/2016,PAY,'XX-XX-XX,XXXXXXXX,OUR CHARGE FT176053329271 FP29348357778898 ,15.00,,445.51
                    // 29/11/2016,BP,'XX-XX-XX,XXXXXXXX,AFL INSURANCE BROK ,1.32,,168.68
                    // 09/11/2016,CHG,'XX-XX-XX,XXXXXXXX,RETURNED D/D ,35.00,,39.08
                    // 11/10/2016,TFR,'XX-XX-XX,XXXXXXXX,FORGN PYT293483577 ,164.10,,460.51
                    // 20/10/2017,FPO,'XX-XX-XX,XXXXXXXX, NAME XXXXX SO-SURE REWARD POT,45.00,,4996.16
                    if (in_array($line['Transaction Type'], ['TFR', 'PAY', 'BP', 'CHG', '', 'FPO'])) {
                        $this->logger->info(sprintf(
                            'Skipping line as transfer/payment/interest. %s',
                            implode($line)
                        ));
                        continue;
                    }

                    $paymentType = self::PAYMENT_TYPE_UNKNOWN;
                    $processedDate = null;

                    if (in_array($line['Transaction Type'], ['FPI'])) {
                        // Incoming faster payments
                        // 28/04/2017,FPI,'30-65-41,36346160,XXX...XXX ,,425.53,2733.96
                        $processedDate = \DateTime::createFromFormat("d/m/Y", $line['Transaction Date']);
                        $identifier = sprintf('BCARD%s', BarclaysService::MID);
                        if (mb_stripos($line['Transaction Description'], $identifier) !== false) {
                            $paymentType = self::PAYMENT_TYPE_BARCLAYS_FPI;
                        }
                    } elseif (in_array($line['Transaction Type'], ['BGC'])) {
                        // Standard incoming from barclays
                        // 13/04/2017,BGC,'30-65-41,36346160,MDIR  8008566APR11 8008566 ,,32.37,1219.18
                        $processedDates = explode(BarclaysService::MID, $line['Transaction Description']);
                        if (mb_stripos($line['Transaction Description'], 'MDIR') !== false) {
                            if (count($processedDates) != 2) {
                                $this->logger->warning(sprintf(
                                    'Skipping line as unable to parse barclays description. %s',
                                    implode($line)
                                ));
                                continue;
                            }
                            $processedDate = new \DateTime($processedDates[1]);
                            $paymentType = self::PAYMENT_TYPE_BARCLAYS_STANDARD;
                        } elseif (trim($line['Transaction Description']) == 'BACS') {
                            $processedDate = \DateTime::createFromFormat("d/m/Y", $line['Transaction Date']);
                            $paymentType = self::PAYMENT_TYPE_BACS;
                        }
                    }

                    if ($paymentType == self::PAYMENT_TYPE_UNKNOWN) {
                        $this->logger->warning(sprintf(
                            'Skipping line as unable to parse description. %s',
                            implode($line)
                        ));
                        continue;
                    }

                    $this->logger->info(sprintf('Processing line. %s', implode($line)));

                    // In the case where we're in Jan processing for Dec of previous year, as the above
                    // doesn't have a year it will assume Dec of current year (e.g. in the future)
                    if ($processedDate > new \DateTime()) {
                        $processedDate = $processedDate->sub(new \DateInterval('P1Y'));
                    }

                    $amount = 0;
                    if (is_numeric($line['Credit Amount'])) {
                        $amount = $line['Credit Amount'];
                    } elseif (is_numeric($line['Debit Amount'])) {
                        $amount = 0 - $line['Debit Amount'];
                    }
                    $total += $amount;
                    $receivedDate = \DateTime::createFromFormat("d/m/Y", $line['Transaction Date']);
                    if (!$maxDate || $maxDate > $receivedDate) {
                        $maxDate = $receivedDate;
                    }

                    if (in_array($paymentType, [
                        self::PAYMENT_TYPE_BARCLAYS_STANDARD,
                        self::PAYMENT_TYPE_BARCLAYS_FPI
                    ])) {
                        if (!isset($dailyBarclaysReceived[$receivedDate->format('Ymd')])) {
                            $dailyBarclaysReceived[$receivedDate->format('Ymd')] = 0;
                        }
                        $dailyBarclaysReceived[$receivedDate->format('Ymd')] += $amount;

                        if (!isset($dailyBarclaysProcessing[$processedDate->format('Ymd')])) {
                            $dailyBarclaysProcessing[$processedDate->format('Ymd')] = 0;
                        }
                        $dailyBarclaysProcessing[$processedDate->format('Ymd')] += $amount;
                    } elseif ($paymentType == self::PAYMENT_TYPE_BACS) {
                        if (!isset($dailyBacs[$receivedDate->format('Ymd')])) {
                            $dailyBacs[$receivedDate->format('Ymd')] = 0;
                        }
                        $dailyBacs[$receivedDate->format('Ymd')] += $amount;
                    }

                    $lines[] = $line;
                }
            }
            fclose($handle);
        }

        $data = [
            'total' => $this->toTwoDp($total),
            'date' => $maxDate,
            'data' => $lines,
            'dailyBarclaysReceived' => $dailyBarclaysReceived,
            'dailyBarclaysProcessing' => $dailyBarclaysProcessing,
            'dailyBacs' => $dailyBacs,
        ];

        return $data;
    }
}
