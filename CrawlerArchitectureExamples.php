<?php

/**
 * =============================================================================
 * COMPLEX LARAVEL BACKEND ARCHITECTURE - USAGE EXAMPLES
 * =============================================================================
 *
 * This file demonstrates enterprise-level design patterns used in production:
 * - Factory Pattern for object creation
 * - Queue Jobs for async processing
 * - Service Layer architecture
 * - Repository Pattern
 * - Event-driven webhooks/postbacks
 * - Comprehensive API logging
 *
 * @author Your Name
 * =============================================================================
 */

namespace App\Services;

use App\Models\Setting;

/**
 * Factory Pattern: Dynamic Crawler Instantiation
 *
 * Responsibilities:
 * - Abstract crawler creation logic
 * - Support multiple crawler implementations
 * - Enable runtime strategy selection based on configuration
 */
class CrawlerFactory
{
    /**
     * Create appropriate crawler instance based on project type and settings
     *
     * This demonstrates the Factory + Strategy patterns working together
     */
    public static function create(
        string $keyword,
        string $region,
        ?string $domain = null,
        string $device = 'desktop',
        $proxy = null,
        int $maxAttempts = 5,
        ?string $projectType = null
    ): CrawlerService {
        // Runtime strategy selection based on settings
        $crawlerType = self::resolveCrawlerStrategy($projectType);

        // Strategy pattern: select implementation
        return match ($crawlerType) {
            'panther' => self::createPantherCrawler(
                $keyword, $region, $domain, $device, $proxy, $maxAttempts, $projectType
            ),
            'scrapingrobot' => self::createScrapingRobotCrawler(
                $keyword, $region, $domain, $device, $proxy, $maxAttempts, $projectType
            ),
            default => new CrawlerService(
                $keyword, $region, $domain, $device, $proxy, $projectType
            ),
        };
    }

    /**
     * Resolve crawler strategy from database settings
     */
    protected static function resolveCrawlerStrategy(?string $projectType): string
    {
        if (!$projectType) {
            return Setting::getByName('google_crawler') ?? 'scrapingrobot';
        }

        $settingKey = match ($projectType) {
            'youtube' => 'youtube_crawler',
            'google_local' => 'google_local_crawler',
            default => 'google_crawler',
        };

        return Setting::getByName($settingKey) ?? 'scrapingrobot';
    }

    protected static function createPantherCrawler(...$args): CrawlerService
    {
        // Could instantiate specialized PantherCrawler here
        return new CrawlerService(...$args);
    }

    protected static function createScrapingRobotCrawler(...$args): CrawlerService
    {
        // Could instantiate specialized ScrapingRobotCrawler here
        return new CrawlerService(...$args);
    }

    public static function getAvailableStrategies(): array
    {
        return [
            'scrapingrobot' => 'Scraping Robot API',
            'panther' => 'Panther Headless Browser (Free)',
        ];
    }
}

// =============================================================================

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\{CrawlerFactory, PostbackService};
use App\Models\{Ranking, ApiRequest, Setting};
use Illuminate\Support\Facades\Log;

/**
 * Queue Job: Asynchronous Ranking Processing
 *
 * This demonstrates:
 * - Queue-based job processing for scalability
 * - Separation of concerns (controller dispatches, job executes)
 * - Webhook/postback integration
 * - Comprehensive error handling and logging
 */
class ProcessRankingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [30, 60, 120]; // Exponential backoff

    protected Ranking $ranking;
    protected ?string $postbackUrl;
    protected ?string $externalId;
    protected ?int $apiRequestId;

    public function __construct(
        Ranking $ranking,
        ?string $externalId = null,
        ?string $postbackUrl = null,
        ?int $apiRequestId = null
    ) {
        $this->ranking = $ranking;
        $this->postbackUrl = $postbackUrl;
        $this->externalId = $externalId;
        $this->apiRequestId = $apiRequestId;
    }

    /**
     * Execute the job - main business logic
     */
    public function handle(): void
    {
        Log::info('Processing ranking job', [
            'ranking_id' => $this->ranking->id,
            'keyword' => $this->ranking->keyword,
        ]);

        // Use Factory to create appropriate crawler
        $crawler = CrawlerFactory::create(
            keyword: $this->ranking->keyword,
            region: $this->ranking->search_engine,
            domain: $this->ranking->domain,
            device: $this->ranking->device ?? 'desktop',
            proxy: null, // Auto-select from pool
            maxAttempts: 5,
            projectType: $this->ranking->project_type
        );

        $result = $crawler->execute();

        if ($result['status'] === 'success') {
            $this->handleSuccess($result);
        } else {
            $this->handleFailure($result);
        }
    }

    /**
     * Handle successful crawl result
     */
    protected function handleSuccess(array $result): void
    {
        $position = $result['rank']['position'] ?? null;
        $url = $result['rank']['url'] ?? null;
        $topSerp = $this->extractTopSerp($result);

        // Update ranking model
        $this->ranking->updateData($position, $url);

        // Send webhook notification if configured
        if ($this->postbackUrl && $this->externalId) {
            $this->sendPostback($this->ranking->keyword, $position, $url, $topSerp, 'success');
        }

        // Log to API request tracking
        if ($this->apiRequestId && Setting::isApiRequestLoggingEnabled()) {
            $this->logCrawlerResult($result, $position, $url, $topSerp);
        }

        Log::info('Ranking processed successfully', [
            'keyword' => $this->ranking->keyword,
            'position' => $position,
        ]);
    }

    /**
     * Handle crawl failure
     */
    protected function handleFailure(array $result): void
    {
        Log::error('Ranking processing failed', [
            'keyword' => $this->ranking->keyword,
            'errors' => $result['errors'] ?? 'Unknown error',
            'has_proxy_error' => $result['has_proxy_error'] ?? false,
            'proxies_tried' => $result['proxies_tried'] ?? 0,
        ]);

        // Send error notification webhook
        if ($this->postbackUrl && $this->externalId) {
            $this->sendPostback($this->ranking->keyword, null, null, null, 'error');
        }

        // Log error details
        if ($this->apiRequestId && Setting::isApiRequestLoggingEnabled()) {
            $this->logCrawlerResult($result, null, null, null);
        }
    }

    /**
     * Event-driven webhook notification
     */
    protected function sendPostback(
        string $keyword,
        ?int $position,
        ?string $url,
        ?string $topSerp,
        string $status
    ): void {
        (new PostbackService($this->postbackUrl, $this->externalId))
            ->send($keyword, $position, $url, $topSerp, $status);
    }

    /**
     * Comprehensive API request logging for debugging and analytics
     */
    protected function logCrawlerResult(
        array $result,
        ?int $position,
        ?string $url,
        ?string $topSerp
    ): void {
        try {
            $apiRequest = ApiRequest::find($this->apiRequestId);
            if (!$apiRequest) return;

            $crawlerResults = $apiRequest->crawler_result ?? [];

            $logEntry = [
                'keyword' => $this->ranking->keyword,
                'status' => $result['status'],
                'position' => $position,
                'url' => $url,
                'proxy' => $result['proxy'] ?? null,
                'timestamp' => now()->toIso8601String(),
            ];

            // Include SERP data (limited to prevent DB bloat)
            if (isset($result['serp']->result)) {
                $logEntry['top_serp'] = collect($result['serp']->result)
                    ->take(10)
                    ->toArray();
            }

            // Save raw HTML to file system (not DB)
            if (isset($result['raw_content'])) {
                $logEntry['raw_content_file'] = $this->saveRawContentToFile(
                    $result['raw_content']
                );
            }

            // Include error details if failed
            if ($result['status'] === 'error') {
                $logEntry['errors'] = $result['errors'];
                $logEntry['has_proxy_error'] = $result['has_proxy_error'] ?? false;
                $logEntry['proxies_tried'] = $result['proxies_tried'] ?? 0;
            }

            $crawlerResults[] = $logEntry;

            $apiRequest->update(['crawler_result' => $crawlerResults]);

        } catch (\Exception $e) {
            Log::error('Failed to log crawler result', [
                'api_request_id' => $this->apiRequestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store raw HTML responses to filesystem for debugging
     */
    protected function saveRawContentToFile($rawContent): ?string
    {
        try {
            $content = is_string($rawContent)
                ? $rawContent
                : (method_exists($rawContent, 'getBody')
                    ? (string) $rawContent->getBody()
                    : (string) $rawContent);

            $directory = storage_path('app/crawler-results');
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $filename = $this->apiRequestId . '_' . time() . '.html';
            $filepath = $directory . '/' . $filename;

            file_put_contents($filepath, $content);

            return 'crawler-results/' . $filename;

        } catch (\Exception $e) {
            Log::warning('Failed to save raw content', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function extractTopSerp(array $result): ?string
    {
        if (!isset($result['serp']->result)) {
            return null;
        }

        return json_encode(
            collect($result['serp']->result)->take(10)
        );
    }

    /**
     * Handle job failure after all retries exhausted
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Ranking job failed after all retries', [
            'ranking_id' => $this->ranking->id,
            'keyword' => $this->ranking->keyword,
            'exception' => $exception->getMessage(),
        ]);

        // Could trigger alerts here (email, Slack, etc.)
    }
}

// =============================================================================

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\{Request, JsonResponse};
use App\Http\Requests\RankingRequest;
use App\Models\{Ranking, ApiRequest, Setting, DomainParser};
use App\Jobs\ProcessRankingJob;
use Illuminate\Support\Facades\Log;

/**
 * RESTful API Controller for Ranking Requests
 *
 * Demonstrates:
 * - Request validation via Form Requests
 * - API authentication
 * - Comprehensive request/response logging
 * - Queue job dispatching with priority queues
 * - Domain validation
 */
class RankingApiController extends Controller
{
    /**
     * Create ranking request(s)
     *
     * POST /api/v1/rankings
     */
    public function store(RankingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->authenticateApiRequest($request->api_key);

        if (!$user) {
            return $this->errorResponse('Invalid API key', 401);
        }

        // Log API request for analytics and debugging
        $apiRequest = $this->logApiRequest($request, $user, $validated);

        // Validate domain (skip for certain project types)
        if (!$this->shouldSkipDomainValidation($validated['project_type'] ?? null)) {
            if (!$this->isValidDomain($validated['domain'])) {
                $this->updateApiRequestLog($apiRequest, 'error', 'Invalid domain', 400);
                return $this->errorResponse('Invalid domain', 400);
            }
        }

        // Process each keyword
        $rankings = $this->processKeywords($validated, $apiRequest);

        $this->updateApiRequestLog($apiRequest, 'success', $rankings, 200);

        return $this->successResponse($rankings);
    }

    /**
     * Process multiple keywords - dispatch jobs or return cached results
     */
    protected function processKeywords(array $data, ?ApiRequest $apiRequest): array
    {
        $results = [
            'params' => [
                'type' => 'rank_request',
                'domain' => $data['domain'],
                'search_engine' => $data['search_engine'],
                'project_type' => $data['project_type'] ?? 'standard',
            ],
            'rankings' => [],
        ];

        $queueType = $data['priority'] ?? 'default';

        foreach ($data['keywords'] as $keyword) {
            $ranking = Ranking::newEntry(
                $data['domain'],
                $data['search_engine'],
                $keyword
            );

            $ranking->update([
                'device' => $data['device'] ?? 'desktop',
                'project_type' => $data['project_type'] ?? null,
            ]);

            // Check if we have fresh data from today
            if ($this->hasFreshData($ranking)) {
                $results['rankings'][] = $this->returnCachedRanking(
                    $ranking,
                    $keyword,
                    $data,
                    $apiRequest
                );
            } else {
                // Dispatch to queue for processing
                ProcessRankingJob::dispatch(
                    $ranking,
                    $data['external_id'] ?? null,
                    $data['postback_url'] ?? null,
                    $apiRequest?->id
                )->onQueue($queueType);

                $results['rankings'][] = [
                    'keyword' => $keyword,
                    'status' => 'processing',
                    'message' => 'Job queued successfully',
                ];

                Log::info('Ranking job queued', [
                    'keyword' => $keyword,
                    'queue' => $queueType,
                ]);
            }
        }

        return $results;
    }

    /**
     * Return cached ranking data (today's results)
     */
    protected function returnCachedRanking(
        Ranking $ranking,
        string $keyword,
        array $data,
        ?ApiRequest $apiRequest
    ): array {
        $todayRank = collect($ranking->rankings)
            ->where('date', now()->toDateString())
            ->first();

        // Send to postback URL if configured
        if ($data['postback_url'] ?? null && $data['external_id'] ?? null) {
            (new PostbackService($data['postback_url'], $data['external_id']))
                ->send(
                    $keyword,
                    $todayRank['position'] ?? null,
                    $todayRank['result_url'] ?? null
                );
        }

        // Log cached result
        if ($apiRequest) {
            $this->logCachedRankingResult($apiRequest, $keyword, $todayRank);
        }

        return [
            'keyword' => $keyword,
            'position' => $todayRank['position'] ?? null,
            'url' => $todayRank['result_url'] ?? null,
            'status' => 'completed',
            'cached' => true,
        ];
    }

    protected function hasFreshData(Ranking $ranking): bool
    {
        return $ranking->to_date === now()->toDateString();
    }

    protected function shouldSkipDomainValidation(?string $projectType): bool
    {
        return in_array($projectType, ['youtube', 'google_local']);
    }

    protected function isValidDomain(string $domain): bool
    {
        return (new DomainParser())->isIcann($domain);
    }

    /**
     * Comprehensive API request logging
     */
    protected function logApiRequest(Request $request, $user, array $data): ?ApiRequest
    {
        if (!Setting::isApiRequestLoggingEnabled()) {
            return null;
        }

        return ApiRequest::create([
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'api_key' => $request->api_key,
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'request_data' => $data,
            'domain' => $data['domain'] ?? null,
            'project_type' => $data['project_type'] ?? null,
        ]);
    }

    protected function updateApiRequestLog(
        ?ApiRequest $apiRequest,
        string $status,
        $data,
        int $code
    ): void {
        if (!$apiRequest) return;

        $apiRequest->update([
            'response_data' => ['status' => $status, 'data' => $data],
            'response_code' => $code,
        ]);
    }

    protected function logCachedRankingResult(
        ApiRequest $apiRequest,
        string $keyword,
        ?array $rank
    ): void {
        $results = $apiRequest->crawler_result ?? [];
        $results[] = [
            'keyword' => $keyword,
            'status' => 'success',
            'position' => $rank['position'] ?? null,
            'url' => $rank['result_url'] ?? null,
            'timestamp' => now()->toIso8601String(),
            'cached' => true,
        ];
        $apiRequest->update(['crawler_result' => $results]);
    }

    protected function authenticateApiRequest(string $apiKey)
    {
        return \App\Models\User::where('api_key', $apiKey)->first();
    }

    protected function successResponse($data, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $data,
        ], $code);
    }

    protected function errorResponse(string $message, int $code = 400): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $code);
    }
}
