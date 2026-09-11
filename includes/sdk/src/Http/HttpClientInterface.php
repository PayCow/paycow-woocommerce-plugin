<?php

namespace PayCow\Http;

use PayCow\Exceptions\ApiException;

/**
 * Interface for pluggable HTTP client implementations.
 */
interface HttpClientInterface
{
    /**
     * Send an HTTP request.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url Full request URL
     * @param array $headers Key-value array of headers
     * @param string|null $body Raw request body
     * @param int $timeout Timeout in seconds
     * @return Response
     * @throws ApiException
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): Response;
}
