<?php

namespace PayCow\Exceptions;

/**
 * Thrown when the PayCow REST API returns an HTTP 4xx or 5xx error response.
 */
class ApiException extends PayCowException
{
    /**
     * HTTP Status Code.
     *
     * @var int
     */
    protected int $statusCode;

    /**
     * Raw Response Body.
     *
     * @var string|null
     */
    protected ?string $responseBody;

    /**
     * Constructor.
     *
     * @param string $message
     * @param int $statusCode
     * @param string|null $responseBody
     */
    public function __construct(string $message, int $statusCode = 0, ?string $responseBody = null)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    /**
     * Get HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get raw response body.
     *
     * @return string|null
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
