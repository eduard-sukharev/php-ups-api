<?php

namespace Ups;

use DOMDocument;
use DOMNode;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use stdClass;
use Ups\Entity\LabelSpecification;
use Ups\Entity\Package;
use Ups\Entity\Shipment;
use Ups\Entity\ShipmentRequestLabelSpecification;
use Ups\Entity\ShipmentRequestReceiptSpecification;

/**
 * Package Shipping API Wrapper
 * Based on UPS Developer Guide, dated: 31 Dec 2012.
 */
class Shipping extends Ups
{
    const REQ_VALIDATE = 'validate';
    const REQ_NONVALIDATE = 'nonvalidate';

    /**
     * @var string
     */
    private $shipConfirmEndpoint = '/ShipConfirm';

    /**
     * @var string
     */
    private $shipAcceptEndpoint = '/ShipAccept';

    /**
     * @var string
     */
    private $voidEndpoint = '/Void';

    /**
     * @var string
     */
    private $recoverLabelEndpoint = '/LabelRecovery';

    private $request;

    /**
     * @param string|null $accessKey UPS License Access Key
     * @param string|null $userId UPS User ID
     * @param string|null $password UPS User Password
     * @param bool $useIntegration Determine if we should use production or CIE URLs.
     * @param RequestInterface $request
     * @param LoggerInterface PSR3 compatible logger (optional)
     */
    public function __construct($accessKey = null, $userId = null, $password = null, $useIntegration = false, RequestInterface $request = null, LoggerInterface $logger = null)
    {
        if (null !== $request) {
            $this->setRequest($request);
        }
        parent::__construct($accessKey, $userId, $password, $useIntegration, $logger);
    }

    /**
     * Create a Shipment Confirm request (generate a digest).
     *
     * @param string $validation A UPS_Shipping::REQ_* constant (or null)
     * @param stdClass $shipment Shipment data container.
     * @param ShipmentRequestLabelSpecification|null $labelSpec LabelSpecification data. Optional
     * @param ShipmentRequestReceiptSpecification|null $receiptSpec ShipmentRequestReceiptSpecification data. Optional
     *
     * @throws Exception
     *
     * @return stdClass
     */
    public function confirm(
        $validation,
        $shipment,
        ShipmentRequestLabelSpecification $labelSpec = null,
        ShipmentRequestReceiptSpecification $receiptSpec = null
    ) {
        $request = $this->createConfirmRequest($validation, $shipment, $labelSpec, $receiptSpec);
        $this->response = $this->getRequest()->request($this->createAccess(), $request, $this->compileEndpointUrl($this->shipConfirmEndpoint));
        $response = $this->response->getResponse();

        if (null === $response) {
            throw new Exception('Failure (0): Unknown error', 0);
        }

        if ($response instanceof SimpleXMLElement && $response->Response->ResponseStatusCode == 0) {
            throw new Exception(
                "Failure ({$response->Response->Error->ErrorSeverity}): {$response->Response->Error->ErrorDescription}",
                (int)$response->Response->Error->ErrorCode
            );
        } else {
            return $this->formatResponse($response);
        }
    }

    /**
     * Creates a ShipConfirm request.
     *
     * @param string $validation
     * @param Shipment $shipment
     * @param ShipmentRequestLabelSpecification|null $labelSpec
     * @param ShipmentRequestReceiptSpecification|null $receiptSpec
     *
     * @return string
     */
    private function createConfirmRequest(
        $validation,
        Shipment $shipment,
        ShipmentRequestLabelSpecification $labelSpec = null,
        ShipmentRequestReceiptSpecification $receiptSpec = null
    ) {
        $xml = new DOMDocument();
        $xml->formatOutput = true;

        // Page 45
        $container = $xml->appendChild($xml->createElement('ShipmentConfirmRequest'));

        // Page 45
        $request = $container->appendChild($xml->createElement('Request'));

        $node = $xml->importNode($this->createTransactionNode(), true);
        $request->appendChild($node);

        $request->appendChild($xml->createElement('RequestAction', 'ShipConfirm'));
        $request->appendChild($xml->createElement('RequestOption', $validation ?: 'nonvalidate'));

        // Page 47
        $shipmentNode = $container->appendChild($xml->createElement('Shipment'));

        if (isset($shipment->Description)) {
            $shipmentNode->appendChild($xml->createElement('Description', $shipment->Description));
        }

        $returnService = $shipment->getReturnService();
        if (isset($returnService)) {
            $node = $shipmentNode->appendChild($xml->createElement('ReturnService'));

            $node->appendChild($xml->createElement('Code', $returnService->getCode()));
        }

        if ($shipment->getDocumentsOnly()) {
            $shipmentNode->appendChild($xml->createElement('DocumentsOnly'));
        }

        $shipperNode = $shipmentNode->appendChild($xml->createElement('Shipper'));

        $shipperNode->appendChild($xml->createElement('Name', $shipment->Shipper->Name));

        if (isset($shipment->Shipper->AttentionName)) {
            $shipperNode->appendChild($xml->createElement('AttentionName', $shipment->Shipper->AttentionName));
        }

        if (isset($shipment->Shipper->CompanyDisplayableName)) {
            $shipperNode->appendChild($xml->createElement('CompanyDisplayableName', $shipment->Shipper->CompanyDisplayableName));
        }

        $shipperNode->appendChild($xml->createElement('ShipperNumber', $shipment->Shipper->ShipperNumber));

        if (isset($shipment->Shipper->TaxIdentificationNumber)) {
            $shipperNode->appendChild($xml->createElement('TaxIdentificationNumber', $shipment->Shipper->TaxIdentificationNumber));
        }

        if (isset($shipment->Shipper->PhoneNumber)) {
            $shipperNode->appendChild($xml->createElement('PhoneNumber', $shipment->Shipper->PhoneNumber));
        }

        if (isset($shipment->Shipper->FaxNumber)) {
            $shipperNode->appendChild($xml->createElement('FaxNumber', $shipment->Shipper->FaxNumber));
        }

        if (isset($shipment->Shipper->EMailAddress)) {
            $shipperNode->appendChild($xml->createElement('EMailAddress', $shipment->Shipper->EMailAddress));
        }

        $addressNode = $xml->importNode($this->compileAddressNode($shipment->Shipper->Address), true);
        $shipperNode->appendChild($addressNode);

        $shipToNode = $shipmentNode->appendChild($xml->createElement('ShipTo'));

        $shipToNode->appendChild($xml->createElement('CompanyName', $shipment->ShipTo->CompanyName));

        if (isset($shipment->ShipTo->AttentionName)) {
            $shipToNode->appendChild($xml->createElement('AttentionName', $shipment->ShipTo->AttentionName));
        }

        if (isset($shipment->ShipTo->PhoneNumber)) {
            $shipToNode->appendChild($xml->createElement('PhoneNumber', $shipment->ShipTo->PhoneNumber));
        }

        if (isset($shipment->ShipTo->FaxNumber)) {
            $shipToNode->appendChild($xml->createElement('FaxNumber', $shipment->ShipTo->FaxNumber));
        }

        if (isset($shipment->ShipTo->EMailAddress)) {
            $shipToNode->appendChild($xml->createElement('EMailAddress', $shipment->ShipTo->EMailAddress));
        }

        $addressNode = $xml->importNode($this->compileAddressNode($shipment->ShipTo->Address), true);

        if (isset($shipment->ShipTo->LocationID)) {
            $addressNode->appendChild($xml->createElement('LocationID', strtoupper($shipment->ShipTo->LocationID)));
        }

        $shipToNode->appendChild($addressNode);

        if (isset($shipment->ShipFrom)) {
            $shipFromNode = $shipmentNode->appendChild($xml->createElement('ShipFrom'));

            $shipFromNode->appendChild($xml->createElement('CompanyName', $shipment->ShipFrom->getCompanyName()));

            if (isset($shipment->ShipFrom->AttentionName)) {
                $shipFromNode->appendChild($xml->createElement('AttentionName', $shipment->ShipFrom->AttentionName));
            }

            if (isset($shipment->ShipFrom->PhoneNumber)) {
                $shipFromNode->appendChild($xml->createElement('PhoneNumber', $shipment->ShipFrom->PhoneNumber));
            }

            if (isset($shipment->ShipFrom->FaxNumber)) {
                $shipFromNode->appendChild($xml->createElement('FaxNumber', $shipment->ShipFrom->FaxNumber));
            }

            $addressNode = $xml->importNode($this->compileAddressNode($shipment->ShipFrom->Address), true);
            $shipFromNode->appendChild($addressNode);
        }

        if (isset($shipment->SoldTo)) {
            $soldToNode = $shipmentNode->appendChild($xml->createElement('SoldTo'));

            if (isset($shipment->SoldTo->Option)) {
                $soldToNode->appendChild($xml->createElement('Option', $shipment->SoldTo->Option));
            }

            $soldToNode->appendChild($xml->createElement('CompanyName', $shipment->SoldTo->CompanyName));

            if (isset($shipment->SoldTo->AttentionName)) {
                $soldToNode->appendChild($xml->createElement('AttentionName', $shipment->SoldTo->AttentionName));
            }

            if (isset($shipment->SoldTo->PhoneNumber)) {
                $soldToNode->appendChild($xml->createElement('PhoneNumber', $shipment->SoldTo->PhoneNumber));
            }

            if (isset($shipment->SoldTo->FaxNumber)) {
                $soldToNode->appendChild($xml->createElement('FaxNumber', $shipment->SoldTo->FaxNumber));
            }

            if (isset($shipment->SoldTo->Address)) {
                $addressNode = $xml->importNode($this->compileAddressNode($shipment->SoldTo->Address), true);
                $soldToNode->appendChild($addressNode);
            }
        }

        $alternate = $shipment->getAlternateDeliveryAddress();
        if (isset($alternate)) {
            $shipmentNode->appendChild($alternate->toNode($xml));
        }

        if (isset($shipment->PaymentInformation)) {
            $paymentNode = $shipmentNode->appendChild($xml->createElement('PaymentInformation'));

            if ($shipment->PaymentInformation->Prepaid) {
                $node = $paymentNode->appendChild($xml->createElement('Prepaid'));
                $node = $node->appendChild($xml->createElement('BillShipper'));

                if ($shipment->PaymentInformation->Prepaid->BillShipper->AccountNumber) {
                    $node->appendChild($xml->createElement('AccountNumber', $shipment->PaymentInformation->Prepaid->BillShipper->AccountNumber));
                } elseif ($shipment->PaymentInformation->Prepaid->BillShipper->CreditCard) {
                    $ccNode = $node->appendChild($xml->createElement('CreditCard'));
                    $ccNode->appendChild($xml->createElement('Type', $shipment->PaymentInformation->Prepaid->BillShipper->CreditCard->Type));
                    $ccNode->appendChild($xml->createElement('Number', $shipment->PaymentInformation->Prepaid->BillShipper->CreditCard->Number));
                    $ccNode->appendChild($xml->createElement('ExpirationDate', $shipment->PaymentInformation->Prepaid->BillShipper->CreditCard->ExpirationDate));

                    if ($shipment->PaymentInformation->Prepaid->BillShipper->CreditCard->SecurityCode) {
                        $ccNode->appendChild($xml->createElement('SecurityCode', $shipment->PaymentInformation->Prepaid->BillShipper->CreditCard->SecurityCode));
                    }

                    if ($shipment->PaymentInformation->Prepaid->BillShipper->CreditCard->Address) {
                        $addressNode = $xml->importNode($this->compileAddressNode($shipment->PaymentInformation->Prepaid->BillShipper->CreditCard->Address), true);
                        $ccNode->appendChild($addressNode);
                    }
                }
            } elseif ($shipment->PaymentInformation->BillThirdParty) {
                $node = $paymentNode->appendChild($xml->createElement('BillThirdParty'));
                $btpNode = $node->appendChild($xml->createElement('BillThirdPartyShipper'));
                $btpNode->appendChild($xml->createElement('AccountNumber', $shipment->PaymentInformation->BillThirdParty->AccountNumber));

                $tpNode = $btpNode->appendChild($xml->createElement('ThirdParty'));
                $addressNode = $tpNode->appendChild($xml->createElement('Address'));

                if ($shipment->PaymentInformation->BillThirdParty->ThirdParty->Address->PostalCode) {
                    $addressNode->appendChild($xml->createElement('PostalCode', $shipment->PaymentInformation->BillThirdParty->ThirdParty->Address->PostalCode));
                }

                $addressNode->appendChild($xml->createElement('CountryCode', $shipment->PaymentInformation->BillThirdParty->ThirdParty->Address->CountryCode));
            } elseif ($shipment->PaymentInformation->FreightCollect) {
                $node = $paymentNode->appendChild($xml->createElement('FreightCollect'));
                $brNode = $node->appendChild($xml->createElement('BillReceiver'));
                $brNode->appendChild($xml->createElement('AccountNumber', $shipment->PaymentInformation->FreightCollect->BillReceiver->AccountNumber));

                if ($shipment->PaymentInformation->FreightCollect->BillReceiver->Address) {
                    $addressNode = $brNode->appendChild($xml->createElement('Address'));
                    $addressNode->appendChild($xml->createElement('PostalCode', $shipment->PaymentInformation->FreightCollect->BillReceiver->Address->PostalCode));
                }
            } elseif ($shipment->PaymentInformation->ConsigneeBilled) {
                $paymentNode->appendChild($xml->createElement('ConsigneeBilled'));
            }
        } elseif (isset($shipment->ItemizedPaymentInformation)) {
            //$paymentNode = $shipmentNode->appendChild($xml->createElement('ItemizedPaymentInformation'));
        }

        if (isset($shipment->GoodsNotInFreeCirculationIndicator)) {
            $shipmentNode->appendChild($xml->createElement('GoodsNotInFreeCirculationIndicator'));
        }

        if (isset($shipment->MovementReferenceNumber)) {
            $shipmentNode->appendChild($xml->createElement('MovementReferenceNumber', $shipment->MovementReferenceNumber));
        }

        $serviceNode = $shipmentNode->appendChild($xml->createElement('Service'));
        $serviceNode->appendChild($xml->createElement('Code', $shipment->Service->getCode()));

        if (isset($shipment->Service->Description)) {
            $serviceNode->appendChild($xml->createElement('Description', $shipment->Service->Description));
        }

        if (isset($shipment->InvoiceLineTotal)) {
            $node = $shipmentNode->appendChild($xml->createElement('InvoiceLineTotal'));

            if ($shipment->InvoiceLineTotal->CurrencyCode) {
                $node->appendChild($xml->createElement('CurrencyCode', $shipment->InvoiceLineTotal->CurrencyCode));
            }

            $node->appendChild($xml->createElement('MonetaryValue', $shipment->InvoiceLineTotal->MonetaryValue));
        }

        if (isset($shipment->NumOfPiecesInShipment)) {
            $shipmentNode->appendChild($xml->createElement('NumOfPiecesInShipment', $shipment->NumOfPiecesInShipment));
        }

        if (isset($shipment->RateInformation)) {
            $node = $shipmentNode->appendChild($xml->createElement('RateInformation'));
            $node->appendChild($xml->createElement('NegotiatedRatesIndicator'));
        }

        foreach ($shipment->getPackages() as &$package) {
            $container->appendChild($xml->importNode($package->toNode($xml), true));
        }

        $shipmentServiceOptions = $shipment->getShipmentServiceOptions();
        if (isset($shipmentServiceOptions)) {
            $shipmentNode->appendChild($shipmentServiceOptions->toNode($xml));
        }

        $referenceNumber = $shipment->getReferenceNumber();
        if (isset($referenceNumber)) {
            $shipmentNode->appendChild($referenceNumber->toNode($xml));
        }

        if ($labelSpec) {
            $container->appendChild($xml->importNode($this->compileLabelSpecificationNode($labelSpec), true));
        }

        $shipmentIndicationType = $shipment->getShipmentIndicationType();
        if (isset($shipmentIndicationType)) {
            $shipmentNode->appendChild($shipmentIndicationType->toNode($xml));
        }

        if ($receiptSpec) {
            $container->appendChild($xml->importNode($this->compileReceiptSpecificationNode($receiptSpec), true));
        }

        return $xml->saveXML();
    }

    /**
     * Create a Shipment Accept request (generate a shipping label).
     *
     * @param string $shipmentDigest The UPS Shipment Digest received from a ShipConfirm request.
     *
     * @throws Exception
     *
     * @return stdClass
     */
    public function accept($shipmentDigest)
    {
        $request = $this->createAcceptRequest($shipmentDigest);
        $this->response = $this->getRequest()->request($this->createAccess(), $request, $this->compileEndpointUrl($this->shipAcceptEndpoint));
        $response = $this->response->getResponse();

        if (null === $response) {
            throw new Exception('Failure (0): Unknown error', 0);
        }

        if ($response instanceof SimpleXMLElement && $response->Response->ResponseStatusCode == 0) {
            throw new Exception(
                "Failure ({$response->Response->Error->ErrorSeverity}): {$response->Response->Error->ErrorDescription}",
                (int)$response->Response->Error->ErrorCode
            );
        } else {
            return $this->formatResponse($response->ShipmentResults);
        }
    }

    /**
     * Creates a ShipAccept request.
     *
     * @param string $shipmentDigest
     *
     * @return string
     */
    private function createAcceptRequest($shipmentDigest)
    {
        $xml = new DOMDocument();
        $xml->formatOutput = true;

        $container = $xml->appendChild($xml->createElement('ShipmentAcceptRequest'));
        $request = $container->appendChild($xml->createElement('Request'));

        $node = $xml->importNode($this->createTransactionNode(), true);
        $request->appendChild($node);

        $request->appendChild($xml->createElement('RequestAction', 'ShipAccept'));
        $container->appendChild($xml->createElement('ShipmentDigest', $shipmentDigest));

        return $xml->saveXML();
    }

    /**
     * Void a shipping label / request.
     *
     * @param string|array $shipmentData Either the UPS Shipment Identification Number or an array of
     *                                   expanded shipment data [shipmentId:, trackingNumbers:[...]]
     *
     * @throws Exception
     *
     * @return stdClass
     */
    public function void($shipmentData)
    {
        if (is_array($shipmentData) && !isset($shipmentData['shipmentId'])) {
            throw new InvalidArgumentException('$shipmentData parameter is required to contain a key `shipmentId`.');
        }

        $request = $this->createVoidRequest($shipmentData);
        $this->response = $this->getRequest()->request($this->createAccess(), $request, $this->compileEndpointUrl($this->voidEndpoint));
        $response = $this->response->getResponse();

        if ($response->Response->ResponseStatusCode == 0) {
            throw new Exception(
                "Failure ({$response->Response->Error->ErrorSeverity}): {$response->Response->Error->ErrorDescription}",
                (int)$response->Response->Error->ErrorCode
            );
        } else {
            unset($response->Response);

            return $this->formatResponse($response);
        }
    }

    /**
     * Creates a void shipment request.
     *
     * @param string|array $shipmentData
     *
     * @return string
     */
    private function createVoidRequest($shipmentData)
    {
        $xml = new DOMDocument();
        $xml->formatOutput = true;

        $container = $xml->appendChild($xml->createElement('VoidShipmentRequest'));
        $request = $container->appendChild($xml->createElement('Request'));

        $node = $xml->importNode($this->createTransactionNode(), true);
        $request->appendChild($node);

        $request->appendChild($xml->createElement('RequestAction', '1'));

        if (is_string($shipmentData)) {
            $container->appendChild($xml->createElement('ShipmentIdentificationNumber', strtoupper($shipmentData)));
        } else {
            $expanded = $container->appendChild($xml->createElement('ExpandedVoidShipment'));
            $expanded->appendChild($xml->createElement('ShipmentIdentificationNumber', strtoupper($shipmentData['shipmentId'])));

            if (array_key_exists('trackingNumbers', $shipmentData)) {
                foreach ($shipmentData['trackingNumbers'] as $tn) {
                    $expanded->appendChild($xml->createElement('TrackingNumber', strtoupper($tn)));
                }
            }
        }

        return $xml->saveXML();
    }

    /**
     * Recover a shipping label.
     *
     * @param string|array $trackingData Either the tracking number or a map of ReferenceNumber data
     *                                         [value:, shipperNumber:]
     * @param array|null $labelSpecification Map of label specification data for this request. Optional.
     *                                         [userAgent:, imageFormat: 'HTML|PDF']
     * @param array|null $labelDelivery All elements are optional. [link:]
     * @param array|null $translate Map of translation data. Optional. [language:, dialect:]
     *
     * @throws Exception|InvalidArgumentException
     *
     * @return stdClass
     */
    public function recoverLabel($trackingData, $labelSpecification = null, $labelDelivery = null, $translate = null)
    {
        if (is_array($trackingData)) {
            if (!isset($trackingData['value'])) {
                throw new InvalidArgumentException('$trackingData parameter is required to contain `value`.');
            }

            if (!isset($trackingData['shipperNumber'])) {
                throw new InvalidArgumentException('$trackingData parameter is required to contain `shipperNumber`.');
            }
        }

        if (!empty($translate)) {
            if (!isset($translateOpts['language'])) {
                $translateOpts['language'] = 'eng';
            }

            if (!isset($translateOpts['dialect'])) {
                $translateOpts['dialect'] = 'US';
            }
        }

        $request = $this->createRecoverLabelRequest($trackingData, $labelSpecification, $labelDelivery, $translate);
        $response = $this->request($this->createAccess(), $request, $this->compileEndpointUrl($this->recoverLabelEndpoint));

        if ($response->Response->ResponseStatusCode == 0) {
            throw new Exception(
                "Failure ({$response->Response->Error->ErrorSeverity}): {$response->Response->Error->ErrorDescription}",
                (int)$response->Response->Error->ErrorCode
            );
        } else {
            unset($response->Response);

            return $this->formatResponse($response);
        }
    }

    /**
     * Creates a label recovery request.
     *
     * @param string|array $trackingData
     * @param array|null $labelSpecificationOpts
     * @param array|null $labelDeliveryOpts
     * @param array|null $translateOpts
     *
     * @return string
     */
    private function createRecoverLabelRequest($trackingData, $labelSpecificationOpts = null, $labelDeliveryOpts = null, $translateOpts = null)
    {
        $xml = new DOMDocument();
        $xml->formatOutput = true;

        $container = $xml->appendChild($xml->createElement('LabelRecoveryRequest'));
        $request = $container->appendChild($xml->createElement('Request'));

        $node = $xml->importNode($this->createTransactionNode(), true);
        $request->appendChild($node);

        $request->appendChild($xml->createElement('RequestAction', 'LabelRecovery'));

        if (!empty($labelSpecificationOpts)) {
            $labelSpec = $request->appendChild($xml->createElement('LabelSpecification'));

            if (isset($labelSpecificationOpts['userAgent'])) {
                $labelSpec->appendChild($xml->createElement('HTTPUserAgent', $labelSpecificationOpts['userAgent']));
            }

            if (isset($labelSpecificationOpts['imageFormat'])) {
                $format = $labelSpec->appendChild($xml->createElement('LabelImageFormat'));
                $format->appendChild($xml->createElement('Code', $labelSpecificationOpts['imageFormat']));
            }
        }

        if (!empty($labelDeliveryOpts)) {
            $labelDelivery = $request->appendChild($xml->createElement('LabelDelivery'));
            $labelDelivery->appendChild($xml->createElement('LabelLinkIndicator', $labelDeliveryOpts['link']));
        }

        if (!empty($translateOpts)) {
            $translate = $request->appendChild($xml->createElement('Translate'));
            $translate->appendChild($xml->createElement('LanguageCode', $translateOpts['language']));
            $translate->appendChild($xml->createElement('DialectCode', $translateOpts['dialect']));
            $translate->appendChild($xml->createElement('Code', '01'));
        }

        return $xml->saveXML();
    }

    /**
     * Format the response.
     *
     * @param SimpleXMLElement $response
     *
     * @return stdClass
     */
    private function formatResponse(SimpleXMLElement $response)
    {
        return $this->convertXmlObject($response);
    }

    /**
     * Generates a standard <Address> node for requests.
     *
     * @param stdClass $address Address data structure
     *
     * @return SimpleXMLElement
     */
    private function compileAddressNode(&$address)
    {
        $xml = new DOMDocument();
        $xml->formatOutput = true;

        $node = $xml->appendChild($xml->createElement('Address'));

        $node->appendChild($xml->createElement('AddressLine1', $address->AddressLine1));

        if (isset($address->AddressLine2)) {
            $node->appendChild($xml->createElement('AddressLine2', $address->AddressLine2));
        }

        if (isset($address->AddressLine3)) {
            $node->appendChild($xml->createElement('AddressLine3', $address->AddressLine3));
        }

        $node->appendChild($xml->createElement('City', $address->City));

        if (isset($address->StateProvinceCode)) {
            $node->appendChild($xml->createElement('StateProvinceCode', $address->StateProvinceCode));
        }

        if (isset($address->PostalCode)) {
            $node->appendChild($xml->createElement('PostalCode', $address->PostalCode));
        }

        if (isset($address->CountryCode)) {
            $node->appendChild($xml->createElement('CountryCode', $address->CountryCode));
        }

        if (isset($address->ResidentialAddressIndicator)) {
            $node->appendChild($xml->createElement('ResidentialAddress'));
        }

        return $node->cloneNode(true);
    }

    /**
     * @return RequestInterface
     */
    public function getRequest()
    {
        if (null === $this->request) {
            $this->request = new Request($this->logger);
        }

        return $this->request;
    }

    /**
     * @param RequestInterface $request
     *
     * @return $this
     */
    public function setRequest(RequestInterface $request)
    {
        $this->request = $request;

        return $this;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param ResponseInterface $response
     *
     * @return $this
     */
    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;

        return $this;
    }

    /**
     * @param ShipmentRequestReceiptSpecification $receiptSpec
     * @return DOMNode
     */
    private function compileReceiptSpecificationNode(ShipmentRequestReceiptSpecification $receiptSpec)
    {
        $xml = new DOMDocument();
        $xml->formatOutput = true;

        $receiptSpecNode = $xml->appendChild($xml->createElement('ReceiptSpecification'));

        $imageFormatNode = $receiptSpecNode->appendChild($xml->createElement('ImageFormat'));
        $imageFormatNode->appendChild($xml->createElement('Code', $receiptSpec->getImageFormatCode()));

        if ($receiptSpec->getImageFormatDescription()) {
            $imageFormatNode->appendChild($xml->createElement('Description', $receiptSpec->getImageFormatDescription()));
        }

        return $receiptSpecNode->cloneNode(true);
    }

    /**
     * @param ShipmentRequestLabelSpecification $labelSpec
     * @return DOMNode
     */
    private function compileLabelSpecificationNode(ShipmentRequestLabelSpecification $labelSpec)
    {
        $xml = new DOMDocument();
        $xml->formatOutput = true;

        $labelSpecNode = $xml->appendChild($xml->createElement('LabelSpecification'));

        $printMethodNode = $labelSpecNode->appendChild($xml->createElement('LabelPrintMethod'));
        $printMethodNode->appendChild($xml->createElement('Code', $labelSpec->getPrintMethodCode()));

        if ($labelSpec->getPrintMethodDescription()) {
            $printMethodNode->appendChild($xml->createElement('Description', $labelSpec->getPrintMethodDescription()));
        }

        if ($labelSpec->getHttpUserAgent()) {
            $labelSpecNode->appendChild($xml->createElement('HTTPUserAgent', $labelSpec->getHttpUserAgent()));
        }

        //Label print method is required only for GIF label formats
        if ($labelSpec->getPrintMethodCode() == ShipmentRequestLabelSpecification::IMG_FORMAT_CODE_GIF) {
            $imageFormatNode = $labelSpecNode->appendChild($xml->createElement('LabelImageFormat'));
            $imageFormatNode->appendChild($xml->createElement('Code', $labelSpec->getImageFormatCode()));

            if ($labelSpec->getImageFormatDescription()) {
                $imageFormatNode->appendChild($xml->createElement('Description', $labelSpec->getImageFormatDescription()));
            }
        } else {
            //Label stock size is required only for non-GIF label formats
            $stockSizeNode = $labelSpecNode->appendChild($xml->createElement('LabelStockSize'));

            $stockSizeNode->appendChild($xml->createElement('Height', $labelSpec->getStockSizeHeight()));
            $stockSizeNode->appendChild($xml->createElement('Width', $labelSpec->getStockSizeWidth()));
        }

        if ($labelSpec->getInstructionCode()) {
            $instructionNode = $labelSpecNode->appendChild($xml->createElement('Instruction'));
            $instructionNode->appendChild($xml->createElement('Code', $labelSpec->getInstructionCode()));

            if ($labelSpec->getInstructionDescription()) {
                $instructionNode->appendChild($xml->createElement('Description', $labelSpec->getInstructionDescription()));
            }
        }

        return $labelSpecNode->cloneNode(true);
    }
}
