<?php

namespace FortyQ\MediaAltSuggester\Clients;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;

abstract class BaseClient implements AiClientInterface
{
    protected HttpClient $http;

    public function __construct(
        protected string $name,
        protected array $config = []
    ) {
        $this->http = new HttpClient([
            'timeout' => 20,
        ]);
    }

    public function getName(): string
    {
        return $this->name;
    }

    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    protected function withExceptionHandling(callable $callback): string
    {
        try {
            return $callback();
        } catch (GuzzleException $exception) {
            throw new \RuntimeException($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }
}
