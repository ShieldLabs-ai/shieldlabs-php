<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ShieldLabs\Internal\ConfiguredClient;

/**
 * PSR-18 test double: answers from a queue (or one repeated answer) and records
 * every request.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface> */
    private array $queue = [];

    /** @var ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface|null */
    private ResponseInterface|\Throwable|\Closure|null $fallback = null;

    /** @var (\Closure(): void)|null */
    public ?\Closure $onRequest = null;

    /** @var list<float> the timeout of every request sent through {@see timed()} */
    public array $timeouts = [];

    /**
     * @param ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface ...$answers
     */
    public function queue(ResponseInterface|\Throwable|\Closure ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->queue[] = $answer;
        }

        return $this;
    }

    /**
     * @param ResponseInterface|\Throwable|\Closure(RequestInterface): ResponseInterface $answer
     */
    public function always(ResponseInterface|\Throwable|\Closure $answer): self
    {
        $this->fallback = $answer;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->onRequest !== null) {
            ($this->onRequest)();
        }
        $answer = array_shift($this->queue) ?? $this->fallback;
        if ($answer === null) {
            throw new \LogicException('MockHttpClient has no answer for ' . $request->getUri());
        }
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        if ($answer instanceof \Closure) {
            return $answer($request);
        }

        return $answer;
    }

    /**
     * This mock behind a client whose timeout the SDK can lower for one attempt, like
     * the clients it creates itself. Every request records its timeout in $timeouts.
     */
    public function timed(float $timeout): ConfiguredClient
    {
        $mock = $this;

        return new ConfiguredClient(
            static fn(float $seconds): ClientInterface => new class ($mock, $seconds) implements ClientInterface {
                public function __construct(private readonly MockHttpClient $mock, private readonly float $seconds) {}

                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    $this->mock->timeouts[] = $this->seconds;

                    return $this->mock->sendRequest($request);
                }
            },
            $timeout,
        );
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent');
        }

        return $last;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function text(int $status, string $body, ?string $contentType = null, array $headers = []): ResponseInterface
    {
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return new Response($status, $headers, $body);
    }

    public static function emptyPage(): ResponseInterface
    {
        return self::json(200, ['data' => [], 'total' => 0]);
    }
}
