<?php

namespace TestHelper\Tools;

use DOMXPath;
use DOMElement;
use DOMDocument;
use LibXMLError;
use ErrorException;
use InvalidArgumentException;
use Illuminate\Support\Collection;

class XmlParser
{
    /**
     * @var string
     */
    protected $xmlString;

    /**
     * @var DOMDocument
     */
    protected $document;

    /**
     * @var DOMXPath
     */
    protected $xPath;

    /**
     * @var string
     */
    protected $queryString = '';

    /**
     * @var LibXMLError[]
     */
    protected $errorCollection = [];

    /**
     * XmlParser constructor.
     * @param string|DOMDocument|DOMXPath $xml
     * @param string $version
     * @param string $encoding
     * @param bool $isHtml
     * @throws ErrorException
     */
    public function __construct($xml, bool $isHtml = false, string $version = '1.0', string $encoding = 'UTF-8')
    {
        $this->setXml($xml, $isHtml, $version, $encoding);
    }

    /**
     * @param string|DOMDocument|DOMXPath $xml
     * @param bool $isHtml
     * @param string $version
     * @param string $encoding
     * @return $this
     * @throws ErrorException
     */
    public function setXml($xml, bool $isHtml = false, string $version = '1.0', string $encoding = 'UTF-8'): self
    {
        if (empty(trim($xml))) {
            $this->xmlString = $xml;
            $this->document = app(DOMDocument::class, compact('version', 'encoding'));
            $this->xPath = app(DOMXpath::class, ['doc' => $this->document]);
        } elseif (is_string($xml)) {
            $this->xmlString = $xml;
            $this->document = app(DOMDocument::class, compact('version', 'encoding'));
            $this->load($xml, $isHtml);
            $this->xPath = app(DOMXpath::class, ['doc' => $this->document]);
        } elseif ($xml instanceof DOMDocument) {
            $this->document = $xml;
            $this->xmlString = $isHtml ? $this->document->saveHTML() : $this->document->saveXML();
            $this->xPath = app(DOMXpath::class, ['doc' => $this->document]);
        } elseif ($xml instanceof DOMXPath) {
            $this->xPath = $xml;
            $this->document = $xml->document;
            $this->xmlString = $isHtml ? $this->document->saveHTML() : $this->document->saveXML();
        } else {
            $type = (($type = gettype($xml)) == 'object') ? get_class($xml) : $type;
            throw new InvalidArgumentException('First argument must be a string or instance of '
                . DOMDocument::class . ' or ' . DOMXPath::class.'.' . ' ' . $type . ' given.');
        }

        return $this;
    }

    /**
     * @param string $xml
     * @param bool $isHtml
     * @return $this
     * @throws ErrorException
     */
    public function load(string $xml, bool $isHtml = false): self
    {
        try {
            if ($isHtml) {
                libxml_use_internal_errors(true);
                $this->document->loadHTML($xml);
                foreach (libxml_get_errors() as $error) {
                    $this->errorCollection[] = $error;
                }
                libxml_clear_errors();
            } else {
                $this->document->loadXML($xml);
            }
        } catch (ErrorException $error) {
            if (strpos($error->getMessage(), 'DOMDocument::loadHTML(): Unexpected end tag')) {
                logger()->error($error->getMessage().": $xml");
            }

            throw $error;
        }

        return $this;
    }

    /**
     * @return LibXMLError[]
     */
    public function getErrors(): array
    {
        return $this->errorCollection;
    }

    /**
     * @param string $queryString
     * @return Collection<DOMElement>
     * @throws ErrorException
     */
    public function query(string $queryString = ''): Collection
    {
        $queryString = $queryString ?: $this->queryString;

        try {
            $items = $this->xPath->query($queryString);
        } catch (ErrorException $error) {
            if ($error->getMessage() != 'DOMXPath::query(): Invalid expression') {
                throw $error;
            }

            throw new ErrorException($error->getMessage().": '$queryString'.", $error->getCode(), $error);
        }

        if (!$items) {
            throw new InvalidArgumentException("Invalid query expression: '$queryString'.");
        }

        return collect($items);
    }

    /**
     * @param string $queryString
     * @return DOMElement
     * @throws ErrorException
     */
    public function first(string $queryString = ''): DOMElement
    {
        $default = app(DOMElement::class, ['name' => 'null', 'value' => null, 'uri' => null]);
        /** @var DOMElement|null $element */

        return $this->query($queryString)->first(null, $default);
    }

    /**
     * @return DOMDocument
     */
    public function getDocument(): DOMDocument
    {
        return $this->document;
    }

    /**
     * @return string
     */
    public function getXmlString(): string
    {
        return $this->xmlString;
    }

    /**
     * @return DOMXPath
     */
    public function getXPath(): DOMXPath
    {
        return $this->xPath;
    }

    /**
     * @param string|array $nodeName
     * @return $this
     */
    public function whereChildren($nodeName): self
    {
        $nodeName = implode('/', (array)$nodeName);
        $this->queryString = $this->queryString ?: '/';
        $this->queryString .= "/$nodeName";

        return $this;
    }

    /**
     * @param string|array $nodeName
     * @return $this
     */
    public function whereDescendant($nodeName): self
    {
        $nodeName = implode('//', (array)$nodeName);
        $this->queryString .= "//$nodeName";

        return $this;
    }

    /**
     * @param array $attributes
     * @param string $operator
     * @return $this
     */
    public function whereAttributes(array $attributes, string $operator = 'and'): self
    {
        foreach ($attributes as $name => $value) {
            $index = $name;

            if (is_numeric($name)) {
                $name = $value;
                $value = null;
            }

            $name = "@$name";
            $value = !is_null($value) ? "\"$value\"" : '';
            $attributes[$index] = $name . ($value ? "=$value" : '');
        }

        $emptyQuery = preg_match('/(^$)|(\/$)/', $this->queryString);
        $this->queryString .= ($emptyQuery ? '*' : '') . '['.implode(" $operator ", $attributes).']';

        return $this;
    }

    /**
     * @param array|string $attributes
     * @return $this
     */
    public function whereAttributeAny($attributes): self
    {
        $this->whereAttributes((array)$attributes, 'or');

        return $this;
    }

    /**
     * @param array|string $attributes
     * @return $this
     */
    public function whereAllAttributes($attributes): self
    {
        $this->whereAttributes((array)$attributes);

        return $this;
    }

    /**
     * @param string $contains
     * @return $this
     */
    public function whereText(string $contains): self
    {
        $elmsSpecified = preg_match('/(^$)|(\/$)/', $this->queryString);
        $contains = str_replace("'", '\'', $contains);
        $this->queryString .= (!$elmsSpecified ? '*' : '') . "[text()][contains(., '$contains')]";

        return $this;
    }

    /**
     * @return $this
     */
    public function clear(): self
    {
        $this->queryString = '';

        return $this;
    }
}
