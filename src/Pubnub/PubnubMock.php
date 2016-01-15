<?php

namespace RingCentral\SDK\Pubnub;

use Pubnub\Pubnub;
use Symfony\Component\EventDispatcher\EventDispatcher;

class PubnubMock extends Pubnub
{

    /** @var EventDispatcher */
    private $eventDispatcher;

    public function __construct(array $options = array())
    {

        parent::__construct($options);
        $this->eventDispatcher = new EventDispatcher();

    }

    public function subscribe($channel, $callback, $timeToken = 0, $presence = false, $timeoutHandler = null)
    {

        $this->eventDispatcher->addListener('message', function (PubnubEvent $e) use ($callback) {
            call_user_func($callback, $e->getData());
        });

    }

    public function receiveMessage($message)
    {

        $this->eventDispatcher->dispatch('message', new PubnubEvent(array(
            'message'   => $message,
            'channel'   => null,
            'timeToken' => time()
        )));

    }

}