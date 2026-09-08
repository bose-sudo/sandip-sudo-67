<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $exception)
    {
        if ($request->is('api/*') || $request->expectsJson()) {

            // 422 — Validation errors
            if ($exception instanceof ValidationException) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'Validation failed.',
                    'errors'  => $exception->errors(),
                ], 422);
            }

            // 401 — Unauthenticated (JWT token missing/expired)
            if ($exception instanceof AuthenticationException) {
                return response()->json([
                    'status'  => 'failed',
                    'message' => 'Unauthenticated. Please log in.',
                ], 401);
            }

            // 403, 404, 405, etc. — proper HTTP exceptions (includes abort())
            if ($exception instanceof HttpException) {
                $statusCode = $exception->getStatusCode();
                $message    = $exception->getMessage();

                $defaults = [
                    403 => 'You do not have permission to perform this action.',
                    404 => 'Resource not found.',
                    405 => 'Method not allowed.',
                ];

                return response()->json([
                    'status'  => 'failed',
                    'message' => $message ?: ($defaults[$statusCode] ?? 'HTTP error.'),
                ], $statusCode);
            }

            // 500 — everything else
            $message = app()->environment('production')
                ? 'Something went wrong on the server.'
                : $exception->getMessage();

            return response()->json([
                'status'    => 'failed',
                'message'   => $message,
                // uncomment below for debugging in local
                // 'exception' => get_class($exception),
                // 'trace'     => $exception->getTrace(),
            ], 500);
        }

        return parent::render($request, $exception);
    }
}