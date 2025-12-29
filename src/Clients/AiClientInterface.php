<?php

namespace FortyQ\MediaAltSuggester\Clients;

interface AiClientInterface
{
    public function generateAltText(string $prompt, array $options = []): string;

    public function getName(): string;
}
