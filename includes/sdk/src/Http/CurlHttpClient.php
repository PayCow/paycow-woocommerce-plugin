<?php

namespace PayCow\Http;

use PayCow\Exceptions\ApiException;
use PayCow\Exceptions\AuthenticationException;

/**
 * Standard cURL-based HTTP client.
 */
class CurlHttpClient implements HttpClientInterface
{
    /**
     * Send an HTTP request via cURL.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string|null $body
     * @param int $timeout
     * @return Response
     * @throws ApiException
     * @throws AuthenticationException
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): Response
    {
        $ch = curl_init();

        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = "{$key}: {$value}";
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errno !== 0) {
            throw new ApiException("Network error connecting to PayCow API ({$errno}): {$error}", 0);
        }

        $rawBody = is_string($responseBody) ? $responseBody : '';
        $response = new Response($statusCode, $rawBody);

        if ($statusCode === 401) {
            $msg = 'Invalid PayCow API credentials.';
            try {
                $json = $response->json();
                if (!empty($json['msg'])) {
                    $msg = $json['msg'];
                }
            } catch (ApiException $e) {
                // Ignore parse error and keep default msg
            }
            throw new AuthenticationException($msg, 401, $rawBody);
        }

        if ($statusCode >= 400) {
            $msg = "PayCow API returned HTTP {$statusCode}";
            try {
                $json = $response->json();
                if (!empty($json['msg'])) {
                    $msg = $json['msg'];
                } elseif (!empty($json['error'])) {
                    $msg = $json['error'];
                }
            } catch (ApiException $e) {
                // Ignore parse error and keep default msg
            }
            throw new ApiException($msg, $statusCode, $rawBody);
        }

        return $response;
    }
}
