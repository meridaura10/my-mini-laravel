<?php

namespace Framework\Kernel\Foundation\Providers\Services;

use Closure;
use Framework\Kernel\Application\Contracts\ApplicationInterface;
use Framework\Kernel\Foundation\Providers\ServiceProvider;
use Framework\Kernel\Http\Responses\Factory\Contracts\ResponseFactoryInterface;
use Framework\Kernel\Http\Responses\Factory\ResponseFactory;
use Framework\Kernel\Route\Contracts\RouteRegistrarInterface;
use Framework\Kernel\Route\Contracts\RouterInterface;
use Framework\Kernel\Route\Redirector\Contracts\RedirectorInterface;
use Framework\Kernel\Route\Redirector\Redirector;
use Framework\Kernel\Route\Router;
use Framework\Kernel\Route\RouteRegistrar;
use Framework\Kernel\Route\UrlGenerator\UrlGenerator;
use Framework\Kernel\Route\UrlGenerator\UrlGeneratorInterface;
use Framework\Kernel\View\Contracts\ViewFactoryInterface;

class RoutingServiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerResponseFactory();
        $this->registerUrlGenerator();
        $this->registerRedirector();

        $this->registerRouter();
    }

    protected function registerUrlGenerator(): void
    {
        $this->app->singleton('url', function (ApplicationInterface $app) {
            $routes = $app['router']->getRoutes();

            $app->instance('routes', $routes);

            return new UrlGenerator(
                $routes, $app->rebinding(
                'request', $this->requestRebinder()
            ), $app['config']['app.asset_url'],
            );
        });

        $this->app->alias('url', UrlGeneratorInterface::class);
    }

    protected function requestRebinder(): Closure
    {
        return function ($app, $request) {
            $app['url']->setRequest($request);
        };
    }

    protected function registerResponseFactory(): void
    {
        $this->app->singleton(ResponseFactoryInterface::class, function (ApplicationInterface $app) {
            return new ResponseFactory($app[ViewFactoryInterface::class], $app['redirect']);
        });
    }

    protected function registerRedirector(): void
    {
        $this->app->singleton('redirect', function (ApplicationInterface $app) {
            $redirector = new Redirector($app['url']);

            if ($app->bound('session.store')) {
                $redirector->setSession($app['session.store']);
            }

            return $redirector;
        });

        $this->app->alias('redirect', RedirectorInterface::class);
    }

    protected function registerRouter(): void
    {
        $this->app->singleton('router', Router::class);

        $this->app->alias('router', RouterInterface::class);
        $this->app->alias('router', RouteRegistrarInterface::class);
    }
}
