<?php

namespace TestHelper\Tools;

use Exception;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionException;

class DebugTrace
{
    const TRUNCATE_AT = 16;

    /**
     * @var array
     */
    protected $content = [];

    /**
     * @param array $trace
     * @return array
     */
    public function getTrace(array $trace = array()): array
    {
        $backtrace = $trace ?: debug_backtrace();

        $lines = array();
        /** @var array $trace */
        foreach ($backtrace as $trace) {
            if (!array_key_exists('function', $trace) && array_key_exists('include_filename', $trace)) {
                $trace['function'] = 'include';
                $trace['args'] = !empty($trace['args']) ? $trace['args'] : ['filename' => $trace['include_filename']];
            }
            //Handle the fact that not all desired fields will be present in each backtrace record.
            $fields = array('file', 'line', 'function', 'class', 'object', 'args');
            $trace = array_intersect_key($trace, array_flip($fields));
            $trace = array_merge(array_fill_keys($fields, ''), $trace);

            $trace['args'] = $trace['args'] ? array_map(array($this, 'normalizeArgs'), $trace['args']) : array();
            if ($trace['function']) {
                $function = $trace['class'] ? array($trace['class'], $trace['function']) : $trace['function'];
                $trace['args'] = $this->applyParameterNames($trace['args'], $function);
            }

            //String the backtrace values together in an easier-to-read fashion
            $line = $trace['file'].':'.$trace['line'];
            ($line == ':') && $line = uniqid('closure_');
            $trace['object'] = $trace['object'] ? get_class($trace['object']) : $trace['class'];
            $trace['object'] .= $trace['object'] != $trace['class'] ? '::'.$trace['class'] : '';
            $function = trim($trace['object'].'::'.$trace['function'], ':');

            $lines[$line] = array($function => $trace['args']);
        }

        return $lines;
    }

    /**
     * @param array $args
     * @param string|array $function
     * @param bool $includeDefaults
     * @return array
     */
    public function applyParameterNames(array $args, $function, bool $includeDefaults = true): array
    {
        list($class, $function) = array_pad((array)$function, -2, null);

        try {
            $reflection = $class ? new ReflectionMethod($class, $function) : new ReflectionFunction($function);

            $parameterNames = $defaults = array();
            foreach ($reflection->getParameters() as $param) {
                $parameterNames[] = $param->name;
                if ($includeDefaults) {
                    $defaults[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                }
            }

            $args = $args + $defaults;
            $args = array_pad($args, count($parameterNames), null);
            $argNames = $parameterNames + array_keys($args);
            $args = !empty($args) && !empty($argNames) ? array_combine($argNames, $args) : $args;
        } catch (ReflectionException $e) {
            //
        }

        return $args;
    }

    /**
     * @param mixed $arg
     * @return string
     */
    public function normalizeArgs($arg)
    {
        switch (gettype($arg)) {
            case 'object':
                $newArg = get_class($arg);

                break;
            case 'resource':
                $newArg = get_resource_type($arg) ?: 'resource';

                break;
            case 'array':
                $newArg = $this->normalizeArrays($arg);

                break;
            default:
                $newArg = $arg;
        }

        //Usage of the $newArg rather than reassigning $arg avoid a segmentation fault occurring in normalizeArrays.
        return $newArg;
    }

    /**
     * @param array $arg
     * @return array
     */
    protected function normalizeArrays(array $arg): array
    {
        $newArg = array();
        foreach ($arg as $index => $elm) {
            $newArg[$index] = $this->normalizeArgs($elm);
        }

        //Usage of the $newArg rather than reassigning $arg avoids a referential override of object to string names.
        return $newArg;
    }

    /**
     * @param mixed $arg
     * @return string
     */
    protected function stringifyArgs($arg): string
    {
        switch (gettype($arg)) {
            case 'string':
                if (strlen($arg) > self::TRUNCATE_AT) {
                    $arg = substr($arg, 0, self::TRUNCATE_AT).'...';
                }
                $arg = '"'.$arg.'"';

                break;
            case 'array':
                $arg = 'array('.count($arg).')';

                break;
            case 'boolean':
                $arg = $arg ? 'true' : 'false';

                break;
            case 'NULL':
                $arg = 'null';

                break;
        }

        return $arg;
    }

    /**
     * @param array $trace
     * @return array
     */
    protected function flatten(array $trace): array
    {
        /**
         * @var string $line
         * @var array $args
         */
        foreach ($trace as $line => $args) {
            $function = key($args);
            $args = current($args);

            foreach ($args as $param => $arg) {
                $args[$param] = $param.':'.$this->stringifyArgs($arg);
            }

            $args = implode(', ', $args);
            $function .= '('.$args.')';

            $trace[$line] = $function;
        }

        return $trace;
    }

    /**
     * @param mixed $arg
     * @return bool
     */
    public function isException($arg): bool
    {
        return $arg instanceof Exception;
    }

    /**
     * @return array
     */
    public function truncate(): array
    {
        $content = $this->content;
        $content['trace'] = $this->flatten($content['trace']);

        return $content;
    }

    /**
     * @param mixed|null $content
     * @return $this
     */
    public function generate($content = null): self
    {
        $content = (array)$content;
        $trace = array();

        if (!empty($content) && $this->isException($err = current($content))) {
            /** @var Exception $err */
            $key = key($content);
            $content[$key] = get_class($err).': "'.$err->getMessage().'"';
            $trace = $err->getTrace();
        }

        $content = array(
            'debug' => $content,
            'trace' => $this->getTrace($trace)
        );

        $this->content = $content;

        return $this;
    }

    /**
     * @return array
     */
    public function getContent(): array
    {
        return $this->content;
    }
}
