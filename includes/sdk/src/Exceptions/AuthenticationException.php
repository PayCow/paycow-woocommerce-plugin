<?php

namespace PayCow\Exceptions;

/**
 * Thrown when API key or API secret authentication fails (HTTP 401).
 */
class AuthenticationException extends ApiException
{
    /**
     * Constructor.
     *
     * @param string $message
     * @param int $statusCode
     * @param string|null $responseBody
     */
    public function __construct(string $message = 'Invalid PayCow API credentials', int $statusCode = 401, ?string $responseBody = null)
    {
        parent::__construct($message, $statusCode, $responseBody);
    }
}
