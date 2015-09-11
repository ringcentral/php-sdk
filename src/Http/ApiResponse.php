<?php

namespace RingCentral\SDK\Http;

use Exception;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RingCentral\SDK\Core\Utils;
use stdClass;

/**
 * FIXME Support streams
 * @package RingCentral\SDK\Http
 * @see     http://www.opensource.apple.com/source/apache_mod_php/apache_mod_php-7/php/pear/Mail/mimeDecode.php
 * @see     https://github.com/php-mime-mail-parser/php-mime-mail-parser
 */
class ApiResponse
{

    /** @var array */
    protected $_jsonAsArray;

    /** @var stdClass */
    protected $_jsonAsObject;

    /** @var ApiResponse[] */
    protected $_multiparts;

    /** @var ResponseInterface */
    protected $_response;

    /** @var RequestInterface */
    protected $_request;

    protected $_raw;

    /**
     * TODO Support strams
     * @param RequestInterface $request Reqeuest used to get the response
     * @param mixed            $body    Stream body
     * @param int              $status  Status code for the response, if any
     */
    public function __construct(RequestInterface $request = null, $body = null, $status = 200)
    {

        $this->_request = $request;
        $this->_raw = $body;

        $body = (string)$body;

        // Make the HTTP message complete
        if (substr($body, 0, 5) !== 'HTTP/') {
            $body = "HTTP/1.1 " . $status . " OK\r\n" . $body;
        }

        $this->_response = \GuzzleHttp\Psr7\parse_response($body);

    }

    /**
     * @return string
     */
    public function text()
    {
        return (string)$this->body();
    }

    /**
     * @return \Psr\Http\Message\StreamInterface
     */
    public function body()
    {
        return $this->_response->getBody();
    }

    /**
     * @return mixed
     */
    public function raw()
    {
        return $this->_raw;
    }

    /**
     * Parses response body as JSON
     * Result is cached internally
     * @param bool $asObject
     * @return stdClass|array
     * @throws Exception
     */
    public function json($asObject = true)
    {

        if (!$this->isContentType('application/json')) {
            throw new Exception('Response is not JSON');
        }

        if (($asObject && empty($this->_jsonAsObject)) || (!$asObject && empty($this->_jsonAsArray))) {

            $json = Utils::json_parse($this->text(), !$asObject);

            if ($asObject) {
                $this->_jsonAsObject = $json;
            } else {
                $this->_jsonAsArray = $json;
            }

        }

        return $asObject ? $this->_jsonAsObject : $this->_jsonAsArray;

    }

    /**
     * Parses multipart response body as an array of ApiResponse objects
     * @return ApiResponse[]
     * @throws Exception
     */
    public function multipart()
    {

        if (empty($this->_multiparts)) {

            $this->_multiparts = array();

            if (!$this->isContentType('multipart/mixed')) {
                throw new Exception('Response is not multipart');
            }

            // Step 1. Get boundary

            preg_match('/boundary=([^";]+)/i', $this->getContentType(), $matches);

            if (empty($matches[1])) {
                throw new Exception('Boundary not found');
            }

            $boundary = $matches[1];

            // Step 2. Split by boundary and remove first and last parts if needed

            $parts = explode('--' . $boundary . '', $this->text()); //TODO Handle as stream

            if (empty($parts[0])) {
                array_shift($parts);
            }

            if (trim($parts[count($parts) - 1]) == '--') {
                array_pop($parts);
            }

            if (count($parts) == 0) {
                throw new Exception('No parts found');
            }

            // Step 3. Create status info object

            $statusInfoPart = array_shift($parts);
            $statusInfoObj = new self(null, trim($statusInfoPart), $this->response()->getStatusCode());
            $statusInfo = $statusInfoObj->json()->response;

            // Step 4. Parse all parts into Response objects

            foreach ($parts as $i => $part) {

                $partInfo = $statusInfo[$i];

                $this->_multiparts[] = new self(null, trim($part), $partInfo->status);

            }

        }

        return $this->_multiparts;

    }

    /**
     * @return bool
     */
    public function ok()
    {
        $status = $this->response()->getStatusCode();
        return $status >= 200 && $status < 300;
    }

    /**
     * Returns a meaningful error message
     * @return string
     */
    public function error()
    {

        $res = $this->response();

        if (!$res) {
            return null;
        }

        if ($this->ok()) {
            return null;
        }

        $message = ($res->getStatusCode() ? $res->getStatusCode() . ' ' : '') .
                   ($res->getReasonPhrase() ? $res->getReasonPhrase() : 'Unknown response reason phrase');

        try {

            $data = $this->json();

            if (!empty($data->message)) {
                $message = $data->message;
            }

            if (!empty($data->error_description)) {
                $message = $data->error_description;
            }

            if (!empty($data->description)) {
                $message = $data->description;
            }

        } catch (Exception $e) {
            // This should never happen
            $message .= ' (and additional error happened during JSON parse: ' . $e->getMessage() . ')';
        }

        return $message;

    }

    /**
     * @return RequestInterface
     */
    public function request()
    {
        return $this->_request;
    }

    /**
     * @return ResponseInterface
     */
    public function response()
    {
        return $this->_response;
    }

    protected function isContentType($type)
    {
        return !!stristr(strtolower($this->getContentType()), strtolower($type));
    }

    protected function getContentType()
    {
        return $this->response()->getHeaderLine('content-type');
    }

}