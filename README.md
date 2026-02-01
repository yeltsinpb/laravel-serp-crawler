# Laravel SERP Crawler - Backend Architecture Sample

A production-grade Laravel backend implementation showcasing advanced architectural patterns and best practices.

## Overview

This code demonstrates enterprise-level backend development skills through a Search Engine Results Page (SERP) crawler service with intelligent proxy management, queue-based processing, and comprehensive error handling.

## Key Features

### 1. **Intelligent Proxy Management**
- Automatic proxy rotation with health tracking
- Circuit breaker pattern for failed proxies
- Proxy pool management with success/failure metrics
- Smart fallback to direct connection

### 2. **Advanced Retry Logic**
- Configurable retry attempts with exponential backoff
- Error categorization (proxy vs. non-proxy errors)
- Fail-fast for non-recoverable errors
- Complete error history tracking

### 3. **Queue-Based Architecture**
- Asynchronous job processing with Laravel Queues
- Priority queue support (free, standard, premium)
- Job retry mechanism with configurable attempts
- Graceful failure handling

### 4. **Design Patterns**
- **Factory Pattern**: Dynamic crawler instantiation
- **Strategy Pattern**: Runtime algorithm selection
- **Repository Pattern**: Data access abstraction
- **Service Layer**: Business logic separation

### 5. **Production Features**
- Comprehensive logging and debugging
- API request/response tracking
- Webhook/postback notifications
- Result caching and deduplication
- Raw HTML storage for debugging

## Architecture

```
┌─────────────┐
│   API       │
│ Controller  │
└──────┬──────┘
       │
       ├─► Validation (FormRequest)
       │
       ├─► Authentication
       │
       ├─► Request Logging
       │
       ├─► Dispatch Job ──► Queue ──► Job Worker
       │                              │
       └─► Return Response            │
                                      │
                                      ├─► Factory Pattern
                                      │
                                      ├─► Crawler Service
                                      │   ├─► Proxy Manager
                                      │   ├─► HTTP Client
                                      │   ├─► DOM Parser
                                      │   └─► Error Handler
                                      │
                                      ├─► Database Update
                                      │
                                      └─► Webhook Notification
```

## Code Files

### [CrawlerService.php](CrawlerService.php)
The core crawler service implementing:
- Proxy rotation and health management
- Smart retry strategies
- DOM parsing for SERP extraction
- Support for multiple project types (standard, local, YouTube)
- Error categorization and handling

### [CrawlerArchitectureExamples.php](CrawlerArchitectureExamples.php)
Complete implementation examples:
- **CrawlerFactory**: Factory pattern for object creation
- **ProcessRankingJob**: Queue job with comprehensive error handling
- **RankingApiController**: RESTful API controller with validation

## Technical Highlights

### Error Handling
```php
// Intelligent error categorization
protected function isProxyRelatedError(string $errorMessage): bool
{
    $patterns = ['proxy', 'connection refused', 'timed out'];
    // Pattern matching logic...
}
```

### Circuit Breaker Pattern
```php
// Mark unhealthy proxies to prevent cascading failures
protected function markProxyUnhealthy(): void
{
    $this->proxyModel->update([
        'blocked' => true,
        'failed_attempts' => $this->proxyModel->failed_attempts + 1,
        'last_failed_attempt' => now(),
    ]);
}
```

### Strategy Selection
```php
// Runtime strategy selection based on configuration
$crawlerType = match ($projectType) {
    'youtube' => 'panther',
    'google_local' => Setting::get('local_crawler'),
    default => 'scrapingrobot',
};
```

## Technologies Used

- **PHP 8.1+**: Modern PHP with match expressions, typed properties
- **Laravel 10+**: Framework with Queue, Events, Eloquent ORM
- **GuzzleHTTP**: HTTP client for web requests
- **Symfony DomCrawler**: HTML/XML parsing
- **MySQL**: Relational database for data persistence

## Real-World Applications

This architecture pattern is ideal for:
- Web scraping services
- SEO monitoring tools
- Price comparison platforms
- Data aggregation systems
- API aggregators with multiple providers

## Performance Considerations

- **Caching**: Results cached for 24 hours to reduce redundant requests
- **Queue Workers**: Horizontal scaling via multiple queue workers
- **Proxy Pool**: Distributed load across multiple proxy providers
- **Database Indexing**: Optimized queries with composite indexes

## Best Practices Demonstrated

✅ SOLID principles
✅ Dependency injection
✅ Comprehensive error handling
✅ Extensive logging for debugging
✅ Configuration-driven behavior
✅ Separation of concerns
✅ Testable code structure
✅ Production-ready error recovery

## Based On

This code is extracted and refined from a production web crawler application handling thousands of daily requests across multiple search engines and project types.

---

**Author**: Yeltsin Batiancila
**License**: MIT
**Contact**: yeltsinpb@gmail.com
