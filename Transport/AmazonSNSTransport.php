<?php
/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 * @author      Jan Kozak <galvani78@gmail.com>
 */

namespace MauticPlugin\MauticAmazonSNSBundle\Transport;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Api\AbstractSmsApi;
use Monolog\Logger;
use GuzzleHttp\Client;
use Smsapi\Client\SmsapiClientException;
use Aws\Credentials\Credentials;
use Aws\Sns\SnsClient;

class AmazonSNSTransport extends AbstractSmsApi
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var IntegrationHelper
     */
    protected $integrationHelper;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    private $api_key;

    /**
     * @var string
     */
    private $secret_id;
    
    /**
     * @var string
     */
    private $region;

    /**
     * @var bool
     */
    protected $connected;

    /**
     * @param IntegrationHelper $integrationHelper
     * @param Logger            $logger
     * @param Client            $client
     */
    public function __construct(IntegrationHelper $integrationHelper, Logger $logger, Client $client)
    {
        $this->integrationHelper = $integrationHelper;
        $this->logger = $logger;
        $this->client = $client;
        $this->connected = false;
    }

    /**
     * @param Lead   $contact
     * @param string $content
     *
     * @return bool|string
     */
    public function sendSms(Lead $contact, $content)
    {
        $number = $contact->getLeadPhoneNumber();
        $leadName = $contact->getName();
        if (empty($number)) {
            return false;
        }

        try {
            $number = substr($this->sanitizeNumber($number), 1);
        } catch (NumberParseException $e) {
            $this->logger->addInfo('Invalid number format. ', ['exception' => $e]);
            return $e->getMessage();
        }
        
        try {
            if (!$this->connected && !$this->configureConnection()) {
                throw new \Exception("Amazon SNS is not configured properly.");
            }
            if (empty($content)) {
                throw new \Exception('Message content is Empty.');
            }

            $response = $this->send($number, $content, $leadName);
            $this->logger->addInfo("Amazon SNS request succeeded. ", ['response' => $response]);
            return true;
        } catch (\Exception $e) {
            $this->logger->addError("Amazon SNS request failed. ", ['exception' => $e]);
            return $e->getMessage();
        }
    }

    /**
     * @param integer   $number
     * @param string    $content
     * 
     * @return array
     * 
     * @throws \Exception
     */
    protected function send($number, $content, $leadName)
    {
        $token      = $this->api_key;
        $secret     = $this->secret_id;
        $region     = $this->region;

        try {
            $client = new SnsClient(
                [
                    'credentials' => new Credentials(
                        $token,
                        $secret
                    ),
                    'region'  => $region,
                    'version' => 'latest',
                ]
            );

            $client->publish([
                'Message'     => $content,
                'PhoneNumber' => $number,
            ]);

            $this->logger->notice('Send SMS to  ' . $leadName . ' on number ' . $number . ' -> ' . $content);
        } catch (SmsapiClientException $clientException) {
            $this->logger->error('Send SMS to ' . $leadName . ' fail' . $clientException->getMessage());

            return $clientException->getMessage();
        }

        return '200';
    }
    
    /**
     * @param string $number
     *
     * @return string
     *
     * @throws NumberParseException
     */
    protected function sanitizeNumber($number)
    {
        $util = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($number, 'CO');

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * @return bool
     */
    protected function configureConnection()
    {
        $integration = $this->integrationHelper->getIntegrationObject('AmazonSNS');
        if ($integration && $integration->getIntegrationSettings()->getIsPublished()) {
            $keys = $integration->getDecryptedApiKeys();
            if (empty($keys['api_key']) || empty($keys['secret_id']) || empty($keys['region'])) {
                return false;
            }

            $this->api_key   = $keys['api_key'];
            $this->secret_id = $keys['secret_id'];
            $this->region    = $keys['region'];
            $this->connected = true;
        }
        return $this->connected;
    }
}
