<?php

namespace App\Traits;

// use Illuminate\Contracts\Pagination\LengthAwarePaginator;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Resources\Json\JsonResource;
// use Symfony\Component\HttpFoundation\Response;
// use Symfony\Component\HttpFoundation\StreamedResponse;
// use Throwable;

trait ResponseTrait
{
    /**
     * Return a success JSON response.
     *
     * @param  mixed  $data
     * @param  string|null  $message
     * @param  int  $code
     *
     * @return JsonResponse
     */
    protected function successResponse(
        $data,
        ?string $message,
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'status' => 'success',
            'message' => $message ?? 'Request was successful.',
            'data' => $data,
        ], $code);
    }

    /**
     * Return an error JSON response.
     *
     * @param  string|null  $message
     * @param  int  $code
     * @param  mixed  $errors
     *
     * @return JsonResponse
     */
    protected function errorResponse(
        string $message = 'Something went wrong.',
        int $code = 500,
        $errors = null
    ): JsonResponse {
        $response = [
            'status' => 'error',
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Return a not found JSON response.
     *
     * @param  string|null  $message
     *
     * @return JsonResponse
     */
    protected function notFoundResponse(string $message = 'Resource not found.'): JsonResponse
    {
        return $this->errorResponse($message, Response::HTTP_NOT_FOUND);
    }

    /**
     * Return a validation error JSON response.
     *
     * @param  array  $errors
     * @param  string|null  $message
     *
     * @return JsonResponse
     */
    protected function validationErrorResponse(
        array $errors,
        string $message = 'Validation failed.'
    ): JsonResponse {
        return $this->errorResponse($message, Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
    }

    /**
     * Return an unauthorized JSON response.
     *
     * @param  string|null  $message
     * @param  int  $code
     *
     * @return JsonResponse
     */
    protected function unauthorizedResponse(
        string $message = 'Unauthorized.',
        int $code = Response::HTTP_FORBIDDEN
    ): JsonResponse {
        return $this->errorResponse($message, $code);
    }

    /**
     * Return a file download response.
     *
     * @param  mixed  $media
     * @param  string|null  $filename
     * @param  array<string, string>  $headers
     *
     * @return StreamedResponse
     */
    protected function downloadResponse(
        $media,
        ?string $filename = null,
        array $headers = []
    ): StreamedResponse {
        $filename ??= $media->file_name;

        $defaultHeaders = [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->streamDownload(
            function () use ($media) {
                echo $media->get();
            },
            $filename,
            array_merge($defaultHeaders, $headers)
        );
    }

    /**
     * Return a created (201) JSON response.
     *
     * @param  mixed  $data
     * @param  string|null  $message
     *
     * @return JsonResponse
     */
    protected function createdResponse(
        $data,
        ?string $message = 'Resource created successfully.'
    ): JsonResponse {
        return $this->successResponse($data, $message, Response::HTTP_CREATED);
    }

    /**
     * Return a no-content (204) response.
     *
     * @return JsonResponse
     */
    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Return a bad request (400) JSON response.
     *
     * @param  string|null  $message
     * @param  mixed|null  $errors
     *
     * @return JsonResponse
     */
    protected function badRequestResponse(
        ?string $message = 'Bad request.',
        $errors = null
    ): JsonResponse {
        return $this->errorResponse(
            $message ?? 'Bad request.',
            Response::HTTP_BAD_REQUEST,
            $errors
        );
    }

    /**
     * Return a forbidden (403) JSON response.
     *
     * @param  string|null  $message
     *
     * @return JsonResponse
     */
    protected function forbiddenResponse(
        ?string $message = 'Forbidden.'
    ): JsonResponse {
        return $this->errorResponse(
            $message ?? 'Forbidden.',
            Response::HTTP_FORBIDDEN
        );
    }

    /**
     * Return a conflict (409) JSON response.
     *
     * @param  string|null  $message
     * @param  mixed|null  $errors
     *
     * @return JsonResponse
     */
    protected function conflictResponse(
        ?string $message = 'Resource conflict.',
        $errors = null
    ): JsonResponse {
        return $this->errorResponse(
            $message ?? 'Resource conflict.',
            Response::HTTP_CONFLICT,
            $errors
        );
    }

    /**
     * Return a paginated success response.
     * Handles both LengthAwarePaginator and AnonymousResourceCollection.
     *
     * @param  LengthAwarePaginator|JsonResource  $resource
     * @param  array|string|null  $secondParam  Either validated array or message string
     * @param  string|null  $message  Optional message (only used with paginator)
     *
     * @return JsonResponse|array
     */
    protected function paginatedResponse(
        LengthAwarePaginator|JsonResource $resource,
        array|string|null $secondParam = null,
        ?string $message = null
    ) {
        // If it's a resource collection, handle it
        if ($resource instanceof JsonResource) {
            $validated = is_array($secondParam) ? $secondParam : [];
            $isPaginated = $validated['is_paginated'] ?? false;

            return $isPaginated
                ? $resource->response()->getData(true)
                : $resource;
        }

        // If it's a paginator, handle it
        if ($resource instanceof LengthAwarePaginator) {
            $msg = is_string($secondParam) ? $secondParam : ($message ?? 'Request was successful.');

            return response()->json([
                'status' => 'success',
                'message' => $msg,
                'data' => $resource->items(),
                'meta' => [
                    'current_page' => $resource->currentPage(),
                    'last_page' => $resource->lastPage(),
                    'per_page' => $resource->perPage(),
                    'total' => $resource->total(),
                ],
            ]);
        }

        throw new \InvalidArgumentException('Resource must be LengthAwarePaginator or AnonymousResourceCollection');
    }

    /**
     * Return a boolean success response.
     *
     * @param  string|null  $message
     *
     * @return JsonResponse
     */
    protected function okResponse(
        ?string $message = 'Operation completed successfully.'
    ): JsonResponse {
        return $this->successResponse(true, $message);
    }

    /**
     * Convert an exception into a standardized error response.
     *
     * @param  Throwable  $exception
     * @param  int|null  $code
     *
     * @return JsonResponse
     */
    protected function exceptionResponse(
        Throwable $exception,
        ?int $code = null
    ): JsonResponse {
        return $this->errorResponse(
            $exception->getMessage(),
            $code ?? Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }

    /**
     * Stream a CSV response.
     *
     * @param  callable  $callback
     * @param  string  $filename
     * @param  array<string, string>  $headers
     *
     * @return StreamedResponse
     */
    protected function csvResponse(
        callable $callback,
        string $filename,
        array $headers = []
    ): StreamedResponse {
        $defaultHeaders = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->streamDownload(
            $callback,
            $filename,
            array_merge($defaultHeaders, $headers)
        );
    }
}
