<?php

namespace Vant\Facturas\Internal;

use DOMDocument;
use DOMElement;
use DOMNode;
use Vant\Facturas\Errors\ArcaInvalidSoapResponseError;
use Vant\Facturas\Errors\ArcaSoapFaultError;

/**
 * XML building and parsing with the same shapes fast-xml-parser gives the
 * TypeScript SDK:
 *
 *  - namespace prefixes are removed from element and attribute names;
 *  - values are never coerced: every leaf is a trimmed string;
 *  - attributes become `@_name` keys and mixed text becomes `#text`;
 *  - an element that repeats becomes a list, a single one stays a map/string;
 *  - an empty element is the empty string.
 *
 * Building: `@_name` keys are attributes, lists repeat the element, null is
 * skipped (like `undefined`) and empty values render as self-closing tags.
 *
 * @internal
 */
final class Xml
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array{namespaceMode?: 'default'|'prefix'}  $options
     */
    public static function buildSoapEnvelope(string $soapVersion, string $operation, string $namespace, array $body, array $options = []): string
    {
        $prefix = $soapVersion === '1.2' ? 'soap12' : 'soap';
        $envelopeNamespace = $soapVersion === '1.2'
            ? 'http://www.w3.org/2003/05/soap-envelope'
            : 'http://schemas.xmlsoap.org/soap/envelope/';
        $namespaceMode = $options['namespaceMode'] ?? 'default';
        $operationElementName = $namespaceMode === 'prefix' ? "tns:{$operation}" : $operation;
        $operationNamespaceAttributes = $namespaceMode === 'prefix'
            ? ['@_xmlns:tns' => $namespace]
            : ['@_xmlns' => $namespace];

        $payload = [
            "{$prefix}:Envelope" => [
                '@_xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
                '@_xmlns:xsd' => 'http://www.w3.org/2001/XMLSchema',
                "@_xmlns:{$prefix}" => $envelopeNamespace,
                "{$prefix}:Body" => [
                    $operationElementName => $operationNamespaceAttributes + self::pruneNullDeep($body),
                ],
            ],
        ];

        return '<?xml version="1.0" encoding="utf-8"?>'.self::build($payload);
    }

    /** Serializes a map the way fast-xml-parser's XMLBuilder does with suppressEmptyNode. */
    public static function build(array $value): string
    {
        $xml = '';
        foreach ($value as $name => $child) {
            $xml .= self::buildNode((string) $name, $child);
        }

        return $xml;
    }

    private static function buildNode(string $name, mixed $value): string
    {
        if ($value === null || str_starts_with($name, '@_')) {
            return '';
        }
        if (is_array($value) && array_is_list($value) && $value !== []) {
            $xml = '';
            foreach ($value as $item) {
                $xml .= self::buildNode($name, $item);
            }

            return $xml;
        }
        if (is_array($value)) {
            if ($value === []) {
                return '';
            }
            $attributes = '';
            $children = '';
            $text = null;
            foreach ($value as $key => $child) {
                $key = (string) $key;
                if (str_starts_with($key, '@_')) {
                    if ($child === null) {
                        continue;
                    }
                    $attributes .= ' '.substr($key, 2).'="'.self::escape(self::scalar($child), true).'"';
                } elseif ($key === '#text') {
                    $text = self::escape(self::scalar($child));
                } else {
                    $children .= self::buildNode($key, $child);
                }
            }
            $content = ($text ?? '').$children;

            return $content === '' ? "<{$name}{$attributes}/>" : "<{$name}{$attributes}>{$content}</{$name}>";
        }
        $text = self::scalar($value);

        return $text === '' ? "<{$name}/>" : "<{$name}>".self::escape($text)."</{$name}>";
    }

    private static function scalar(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return Js::str($value);
    }

    /** XMLBuilder's processEntities: the same five entities in text and attributes. */
    private static function escape(string $value, bool $attribute = false): string
    {
        return str_replace(['&', '<', '>', "'", '"'], ['&amp;', '&lt;', '&gt;', '&apos;', '&quot;'], $value);
    }

    /** Removes null values recursively, like the SDK's `pruneUndefinedDeep`. */
    public static function pruneNullDeep(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $result = [];
        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }
            $result[$key] = self::pruneNullDeep($item);
        }

        return $isList ? array_values($result) : $result;
    }

    /**
     * Parses a SOAP response and returns its Body map, throwing on faults.
     *
     * @param  array{service?: string, operation?: string, endpointUrl?: string, statusCode?: int, contentType?: string, responseBody?: string, responseBodyPreviewLength?: int}  $context
     * @return array<string, mixed>
     */
    public static function parseSoapBody(string $xml, array $context = []): array
    {
        try {
            $parsed = self::parseDocument($xml);
        } catch (\Throwable $error) {
            throw self::createInvalidSoapResponseError('Invalid SOAP response: XML parse failed', $context, $error);
        }
        $envelope = $parsed['Envelope'] ?? null;
        $body = is_array($envelope) ? ($envelope['Body'] ?? null) : null;
        if (! is_array($body)) {
            throw self::createInvalidSoapResponseError('Invalid SOAP response: missing body', $context);
        }
        // `if (fault)`: an empty `<Fault/>` parses to "" and is not a fault.
        $fault = $body['Fault'] ?? null;
        if (is_array($fault) && $fault !== []) {
            throw self::createSoapFaultError($fault);
        }
        if (is_string($fault) && $fault !== '') {
            throw self::createSoapFaultError([]);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: mixed}
     */
    public static function getSingleBodyEntry(array $body, array $context = []): array
    {
        $entries = array_filter($body, fn ($key) => $key !== '@_xmlns' && ! str_starts_with((string) $key, '@_'), ARRAY_FILTER_USE_KEY);
        if (count($entries) !== 1) {
            throw self::createInvalidSoapResponseError('Invalid SOAP response: expected a single body entry, got '.count($entries), $context);
        }
        $key = array_key_first($entries);

        return [(string) $key, $entries[$key]];
    }

    /**
     * Parses any XML document into the fast-xml-parser shape. Like
     * fast-xml-parser, input without markup is an empty document, the XML
     * declaration and processing instructions become `?name` keys, and only the
     * five predefined entities are decoded (`&#233;` or `&nbsp;` stay literal).
     *
     * @return array<string, mixed>
     */
    public static function parseDocument(string $xml): array
    {
        if (! str_contains($xml, '<')) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML(self::protectEntities($xml), LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            if (! $loaded || $document->documentElement === null) {
                $message = $errors !== [] ? trim($errors[0]->message) : 'empty document';
                throw new \UnexpectedValueException("XML parse failed: {$message}");
            }
        } finally {
            libxml_use_internal_errors($previous);
        }
        $result = [];
        if (preg_match('/^\s*<\?xml\s+(.*?)\?>/s', $xml, $declaration)) {
            $result['?xml'] = self::instructionValue($declaration[1]);
        }
        foreach ($document->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $result[$node->localName] = self::convertElement($node);
            } elseif ($node instanceof \DOMProcessingInstruction) {
                $result['?'.$node->target] = self::instructionValue($node->data);
            }
        }

        return $result;
    }

    /** Escapes every `&` that does not start one of the five XML entities, outside CDATA sections. */
    private static function protectEntities(string $xml): string
    {
        return preg_replace_callback(
            '/<!\[CDATA\[.*?\]\]>|&(?!(?:amp|lt|gt|quot|apos);)/s',
            fn (array $match) => $match[0] === '&' ? '&amp;' : $match[0],
            $xml,
        ) ?? $xml;
    }

    private static function instructionValue(string $data): array|string
    {
        preg_match_all('/([^\s=]+)\s*=\s*(["\'])(.*?)\2/s', $data, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $name = str_contains($match[1], ':') ? substr($match[1], strrpos($match[1], ':') + 1) : $match[1];
            $attributes['@_'.$name] = $match[3];
        }

        return $attributes === [] ? '' : $attributes;
    }

    /**
     * Children first (a repeated name becomes a list at its first position),
     * then `#text`, then attributes, the key order fast-xml-parser produces.
     * Each text run between nodes is trimmed on its own; CDATA is kept raw.
     */
    private static function convertElement(DOMElement $element): mixed
    {
        $result = [];
        $text = '';
        $lists = [];
        /** @var DOMNode $child */
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $name = $child->localName;
                $value = self::convertElement($child);
                if (array_key_exists($name, $result)) {
                    if (! isset($lists[$name])) {
                        $result[$name] = [$result[$name]];
                        $lists[$name] = true;
                    }
                    $result[$name][] = $value;
                } else {
                    $result[$name] = $value;
                }
            } elseif ($child->nodeType === XML_CDATA_SECTION_NODE) {
                $text .= $child->nodeValue;
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $text .= Js::trim((string) $child->nodeValue);
            }
        }
        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            /** @var \DOMAttr $attribute */
            $name = $attribute->localName;
            if ($attribute->prefix === 'xmlns' || $name === 'xmlns') {
                continue;
            }
            $attributes['@_'.$name] = $attribute->value;
        }
        if ($result === [] && $attributes === []) {
            return $text;
        }
        if ($text !== '') {
            $result['#text'] = $text;
        }

        return $result + $attributes;
    }

    private static function createInvalidSoapResponseError(string $message, array $context, ?\Throwable $previous = null): ArcaInvalidSoapResponseError
    {
        $responseBody = $context['responseBody'] ?? '';

        return new ArcaInvalidSoapResponseError($message, [
            'previous' => $previous,
            'service' => $context['service'] ?? null,
            'operation' => $context['operation'] ?? null,
            'endpointUrl' => $context['endpointUrl'] ?? null,
            'statusCode' => $context['statusCode'] ?? null,
            'contentType' => $context['contentType'] ?? null,
            ...Redaction::createResponseBodyDiagnostic($responseBody, $context['responseBodyPreviewLength'] ?? null),
        ]);
    }

    private static function createSoapFaultError(array $fault): ArcaSoapFaultError
    {
        $faultCode = is_string($fault['faultcode'] ?? null) ? $fault['faultcode'] : self::nestedString($fault, ['Code', 'Value']);
        $message = is_string($fault['faultstring'] ?? null)
            ? $fault['faultstring']
            : (self::nestedString($fault, ['Reason', 'Text']) ?? 'ARCA SOAP fault response');

        return new ArcaSoapFaultError($message, ['faultCode' => $faultCode]);
    }

    private static function nestedString(array $value, array $path): ?string
    {
        $current = $value;
        foreach ($path as $key) {
            if (! is_array($current)) {
                return null;
            }
            $current = $current[$key] ?? null;
        }
        if (is_array($current) && is_string($current['#text'] ?? null)) {
            return $current['#text'];
        }

        return is_string($current) ? $current : null;
    }
}
