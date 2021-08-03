<?php

namespace TestHelper;

use stdClass;
use RuntimeException;

if (!function_exists('json_decode_strict')) {
    /**
     * @param string $string
     * @param bool $asArray
     * @return array|stdClass
     */
    function json_decode_strict(string $string, bool $asArray = false)
    {
        $json = json_decode($string, $asArray);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(json_last_error_msg());
        }

        return $json;
    }
}

if (!function_exists('get_type')) {
    /**
     * @param mixed $thing
     * @return string
     */
    function get_type($thing): string
    {
        return (($type = gettype($thing)) == 'object') ? get_class($thing) : $type;
    }
}
