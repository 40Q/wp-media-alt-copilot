<?php

namespace FortyQ\MediaAltSuggester\Clients;

class AiClientFactory
{
    protected array $clients = [];

    public function __construct(protected array $config = [])
    {
    }

    public function driver(string $name): AiClientInterface
    {
        if (isset($this->clients[$name])) {
            return $this->clients[$name];
        }

        if (!isset($this->config[$name])) {
            throw new \InvalidArgumentException("Provider [{$name}] is not configured.");
        }

        $providerConfig = $this->config[$name];
        $driver = $providerConfig['driver'] ?? $name;

        return $this->clients[$name] = match ($driver) {
            'openai' => new OpenAiClient($name, $providerConfig),
            'anthropic' => new AnthropicClient($name, $providerConfig),
            default => throw new \InvalidArgumentException("Driver [{$driver}] is not supported."),
        };
    }
}
