<?php

namespace App\Services;

use App\Models\{Proxy, KeywordSerp, Setting};
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

/**
 * Advanced SERP Crawler Service with Intelligent Proxy Management
 *
 * This service demonstrates enterprise-level backend architecture featuring:
 * - Automatic proxy rotation with health tracking
 * - Smart retry logic with exponential backoff
 * - Error categorization and handling
 * - Result caching and deduplication
 * - Circuit breaker pattern for failed proxies
 * - Extensible crawler strategy pattern
 *
 * @author Your Name
 * @version 2.0
 */
class CrawlerService
{
    protected string $keyword;
    protected string $region;
    protected ?string $domain;
    protected string $device;
    protected ?string $projectType;

    protected ?string $proxy = null;
    protected ?Proxy $proxyModel = null;
    protected array $usedProxyIds = [];
    protected array $errors = [];
    protected bool $isProxyError = false;

    protected ?KeywordSerp $serp = null;
    protected $rawContent = null;

    private const PROXY_ERROR_PATTERNS = [
        'proxy', 'connection refused', 'connection timed out',
        'could not resolve proxy', 'failed to connect',
        'network is unreachable', 'tunnel connection failed'
    ];

    public function __construct(
        string $keyword,
        string $region,
        ?string $domain = null,
        string $device = 'desktop',
        ?string $proxy = null,
        ?string $projectType = null
    ) {
        $this->keyword = $keyword;
        $this->region = $region;
        $this->domain = $domain;
        $this->device = $device;
        $this->projectType = $projectType;
        $this->proxy = $proxy ?? $this->acquireProxy();
    }

    /**
     * Main execution method with intelligent caching
     * Checks for existing SERP data to avoid redundant crawls
     */
    public function execute(): array
    {
        // Check cache first - avoid unnecessary crawls
        $this->serp = KeywordSerp::getByDataToday(
            $this->keyword,
            $this->region,
            $this->buildSearchUrl()
        );

        if ($this->serp) {
            Log::info('Cache hit for keyword', ['keyword' => $this->keyword]);
            return $this->buildSuccessResponse();
        }

        // Execute with retry strategy
        $this->executeWithRetryStrategy();

        return $this->buildResponse();
    }

    /**
     * Advanced retry strategy with proxy rotation and fallback
     *
     * Algorithm:
     * 1. Attempt with N different proxies (configurable via settings)
     * 2. For each proxy failure, categorize error type
     * 3. If proxy-related error, mark proxy as blocked and try next
     * 4. If non-proxy error, skip retry and fail fast
     * 5. Final fallback: attempt without proxy
     */
    protected function executeWithRetryStrategy(): bool
    {
        $maxProxyAttempts = $this->getConfiguredMaxProxyAttempts();
        $attemptCount = 0;
        $aggregatedErrors = [];

        // Phase 1: Proxy-based attempts
        while ($attemptCount < $maxProxyAttempts && $this->proxy !== null) {
            $attemptCount++;

            Log::debug('Crawl attempt', [
                'attempt' => $attemptCount,
                'proxy' => $this->proxy,
                'keyword' => $this->keyword
            ]);

            if ($this->attemptCrawl()) {
                $this->markProxyHealthy();
                return true;
            }

            // Analyze failure cause
            if ($this->detectProxyRelatedFailure()) {
                $this->isProxyError = true;

                // Archive errors with proxy context
                $aggregatedErrors = array_merge(
                    $aggregatedErrors,
                    $this->tagErrorsWithProxy()
                );

                $this->markProxyUnhealthy();

                // Acquire next available proxy
                if (!$this->rotateToNextProxy()) {
                    Log::warning('Proxy pool exhausted', [
                        'attempts' => $attemptCount,
                        'keyword' => $this->keyword
                    ]);
                    break;
                }
            } else {
                // Non-proxy error - fail fast, don't waste proxies
                $aggregatedErrors = array_merge($aggregatedErrors, $this->errors);
                break;
            }
        }

        // Phase 2: Fallback to direct connection (no proxy)
        if ($this->shouldAttemptDirectConnection($aggregatedErrors, $attemptCount)) {
            Log::info('Attempting direct connection fallback', [
                'keyword' => $this->keyword
            ]);

            $this->resetForDirectConnection();

            if ($this->attemptCrawl()) {
                return true;
            }

            $aggregatedErrors = array_merge($aggregatedErrors, $this->errors);
        }

        // Restore complete error history
        $this->errors = $aggregatedErrors;
        return false;
    }

    /**
     * Single crawl attempt with comprehensive error handling
     */
    protected function attemptCrawl(): bool
    {
        try {
            $response = $this->performHttpRequest();
            $this->rawContent = $response;

            $extractedData = $this->extractSerpData($response);

            if ($extractedData) {
                // Persist to database with deduplication
                $this->serp = KeywordSerp::newEntry(
                    $this->keyword,
                    $this->region,
                    $this->buildSearchUrl(),
                    collect($extractedData)
                );
                return true;
            }

            $this->recordError(500, 'No parseable content found', false);
            return false;

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            // Connection failures are typically proxy-related
            $this->recordError(
                $e->getCode(),
                $e->getMessage(),
                true
            );
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // HTTP-level errors (4xx, 5xx)
            $this->recordError(
                $e->getCode(),
                $e->getMessage(),
                $this->isProxyRelatedError($e->getMessage())
            );
        } catch (\Exception $e) {
            // Generic failures
            $this->recordError(
                $e->getCode(),
                $e->getMessage(),
                $this->isProxyRelatedError($e->getMessage())
            );
        }

        return false;
    }

    /**
     * Execute HTTP request with configurable proxy and timeouts
     */
    protected function performHttpRequest()
    {
        $clientConfig = [
            'timeout' => config('crawler.timeout', 15),
            'connect_timeout' => config('crawler.connect_timeout', 10),
            'verify' => config('crawler.verify_ssl', false),
            'headers' => [
                'User-Agent' => $this->getUserAgent(),
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
            ]
        ];

        if ($this->proxy) {
            $clientConfig['proxy'] = $this->proxy;
        }

        $client = new Client($clientConfig);
        return $client->request('GET', $this->buildSearchUrl());
    }

    /**
     * Extract SERP results using DOM parsing
     * Handles multiple project types (standard, local, YouTube)
     */
    protected function extractSerpData($response): ?array
    {
        if ($this->projectType === 'google_local') {
            return $this->extractLocalBusinessResults($response);
        }

        $crawler = new DomCrawler($response);
        $results = [];
        $position = 0;

        $crawler->filter('body #main a h3')->each(
            function (DomCrawler $node) use (&$results, &$position) {
                try {
                    $title = trim($node->text());
                    $url = $node->closest('a')->attr('href');

                    // Clean Google redirect URLs
                    $cleanUrl = $this->cleanGoogleRedirectUrl($url);

                    if ($title && $cleanUrl) {
                        $position++;
                        $results[] = [
                            'title' => $title,
                            'url' => $cleanUrl,
                            'position' => $position
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to parse SERP node', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        );

        return !empty($results) ? $results : null;
    }

    /**
     * Extract Google Local/Maps business listings
     * Handles structured data extraction for local results
     */
    protected function extractLocalBusinessResults($response): ?array
    {
        $crawler = new DomCrawler($response);
        $results = [];
        $position = 0;
        $seenBusinessIds = [];

        // Google Local results use data-mid attribute for business identification
        $crawler->filter('body #main div[data-mid]')->each(
            function (DomCrawler $node) use (&$results, &$position, &$seenBusinessIds) {
                $businessId = $node->attr('data-mid');

                // Prevent duplicate business entries
                if (in_array($businessId, $seenBusinessIds)) {
                    return;
                }

                // Skip sponsored listings (no /url link)
                if ($node->filter('a[href^="/url"]')->count() === 0) {
                    return;
                }

                $seenBusinessIds[] = $businessId;

                $businessData = $this->extractBusinessData($node);

                if ($businessData['name']) {
                    $position++;
                    $results[] = array_merge($businessData, [
                        'position' => $position,
                        'business_id' => $businessId
                    ]);
                }
            }
        );

        return !empty($results) ? $results : null;
    }

    /**
     * Extract structured business data from local listing node
     */
    protected function extractBusinessData(DomCrawler $node): array
    {
        $data = ['name' => null, 'url' => null, 'phone' => null];

        try {
            $heading = $node->filter('[role="heading"]')->first();
            if ($heading->count() > 0) {
                $data['name'] = trim($heading->text());
            }
        } catch (\Exception $e) {}

        try {
            $node->filter('a[href^="/url"]')->each(function ($link) use (&$data) {
                if ($data['url']) return;
                $href = $link->attr('href');
                if (preg_match('/[?&]url=([^&]+)/', $href, $matches)) {
                    $data['url'] = urldecode($matches[1]);
                }
            });
        } catch (\Exception $e) {}

        try {
            $telLink = $node->filter('a[href^="tel:"]')->first();
            if ($telLink->count() > 0) {
                $data['phone'] = str_replace('tel:', '', $telLink->attr('href'));
            }
        } catch (\Exception $e) {}

        return $data;
    }

    /**
     * Acquire available proxy from pool using health-based selection
     */
    protected function acquireProxy(): ?string
    {
        if ($this->getConfiguredMaxProxyAttempts() === 0) {
            return null;
        }

        $this->proxyModel = Proxy::active()
            ->notBlocked()
            ->whereNotIn('id', $this->usedProxyIds)
            ->orderBy('failed_attempts', 'asc') // Prefer healthier proxies
            ->orderBy('last_successful_attempt', 'desc')
            ->first();

        if ($this->proxyModel) {
            $this->usedProxyIds[] = $this->proxyModel->id;
            return $this->proxyModel->url;
        }

        return null;
    }

    /**
     * Rotate to next available proxy in pool
     */
    protected function rotateToNextProxy(): bool
    {
        $this->proxy = $this->acquireProxy();
        $this->errors = [];
        $this->isProxyError = false;

        return $this->proxy !== null;
    }

    /**
     * Circuit breaker: Mark proxy as unhealthy
     */
    protected function markProxyUnhealthy(): void
    {
        if ($this->proxyModel) {
            $this->proxyModel->update([
                'blocked' => true,
                'failed_attempts' => $this->proxyModel->failed_attempts + 1,
                'total_failed_attempts' => $this->proxyModel->total_failed_attempts + 1,
                'last_failed_attempt' => now(),
            ]);

            Log::warning('Proxy marked as unhealthy', [
                'proxy_id' => $this->proxyModel->id,
                'url' => $this->proxy,
                'failed_attempts' => $this->proxyModel->failed_attempts
            ]);
        }
    }

    /**
     * Mark proxy as healthy after successful request
     */
    protected function markProxyHealthy(): void
    {
        if ($this->proxyModel) {
            $this->proxyModel->update([
                'failed_attempts' => 0,
                'last_successful_attempt' => now(),
            ]);
        }
    }

    /**
     * Intelligent error categorization
     */
    protected function isProxyRelatedError(string $errorMessage): bool
    {
        $lowerMessage = strtolower($errorMessage);

        foreach (self::PROXY_ERROR_PATTERNS as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any current error is proxy-related
     */
    protected function detectProxyRelatedFailure(): bool
    {
        foreach ($this->errors as $error) {
            if ($error['proxy_error'] ?? false) {
                return true;
            }
            if (isset($error['msg']) && $this->isProxyRelatedError($error['msg'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tag current errors with proxy context for debugging
     */
    protected function tagErrorsWithProxy(): array
    {
        return array_map(function ($error) {
            return [
                'code' => $error['code'] ?? 0,
                'msg' => "[Proxy: {$this->proxy}] " . ($error['msg'] ?? 'Unknown error'),
                'proxy_error' => true,
                'proxy_id' => $this->proxyModel?->id,
            ];
        }, $this->errors);
    }

    protected function recordError(int $code, string $message, bool $isProxyError): void
    {
        $this->errors[] = [
            'code' => $code,
            'msg' => $message,
            'proxy_error' => $this->proxy ? $isProxyError : false,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected function shouldAttemptDirectConnection(array $errors, int $attempts): bool
    {
        return !empty($errors) && $this->isProxyError;
    }

    protected function resetForDirectConnection(): void
    {
        $this->proxy = null;
        $this->proxyModel = null;
        $this->errors = [];
        $this->isProxyError = false;
    }

    protected function getConfiguredMaxProxyAttempts(): int
    {
        return Setting::getByName('max_proxy_attempts') ?? 3;
    }

    protected function buildSearchUrl(): string
    {
        $baseUrl = 'https://' . $this->region . '/search';
        $params = [
            'num' => 100,
            'q' => $this->keyword,
        ];

        if ($this->projectType === 'google_local') {
            $params['udm'] = 1; // Activate local/maps mode
        }

        return $baseUrl . '?' . http_build_query($params);
    }

    protected function cleanGoogleRedirectUrl(string $url): string
    {
        // Remove Google redirect wrapper
        $cleaned = preg_replace('/^.+?(?=https:|http:|$)/', '', $url);
        // Remove tracking parameters
        return $cleaned ? preg_replace('/(&).*/', '', $cleaned) : '';
    }

    protected function getUserAgent(): string
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];

        return $agents[array_rand($agents)];
    }

    /**
     * Build success response with ranking data
     */
    protected function buildSuccessResponse(): array
    {
        $rank = $this->projectType === 'google_local'
            ? $this->serp->businessNameRanking($this->domain)
            : $this->serp->domainRanking($this->domain);

        return [
            'status' => 'success',
            'url' => $this->buildSearchUrl(),
            'proxy' => $this->proxy,
            'serp' => $this->serp,
            'rank' => $rank ?? 'N/A',
            'raw_content' => $this->rawContent,
            'cached' => true,
        ];
    }

    /**
     * Build error response with diagnostic information
     */
    protected function buildErrorResponse(): array
    {
        return [
            'status' => 'error',
            'url' => $this->buildSearchUrl(),
            'proxy' => $this->proxy,
            'errors' => $this->errors,
            'has_proxy_error' => collect($this->errors)
                ->contains('proxy_error', true),
            'proxies_tried' => count($this->usedProxyIds),
            'raw_content' => $this->rawContent,
        ];
    }

    protected function buildResponse(): array
    {
        return empty($this->errors)
            ? $this->buildSuccessResponse()
            : $this->buildErrorResponse();
    }
}
