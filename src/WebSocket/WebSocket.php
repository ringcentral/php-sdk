<?php

namespace RingCentral\SDK\WebSocket;

use Exception;
use Symfony\Component\EventDispatcher\EventDispatcher;
use React\EventLoop\Factory;
use RingCentral\SDK\Core\Utils;
use RingCentral\SDK\Platform\Platform;
use RingCentral\SDK\WebSocket\ApiRequest;
use RingCentral\SDK\WebSocket\ApiResponse;
use RingCentral\SDK\WebSocket\Events\ErrorEvent;
use RingCentral\SDK\WebSocket\Events\NotificationEvent;
use RingCentral\SDK\WebSocket\Events\CloseEvent;
use RingCentral\SDK\WebSocket\Events\SuccessEvent;

class WebSocket extends EventDispatcher
{
    const EVENT_READY = 'ready';
    const EVENT_NOTIFICATION = 'notification';
    const EVENT_CLOSE = 'close';
    const EVENT_ERROR = 'error';

    /** @var Platform */
    protected $_platform;

    /** @var WebSocket */
    protected $_socket;

    /** @var bool */
    protected $_ready;

    protected $_connectionDetails;

    protected $_wsToken;

    /** @var array */
    protected $_responseCallbacks = [];

    function __construct(Platform $platform)
    {
        $this->_platform = $platform;
        $this->_ready = false;
        $this->_responseCallbacks = [];
    }

    public function connect() {
        try {
            $this->_connect();
        } catch (Exception $e) {
            $this->dispatch(new ErrorEvent($e), self::EVENT_ERROR);
            throw $e;
        }
    }

    protected function _connect() {
        $tokenResponse = $this->_platform->post('/restapi/oauth/wstoken');
        $this->_wsToken = $tokenResponse->json();
        $authHeaders = [
            'Authorization' => 'Bearer ' . $this->_wsToken->ws_access_token
        ];
        \Ratchet\Client\connect($this->_wsToken->uri, [], $authHeaders)->then(function($conn) {
            $this->_socket = $conn;
            $this->_socket->on('message', function($msg) {
                $this->handleMessages($msg);
            });
            $this->_socket->on('close', function($code = null, $reason = null) {
                $this->clear();
                $this->dispatch(new CloseEvent($code, $reason), self::EVENT_CLOSE);
            });
            $this->_socket->on('error', function(Exception $e) {
                $this->clear();
                $this->dispatch(new ErrorEvent($e), self::EVENT_ERROR);
            });
        }, function ($e) {
            $this->clear();
            $this->dispatch(new ErrorEvent($e), self::EVENT_ERROR);
        });
    }

    public function send(string $data) {
        if (!$this->ready()) {
            $readyCallback = function() use ($data, &$readyCallback) {
                $this->removeListener(self::EVENT_READY, $readyCallback);
                $readyCallback = null;
                $this->_socket->send($data);
            };
            $this->addListener(self::EVENT_READY, $readyCallback);
        } else {
            $this->_socket->send($data);
        }
    }

    public function sendRequest(ApiRequest $request, $responseCallback) {
        $this->_responseCallbacks[$request->requestId()] = $responseCallback;
        $this->send($request->toJSON());
    }

    public function close() {
        if ($this->_socket) {
            $this->_socket->close();
            $this->clear();
        }
    }

    protected function handleMessages($msg) {
        $data = Utils::json_parse($msg, true);
        $response = $data[0];
        $body = $data[1];
        if ($response['type'] === 'ConnectionDetails') {
            if (empty($this->_connectionDetails)) {
                $this->_connectionDetails = new ApiResponse($response, $body);
            } else {
                if (empty($response['wsc'])) {
                    $response['wsc'] = $this->_connectionDetails->wsc();
                }
                if (empty($body)) {
                    $body = $this->_connectionDetails->body();
                }
                $this->_connectionDetails = new ApiResponse($response, $body);
            }
            $this->_ready = true;
            $this->dispatch(new SuccessEvent($this->_connectionDetails), self::EVENT_READY);
            return;
        }
        if ($response['type'] === 'ServerNotification') {
            $this->dispatch(new NotificationEvent($body), self::EVENT_NOTIFICATION);
            return;
        }
        $apiResponse = new ApiResponse($response, $body);
        $requestId = $apiResponse->requestId();
        if (isset($this->_responseCallbacks[$requestId])) {
            $this->_responseCallbacks[$requestId]($apiResponse);
            unset($this->_responseCallbacks[$requestId]);
        }
    }

    protected function clear() {
        $this->_ready = false;
        $this->_socket = null;
    }

    public function ready() {
        return $this->_ready;
    }
}
