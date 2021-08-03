<?php

namespace TestHelper\Tools;

use Exception;
use Illuminate\Http\Request;
use Orchestra\Testbench\Http\Kernel;
use Illuminate\Foundation\Application;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Routing\{Controller, RouteDependencyResolverTrait, Router};

/**
 * @property Application $app
 */
trait AnonymousResponse
{
    use RouteDependencyResolverTrait;

    /**
     * @param Controller $controller
     * @param string $methodName
     * @param Request|null $request
     * @return Response
     */
    protected function generateResponse(Controller $controller, string $methodName, Request $request = null): Response
    {
        $request = $request ?: Request::create(config('app.url').'/'.uniqid('dummy-route-')."/$methodName");
        $this->applyRoute($request, $controller, $methodName);
        /** @var Kernel $kernel singleton */
        $kernel = $this->app->make(KernelContract::class);
        $response = $kernel->handle($request);
        $this->throwExceptions($response);

        return $kernel->handle($request);
    }

    /**
     * @param Response $response
     */
    protected function throwExceptions(Response $response)
    {
        if (($response->exception ?? null) instanceof Exception) {
            throw $response->exception;
        }
    }

    /**
     * @param Request $request
     * @param Controller $controller
     * @param string $methodName
     */
    protected function applyRoute(Request $request, Controller $controller, string $methodName)
    {
        /** @var Router $router singleton */
        $router = $this->app->make('router');
        $action = ['as' => $methodName, 'uses' => "@$methodName"];
        $route = $router->addRoute([$request->method()], $request->getPathInfo(), $action);
        $route->controller = $controller;
        $router->getRoutes()->add($route);
    }
}
