<?php

namespace TestHelper\Tools;

use Illuminate\Support\Arr;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\{Client, HandlerStack};
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use GuzzleHttp\Psr7\{Request as GuzzleRequest, Response as GuzzleResponse, Utils};

/**
 * @property Application $app
 */
trait MockGuzzleClient
{
    /**
     * @var RequestInterface[]
     */
    protected $guzzleRequestLog = [];

    /**
     * @var MockHandler
     */
    protected $guzzleHandler;

    /**
     * @var bool
     */
    protected $traceTransaction = false;

    /**
     * @var Client|callable|null
     */
    protected $originalBinding = null;

    /**
     * @return $this
     */
    public function mockGuzzleResponses(): self
    {
        $this->guzzleHandler = app(MockHandler::class);
        $bindings = app()->getBindings();
        $this->originalBinding = $bindings[Client::class]['concrete'] ?? function (Application $app, array $args = []) {
            return new Client(...array_values($args));
        };

        $this->app->bind(Client::class, function (Application $app, array $args = []) {
            $handler = function (RequestInterface $request, array $options) {
                $mockResponse = $this->arrayToResponse(['body' => 'dummy-response']);
                !$this->guzzleHandler->count() && $this->guzzleHandler->append($mockResponse);
                $promise = ($this->guzzleHandler)($request, $options);
                $response = $promise->wait();
                $transaction = [
                    'request' => $this->requestToArray($request),
                    'response' => $this->responseToArray($response)
                ];
                $this->traceTransaction && $transaction['trace'] = app(DebugTrace::class)->generate()->truncate()['trace'];
                $this->guzzleRequestLog[] = $transaction;

                return $promise;
            };

            $handlerStack = HandlerStack::create($handler);
            $config = Arr::first($args, null, []);
            $config['handler'] = $handlerStack;

            return new Client($config);
        });

        return $this;
    }

    /**
     * @param bool $traceTransaction
     * @return $this
     */
    public function setTraceTransaction(bool $traceTransaction): self
    {
        $this->traceTransaction = $traceTransaction;

        return $this;
    }

    /**
     * @param RequestInterface $request
     * @return array
     */
    public function requestToArray(RequestInterface $request): array
    {
        $method = $request->getMethod();
        $uri = (string)$request->getUri();
        parse_str((string)$request->getBody(), $body);
        $request->getBody()->rewind();
        $headers = array_map(function (array $header) {
            return count($header) == 1 ? current($header) : $header;
        }, $request->getHeaders());

        return compact('method', 'uri', 'headers', 'body');
    }

    /**
     * @param ResponseInterface $response
     * @return array
     */
    public function responseToArray(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        parse_str((string)$response->getBody(), $body);
        $body = (count($body) == 1 && !current($body)) ? $body = key($body) : $body;
        $response->getBody()->rewind();
        $headers = array_map(function (array $header) {
            return count($header) == 1 ? current($header) : $header;
        }, $response->getHeaders());

        return compact('status', 'body', 'headers');
    }

    /**
     * @param array $request
     * @return RequestInterface
     */
    public function arrayToRequest(array $request): RequestInterface
    {
        $request = $this->composeBody($request, ['method' => 'GET', 'uri' => '/', 'body' => null]);

        return app(GuzzleRequest::class, $request);
    }

    /**
     * @param array $response
     * @return ResponseInterface
     */
    public function arrayToResponse(array $response): ResponseInterface
    {
        $response = $this->composeBody($response, ['status' => Response::HTTP_OK, 'headers' => [], 'body' => null]);

        return app(GuzzleResponse::class, $response);
    }

    /**
     * @param array $content
     * @param array $defaults
     * @return array
     */
    private function composeBody(array $content, array $defaults = []): array
    {
        $content = array_merge($defaults, $content);
        $content = array_intersect_key($content, $defaults);

        if ($content['body']) {
            $content['body'] = is_array($content['body']) ? http_build_query($content['body']) : $content['body'];
            $content['body'] = Utils::streamFor($content['body']);
        }

        return $content;
    }
}