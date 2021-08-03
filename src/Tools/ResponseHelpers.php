<?php

namespace TestHelper\Tools;

use Exception;
use DOMDocument;
use ErrorException;
use ReflectionClass;
use ReflectionFunction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

trait ResponseHelpers
{
    /**
     * @var array
     */
    protected static $request = [];

    /**
     * @var array
     */
    protected static $response = [];

    /**
     * @var string[]
     */
    protected $oauthKeys = [];

    /**
     * @inheritDoc
     * @throws ErrorException
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        static::$request = compact('method', 'uri', 'parameters', 'cookies', 'files', 'server', 'content');
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        static::$response = [
            'code' => $response->getStatusCode(),
            'headers' => array_map(function (array $header) {
                return count($header) == 1 ? current($header) : $header;
            }, $response->headers->all()),
            'content' => static::parseResponse($response)
        ];

        return $response;
    }

    /**
     * @inheritDoc
     */
    public static function assertThat($value, Constraint $constraint, string $message = ''): void
    {
        try {
            parent::assertThat($value, $constraint, $message);
        } catch (ExpectationFailedException $error) {
            $error = [
                'message' => $error->getMessage(),
                'expected' => optional($error->getComparisonFailure())->getExpected(),
                'actual' => optional($error->getComparisonFailure())->getActual()
            ];

            $transmission = array_filter([
                'response' => static::$response,
                'request' => static::$request
            ]);

            $route = [];

            if ($transmission) {
                $route = app('router')->getRoutes()->match(Request::create(
                    static::$request['uri'],
                    static::$request['method'],
                    static::$request['parameters']
                ));

                $action = $route->getAction();
                $controller = $action['controller'];
                $middleware = $action['middleware'] ?? [];

                if (is_string($controller)) {
                    [$class, $method] = Str::parseCallback($controller);
                    $reflection = app(ReflectionClass::class, ['argument' => $class])->getMethod($method);
                } else/*if ($controller instanceof \Closure)*/ {
                    $reflection = app(ReflectionFunction::class, ['function' => $controller]);
                    $controller = get_class($controller);
                }

                $location = $reflection->getFileName() . ':' . $reflection->getStartLine();
                $route = compact('controller', 'location', 'middleware');
            }

            $details = array_filter(compact('error', 'route', 'transmission'));

            throw new class (print_r($details, true)) extends Exception {};
        }
    }

    /**
     * @param SymfonyResponse|TestResponse $response
     * @return array|false|mixed|string
     * @throws ErrorException|Exception
     */
    public static function parseResponse($response)
    {
        $body = preg_replace('~>\s+<~', '><', $response->getContent());
        $doc = app(DOMDocument::class, ['version' => '1.0', 'encoding' => 'UTF-8']);
        $doc->formatOutput = true;

        if (!$body) {
            return '';
        }

        try {
            $doc->loadHTML($body);
        } catch (Exception $e) {
            $json = json_decode($body);
            $body = json_last_error() !== JSON_ERROR_NONE ? $body : $json;

            if (!$body) {
                throw $e;
            }
        }

        $html = $doc->saveHTML();
        $parser = app(XmlParser::class, ['xml' => $html, 'isHtml' => true]);
        $query = $parser->whereChildren(['p']);
        $json = json_decode($query->first()->nodeValue ?? '', true);

        $output = $json ?: $html;
        is_array($output) && isset($output['code']) && $output['code'] = $response->getStatusCode();

        return $output;
    }
}
