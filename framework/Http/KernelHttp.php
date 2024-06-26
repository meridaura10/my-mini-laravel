<?php

namespace Framework\Kernel\Http;

use Closure;
use Framework\Kernel\Application\Contracts\ApplicationInterface;
use Framework\Kernel\Facades\Facade;
use Framework\Kernel\Foundation\Exceptions\Contracts\ExceptionHandlerInterface;
use Framework\Kernel\Http\Contracts\KernelInterface;
use Framework\Kernel\Http\Requests\Contracts\RequestInterface;
use Framework\Kernel\Http\Requests\Request;
use Framework\Kernel\Http\Responses\Contracts\ResponseInterface;
use Framework\Kernel\Pipeline\BasePipeline;
use Framework\Kernel\Route\Contracts\RouterInterface;
use Framework\Kernel\Route\Pipeline;
use Throwable;

class KernelHttp implements KernelInterface
{
    protected array $middleware = [];

    protected array $middlewarePriority = [];

    protected array $middlewareGroups = [];

    protected array $middlewareAliases = [];

    protected array $bootstrappers = [
        \Framework\Kernel\Foundation\Bootstrap\LoadConfiguration::class,
        \Framework\Kernel\Foundation\Bootstrap\RegisterProviders::class,
        \Framework\Kernel\Foundation\Bootstrap\RegisterFacades::class,
        \Framework\Kernel\Foundation\Bootstrap\BootProviders::class,
    ];

    public function __construct(
        protected ApplicationInterface $app,
        protected RouterInterface $router
    ) {
        $this->syncMiddlewareToRouter();
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->sendRequestThroughRouter($request);
        }catch (Throwable $exception){
            $this->reportException($exception);

            $response = $this->renderException($request, $exception);
        }

        return $response;
    }

    public function reportException(Throwable $e): void
    {
        $this->app[ExceptionHandlerInterface::class]->report($e);
    }

    public function renderException(RequestInterface $request, Throwable $e): ResponseInterface
    {
        return $this->app[ExceptionHandlerInterface::class]->render($request, $e);
    }

    protected function sendRequestThroughRouter(RequestInterface $request): ResponseInterface
    {
        $this->app->instance('request', $request);

        Facade::clearResolvedInstance('request');

        $this->bootstrap();

        return (new Pipeline($this->app))
            ->send($request)
            ->through($this->middleware)
            ->then($this->dispatchToRouter());
    }

    protected function dispatchToRouter(): Closure
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }

    public function bootstrap(): void
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }
    }

    protected function bootstrappers(): array
    {
        return $this->bootstrappers;
    }

    protected function syncMiddlewareToRouter(): void
    {
        $this->router->setMiddlewarePriority($this->middlewarePriority);

        foreach ($this->middlewareGroups as $key => $middleware) {
            $this->router->setMiddlewareGroup($key, $middleware);
        }

        foreach ($this->middlewareAliases as $key => $middleware) {
            $this->router->setAliasMiddleware($key, $middleware);
        }
    }
}
