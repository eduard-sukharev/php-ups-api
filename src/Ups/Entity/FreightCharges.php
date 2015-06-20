<?php
namespace Ups\Entity;

use Ups\NodeInterface;
use DOMDocument;
use DOMElement;

class FreightCharges implements NodeInterface
{

    private $monetaryValue;

    function __construct($response = null)
    {
        if (null != $response) {
            if (isset($response->MonetaryValue)) {
                $this->setMonetaryValue($response->MonetaryValue);
            }
        }
    }

    /**
     * @param null|DOMDocument $document
     * @return DOMElement
     */
    public function toNode(DOMDocument $document = null)
    {
        if (null === $document) {
            $document = new DOMDocument();
        }

        $node = $document->createElement('FreightCharges');
        $node->appendChild($document->createElement('MonetaryValue', $this->getMonetaryValue()));

        return $node;
    }

    /**
     * @return mixed
     */
    public function getMonetaryValue() {
        return $this->monetaryValue;
    }

    /**
     * @param $var
     */
    public function setMonetaryValue($var) {
        $this->monetaryValue = round($var, 2); // Max 2 decimals places

        if(strlen((string) $this->monetaryValue) > 15) {
            throw new \Exception('Value too long');
        }

        return $this;
    }

} 