<?php

namespace TestHelper\PassportModels;

use Laravel\Passport\Token;

class DummyToken extends Token
{
    /**
     * @var array
     */
    protected static $staticAttributes = [];

    /**
     * @var null
     */
    public static $dummyClient = null;

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
    public function getKey()
    {
        return 1;
    }

    /**
     * @inerhitDoc
     */
    public function __get($key)
    {
        return ($key == 'client' && static::$dummyClient) ? static::$dummyClient : parent::__get($key);
    }

    /**
     * @param array $attributes
     * @return $this
     */
    public function create(array $attributes): self
    {
        static::$staticAttributes = $attributes;
        return $this;
    }

    /**
     * @inerhitDoc
     */
    public function __call($name, $arguments)
    {
        return $this;
    }
}
