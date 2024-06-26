<?php

namespace Framework\Kernel\Foundation\Exceptions;

use Framework\Kernel\Application\Contracts\ApplicationInterface;
use Framework\Kernel\Contracts\Support\Responsable;
use Framework\Kernel\Database\Exceptions\ModelNotFoundException;
use Framework\Kernel\Foundation\Exceptions\Contracts\ExceptionHandlerInterface;
use Framework\Kernel\Foundation\Exceptions\Contracts\ExceptionRendererInterface;
use Framework\Kernel\Http\Exception\HttpExceptionInterface;
use Framework\Kernel\Http\Exception\NotFoundHttpException;
use Framework\Kernel\Http\Requests\Contracts\RequestInterface;
use Framework\Kernel\Http\Responses\Contracts\ResponseInterface;
use Framework\Kernel\Http\Responses\JsonResponse;
use Framework\Kernel\Http\Responses\Response;
use Framework\Kernel\Route\Exceptions\BackedEnumCaseNotFoundException;
use Framework\Kernel\Route\Router;
use Framework\Kernel\Support\Arr;
use Framework\Kernel\Support\ViewErrorBag;
use Framework\Kernel\Validator\Exceptions\ValidationException;
use Throwable;
use WeakMap;

class ExceptionHandler implements ExceptionHandlerInterface
{
    protected WeakMap $reportedExceptionMap;

    protected array $renderCallbacks = [];

    protected array $exceptionMap = [];

    protected array $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function __construct(protected ApplicationInterface $app)
    {
        $this->reportedExceptionMap = new WeakMap();

        $this->register();
    }

    public function register(): void
    {

    }

    public function report(Throwable $e): void
    {

    }

    public function render(RequestInterface $request, Throwable $e): ResponseInterface
    {
        $e = $this->mapException($e);

        if (method_exists($e, 'render') && $response = $e->render($request)) {
            return Router::toResponse($request, $response);
        }

        if ($e instanceof Responsable) {
            return $e->toResponse($request);
        }

        $e = $this->prepareException($e);

        return match (true) {
//            $e instanceof HttpResponseException => $e->getResponse(),
//            $e instanceof AuthenticationException => $this->unauthenticated($request, $e),
            $e instanceof ValidationException => $this->convertValidationExceptionToResponse($e, $request),
            default => $this->renderExceptionResponse($request, $e),
        };
    }

    protected function renderExceptionResponse(RequestInterface $request, Throwable $e): ResponseInterface
    {
        return $this->shouldReturnJson($request, $e)
            ? $this->prepareJsonResponse($request, $e)
            : $this->prepareResponse($request, $e);
    }

    protected function prepareResponse(RequestInterface $request, Throwable|HttpExceptionInterface $e): ResponseInterface
    {
        if (!$this->isHttpException($e) && config('app.debug')) {
            return $this->toIlluminateResponse($this->convertExceptionToResponse($e), $e)->prepare($request);
        }


        return $this->toIlluminateResponse(
            $this->renderHttpException($e), $e
        )->prepare($request);
    }

    protected function renderHttpException(HttpExceptionInterface $e): ResponseInterface
    {
        if($view = $this->getHttpExceptionView($e)){
            try {
                return response()->view($view, [
                    'errors' => new ViewErrorBag(),
                    'exception' => $e,
                ], $e->getStatusCode(), $e->getHeaders());
            } catch (Throwable $t) {
                config('app.debug') && throw $t;

                $this->report($t);
            }
        }

        return $this->convertExceptionToResponse($e);
    }

    protected function getHttpExceptionView(HttpExceptionInterface $e): ?string
    {
        $view = 'errors::'.$e->getStatusCode();

        if (view()->exists($view)) {
            return $view;
        }

        $view = substr($view, 0, -2).'xx';

        if (view()->exists($view)) {
            return $view;
        }

        return null;
    }

    protected function toIlluminateResponse(ResponseInterface $response): ResponseInterface
    {
        return new Response(
            $response->getContent(), $response->getStatusCode(), $response->headers->all()
        );
    }

    protected function convertExceptionToResponse(Throwable $e): ResponseInterface
    {
        return new Response(
            $this->renderExceptionContent($e),
            $this->isHttpException($e) ? $e->getStatusCode() : 500,
            $this->isHttpException($e) ? $e->getHeaders() : []
        );
    }

    protected function renderExceptionContent(Throwable $e): string
    {
        return app(ExceptionRendererInterface::class)->render($e);
    }

    protected function prepareJsonResponse(RequestInterface $request, Throwable $e): ResponseInterface
    {
        return new JsonResponse(
            $this->convertExceptionToArray($e),
            $this->isHttpException($e) ? $e->getStatusCode() : 500,
            $this->isHttpException($e) ? $e->getHeaders() : [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    protected function isHttpException(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface;
    }

    protected function convertExceptionToArray(Throwable $e): array
    {
        return config('app.debug') ? [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->map(fn($trace) => Arr::except($trace, ['args']))->all(),
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error',
        ];
    }



    protected function convertValidationExceptionToResponse(ValidationException $e, RequestInterface $request): ResponseInterface
    {
        if ($e->response) {
            return $e->response;
        }

        return $this->shouldReturnJson($request, $e)
            ? $this->invalidJson($request, $e)
            : $this->invalid($request, $e);
    }

    protected function invalid(RequestInterface $request, ValidationException $exception): ResponseInterface
    {
        return redirect($exception->redirectTo ?? url()->previous())
            ->withInput(Arr::except($request->input(), $this->dontFlash))
            ->withErrors($exception->errors(), $request->input('_error_bag', $exception->errorBag));
    }

    protected function invalidJson(RequestInterface $request, ValidationException $exception): ResponseInterface
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], $exception->status);
    }

    protected function shouldReturnJson(RequestInterface $request, Throwable $e): bool
    {
        return $request->expectsJson();
    }


    protected function prepareException(Throwable $e): Throwable
    {
        return match (true) {
            $e instanceof BackedEnumCaseNotFoundException => new NotFoundHttpException($e->getMessage(), $e),
            $e instanceof ModelNotFoundException => new NotFoundHttpException($e->getMessage(), $e),
            default => $e,
        };
    }


    protected function mapException(Throwable $e): Throwable
    {
        if (method_exists($e, 'getInnerException') &&
            ($inner = $e->getInnerException()) instanceof Throwable) {
            return $inner;
        }

        foreach ($this->exceptionMap as $class => $mapper) {
            if (is_a($e, $class)) {
                return $mapper($e);
            }
        }

        return $e;
    }

}