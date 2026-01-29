<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleAutoCRM\Lib;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;

/**
 * AutoCRM API Client
 *
 * @package Modules\ModuleAutoCRM\Lib
 */
class AutoCrmApiClient
{
    private string $apiUrl;
    private string $apiToken;
    private Client $httpClient;
    private int $maxRetries = 3;
    private int $retryDelay = 1; // seconds

    /**
     * Constructor
     *
     * @param string|null $apiUrl API base URL
     * @param string|null $apiToken Bearer token
     */
    public function __construct(?string $apiUrl = null, ?string $apiToken = null)
    {
        if ($apiUrl === null || $apiToken === null) {
            $settings = ModuleAutoCRM::findFirst();
            if ($settings) {
                $apiUrl = $apiUrl ?? $settings->api_url;
                $apiToken = $apiToken ?? $settings->api_token;
            }
        }

        $this->apiUrl = $this->normalizeApiUrl($apiUrl ?? '');
        $this->apiToken = $apiToken ?? '';

        // Guzzle requires base_uri to end with / for proper URL resolution
        $this->httpClient = new Client([
            'base_uri' => $this->apiUrl . '/',
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            // TODO: Remove this workaround - see tasks.md
            // Disabled SSL verification for environments with missing CA certificates
            'verify' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Normalize API URL - convert server address to full API URL
     *
     * @param string $url Server address or full URL
     * @return string Full API URL
     */
    private function normalizeApiUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) {
            return '';
        }

        // Remove trailing slash
        $url = rtrim($url, '/');

        // If already a full URL with /yii/api, just ensure https
        if (strpos($url, '/yii/api') !== false) {
            if (strpos($url, 'http://') === 0) {
                $url = 'https://' . substr($url, 7);
            } elseif (strpos($url, 'https://') !== 0) {
                $url = 'https://' . $url;
            }
            return rtrim($url, '/');
        }

        // Otherwise, treat as server address and construct full URL
        // Remove http:// or https:// if present
        if (strpos($url, 'http://') === 0) {
            $url = substr($url, 7);
        } elseif (strpos($url, 'https://') === 0) {
            $url = substr($url, 8);
        }

        return 'https://' . $url . '/yii/api';
    }

    /**
     * Get users list from AutoCRM (all pages)
     *
     * @param bool $activeOnly Load only active users (status=1)
     * @return array
     * @throws AutoCrmApiException
     */
    public function getUsers(bool $activeOnly = true): array
    {
        $filters = [];
        if ($activeOnly) {
            $filters['status'] = 1;
        }
        return $this->getAllPaginated('user', $filters);
    }

    /**
     * Get all items from paginated endpoint
     *
     * @param string $endpoint API endpoint
     * @param array $filters Query filters
     * @return array All items from all pages
     * @throws AutoCrmApiException
     */
    private function getAllPaginated(string $endpoint, array $filters = []): array
    {
        $allResults = [];
        $page = 1;
        $maxPages = 100; // Safety limit

        while ($page <= $maxPages) {
            $response = $this->requestWithPagination('GET', $endpoint, [], $page, $filters);
            $items = $response['result'] ?? [];

            if (empty($items)) {
                break;
            }

            // Check pagination headers
            $pagination = $response['_pagination'] ?? [];
            $totalCount = $pagination['total_count'] ?? 0;
            $pageCount = $pagination['page_count'] ?? 1;
            $currentPage = $pagination['current_page'] ?? $page;
            $perPage = $pagination['per_page'] ?? 50;

            // Check if API is not advancing pages (server ignores page parameter)
            // Do this check BEFORE adding results to avoid duplicates
            if ($page > 1 && $currentPage <= 1) {
                // API returns same page - pagination not supported, don't add duplicates
                $gotCount = count($allResults);
                SystemMessages::sysLogMsg(
                    __CLASS__,
                    "Warning: API pagination not working. Got {$gotCount} of {$totalCount} items.",
                    LOG_WARNING
                );
                break;
            }

            // Add results after pagination check
            $allResults = array_merge($allResults, $items);

            // Log pagination info on first page
            if ($page === 1 && $totalCount > 0) {
                SystemMessages::sysLogMsg(
                    __CLASS__,
                    "Pagination: total={$totalCount}, pages={$pageCount}, per_page={$perPage}",
                    LOG_DEBUG
                );
            }

            // Check if we got all data
            if (count($allResults) >= $totalCount) {
                break;
            }

            // Move to next page
            if ($page >= $pageCount) {
                break;
            }

            $page++;
        }

        return $allResults;
    }

    /**
     * Make request with pagination support
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param int $page Page number
     * @param array $filters Query filters
     * @return array Response with _pagination metadata
     * @throws AutoCrmApiException
     */
    private function requestWithPagination(string $method, string $endpoint, array $data = [], int $page = 1, array $filters = []): array
    {
        $lastException = null;
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                $options = [];
                if (!empty($data)) {
                    $options[RequestOptions::JSON] = $data;
                }

                // Build query parameters
                $query = $filters;
                $searchParam = '';
                if ($page > 1) {
                    // Add pagination parameter (AutoCRM uses ModelSearch_page format)
                    $searchParam = $this->getSearchPageParam($endpoint);
                    $query[$searchParam] = $page;
                }
                if (!empty($query)) {
                    $options[RequestOptions::QUERY] = $query;
                }

                $response = $this->httpClient->request($method, $endpoint, $options);
                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                // Extract pagination headers
                $pagination = [
                    'total_count' => (int)$response->getHeaderLine('X-Pagination-Total-Count'),
                    'page_count' => (int)$response->getHeaderLine('X-Pagination-Page-Count'),
                    'current_page' => (int)$response->getHeaderLine('X-Pagination-Current-Page'),
                    'per_page' => (int)$response->getHeaderLine('X-Pagination-Per-Page'),
                ];

                $logEndpoint = !empty($query) ? $endpoint . '?' . http_build_query($query) : $endpoint;
                $this->logRequest($method, $logEndpoint, $data, $statusCode, $body);

                // Parse response
                $result = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $bodyPreview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
                    throw new AutoCrmApiException(
                        "Invalid JSON response: " . json_last_error_msg() . " | Response: " . $bodyPreview
                    );
                }

                // Check HTTP status
                if ($statusCode >= 400 && $statusCode < 500) {
                    $error = $this->extractError($result);
                    throw new AutoCrmApiException("API client error ({$statusCode}): {$error}", $statusCode);
                }

                if ($statusCode >= 500) {
                    $bodyPreview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
                    throw new AutoCrmApiException(
                        "API server error ({$statusCode}) | Response: " . $bodyPreview,
                        $statusCode
                    );
                }

                // Check API status
                $apiSuccess = true;
                if (isset($result['status'])) {
                    $apiSuccess = $result['status'] == 1;
                } elseif (isset($result['success'])) {
                    $apiSuccess = $result['success'] === true;
                }

                if (!$apiSuccess) {
                    $error = $this->extractError($result);
                    throw new AutoCrmApiException("API error: {$error}");
                }

                // Add pagination metadata to response
                $result['_pagination'] = $pagination;

                return $result;

            } catch (ConnectException $e) {
                $lastException = new AutoCrmApiException("Connection error: " . $e->getMessage(), 0, $e);
                $this->logError($method, $endpoint, $lastException->getMessage(), $attempt);

            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;

                if ($statusCode >= 400 && $statusCode < 500) {
                    throw new AutoCrmApiException("Request error ({$statusCode}): " . $e->getMessage(), $statusCode, $e);
                }

                $lastException = new AutoCrmApiException("Request error: " . $e->getMessage(), $statusCode, $e);
                $this->logError($method, $endpoint, $lastException->getMessage(), $attempt);

            } catch (AutoCrmApiException $e) {
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    throw $e;
                }

                $lastException = $e;
                $this->logError($method, $endpoint, $e->getMessage(), $attempt);

            } catch (GuzzleException $e) {
                $lastException = new AutoCrmApiException("HTTP error: " . $e->getMessage(), 0, $e);
                $this->logError($method, $endpoint, $lastException->getMessage(), $attempt);
            }

            if ($attempt < $this->maxRetries) {
                sleep($this->retryDelay * $attempt);
            }
        }

        throw $lastException ?? new AutoCrmApiException("Unknown error after {$this->maxRetries} attempts");
    }

    /**
     * Get autosalons list from AutoCRM (all pages)
     *
     * @return array
     * @throws AutoCrmApiException
     */
    public function getAutosalons(): array
    {
        return $this->getAllPaginated('autosalon');
    }

    /**
     * Create call record in AutoCRM
     *
     * @param array $data Call data
     * @return array Full API response with 'status' and 'result' keys
     * @throws AutoCrmApiException
     */
    public function createCall(array $data): array
    {
        return $this->request('POST', 'call', $data);
    }

    /**
     * Update call record in AutoCRM
     *
     * @param int $id Call ID in AutoCRM
     * @param array $data Call data to update
     * @return array Full API response with 'status' and 'result' keys
     * @throws AutoCrmApiException
     */
    public function updateCall(int $id, array $data): array
    {
        return $this->request('PUT', "call/{$id}", $data);
    }

    /**
     * Get call record from AutoCRM
     *
     * @param int $id Call ID in AutoCRM
     * @return array Call data
     * @throws AutoCrmApiException
     */
    public function getCall(int $id): array
    {
        $response = $this->request('GET', "call/{$id}");
        return $response['result'] ?? $response;
    }

    /**
     * Test API connection
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $this->getUsers();
            return true;
        } catch (AutoCrmApiException $e) {
            return false;
        }
    }

    /**
     * Get pagination parameter name for endpoint
     *
     * AutoCRM API uses ModelSearch_page format for pagination
     *
     * @param string $endpoint API endpoint
     * @return string Pagination parameter name
     */
    private function getSearchPageParam(string $endpoint): string
    {
        // Map endpoints to their search model names
        $mapping = [
            'user' => 'UserSearch_page',
            'autosalon' => 'AutosalonSearch_page',
            'call' => 'CallSearch_page',
        ];

        // Extract base endpoint (remove any path parameters like /123)
        $baseEndpoint = explode('/', $endpoint)[0];

        return $mapping[$baseEndpoint] ?? ucfirst($baseEndpoint) . 'Search_page';
    }

    /**
     * Make HTTP request to API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array Response data
     * @throws AutoCrmApiException
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $lastException = null;
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            $attempt++;

            try {
                $options = [];
                if (!empty($data)) {
                    $options[RequestOptions::JSON] = $data;
                }

                $response = $this->httpClient->request($method, $endpoint, $options);
                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                $this->logRequest($method, $endpoint, $data, $statusCode, $body);

                // Parse response
                $result = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Include truncated response body for debugging
                    $bodyPreview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
                    throw new AutoCrmApiException(
                        "Invalid JSON response: " . json_last_error_msg() . " | Response: " . $bodyPreview
                    );
                }

                // Check HTTP status
                if ($statusCode >= 400 && $statusCode < 500) {
                    // Client error - don't retry
                    $error = $this->extractError($result);
                    throw new AutoCrmApiException("API client error ({$statusCode}): {$error}", $statusCode);
                }

                if ($statusCode >= 500) {
                    // Server error - retry, include response for debugging
                    $bodyPreview = strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
                    throw new AutoCrmApiException(
                        "API server error ({$statusCode}) | Response: " . $bodyPreview,
                        $statusCode
                    );
                }

                // Check API status (supports both 'status' and 'success' fields)
                $apiSuccess = true;
                if (isset($result['status'])) {
                    $apiSuccess = $result['status'] == 1;
                } elseif (isset($result['success'])) {
                    $apiSuccess = $result['success'] === true;
                }

                if (!$apiSuccess) {
                    $error = $this->extractError($result);
                    throw new AutoCrmApiException("API error: {$error}");
                }

                return $result;

            } catch (ConnectException $e) {
                $lastException = new AutoCrmApiException("Connection error: " . $e->getMessage(), 0, $e);
                $this->logError($method, $endpoint, $lastException->getMessage(), $attempt);

            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;

                // Don't retry client errors
                if ($statusCode >= 400 && $statusCode < 500) {
                    throw new AutoCrmApiException("Request error ({$statusCode}): " . $e->getMessage(), $statusCode, $e);
                }

                $lastException = new AutoCrmApiException("Request error: " . $e->getMessage(), $statusCode, $e);
                $this->logError($method, $endpoint, $lastException->getMessage(), $attempt);

            } catch (AutoCrmApiException $e) {
                // Don't retry client errors (4xx)
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    throw $e;
                }

                $lastException = $e;
                $this->logError($method, $endpoint, $e->getMessage(), $attempt);

            } catch (GuzzleException $e) {
                $lastException = new AutoCrmApiException("HTTP error: " . $e->getMessage(), 0, $e);
                $this->logError($method, $endpoint, $lastException->getMessage(), $attempt);
            }

            // Wait before retry
            if ($attempt < $this->maxRetries) {
                sleep($this->retryDelay * $attempt);
            }
        }

        throw $lastException ?? new AutoCrmApiException("Unknown error after {$this->maxRetries} attempts");
    }

    /**
     * Extract error message from API response
     *
     * @param array $result API response
     * @return string Error message
     */
    private function extractError(array $result): string
    {
        if (isset($result['errors'])) {
            if (is_array($result['errors'])) {
                $messages = [];
                foreach ($result['errors'] as $field => $errors) {
                    if (is_array($errors)) {
                        $messages[] = $field . ': ' . implode(', ', $errors);
                    } else {
                        $messages[] = $errors;
                    }
                }
                return implode('; ', $messages);
            }
            return (string)$result['errors'];
        }

        if (isset($result['message'])) {
            return $result['message'];
        }

        return 'Unknown error';
    }

    /**
     * Log API request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param int $statusCode Response status code
     * @param string $body Response body
     */
    private function logRequest(string $method, string $endpoint, array $data, int $statusCode, string $body): void
    {
        $message = sprintf(
            "AutoCRM API: %s %s -> %d",
            $method,
            $endpoint,
            $statusCode
        );

        if ($statusCode >= 400) {
            SystemMessages::sysLogMsg(__CLASS__, $message . " | Response: " . $body, LOG_WARNING);
        } else {
            SystemMessages::sysLogMsg(__CLASS__, $message, LOG_DEBUG);
        }
    }

    /**
     * Log API error
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param string $error Error message
     * @param int $attempt Attempt number
     */
    private function logError(string $method, string $endpoint, string $error, int $attempt): void
    {
        $message = sprintf(
            "AutoCRM API error (attempt %d/%d): %s %s -> %s",
            $attempt,
            $this->maxRetries,
            $method,
            $endpoint,
            $error
        );
        SystemMessages::sysLogMsg(__CLASS__, $message, LOG_ERR);
    }

    /**
     * Get API URL
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Check if client is configured
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiUrl) && !empty($this->apiToken);
    }
}
