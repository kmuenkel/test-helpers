<?php

namespace TestHelper\PassportModels;

use Laravel\Passport\Client;

class DummyClient extends Client
{
    /**
     * @var array
     */
    public static $staticAttributes = [];

    /**
     * @inerhitDoc
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct(static::$staticAttributes);
    }

    /**
     * @inerhitDoc
     */
    public function confidential()
    {
        return true;
    }

    /**
     * @inerhitDoc
     */
    public function __call($name, $arguments)
    {
        return $this;
    }
}
