<?php

namespace FortyQ\MediaAltSuggester\Clients;

class AnthropicClient extends BaseClient
{
    public function generateAltText(string $prompt, array $options = []): string
    {
        $apiKey = $this->getConfig('api_key');
        if (!$apiKey) {
            throw new \RuntimeException('Anthropic API key is missing.');
        }

        $endpoint = $this->getConfig('endpoint', 'https://api.anthropic.com/v1/messages');
        $model = $this->getConfig('model', 'claude-3-5-sonnet-latest');

        return $this->withExceptionHandling(function () use ($endpoint, $apiKey, $model, $prompt, $options) {
            $response = $this->http->post($endpoint, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'max_tokens' => $options['max_tokens'] ?? $this->getConfig('max_tokens', 180),
                    'system' => 'You write concise, accessible alt text. Keep it short.',
                    'messages' => [
                        ['role' => 'user', 'content' => $this->buildContent($prompt, $options['image'] ?? null)],
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return trim($data['content'][0]['text'] ?? '');
        });
    }

    protected function buildContent(string $prompt, ?array $image = null): array
    {
        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];

        if ($image) {
            if ($image['type'] === 'base64') {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'image/jpeg',
                        'data' => str_replace(['data:image/jpeg;base64,', 'data:image/png;base64,'], '', $image['value']),
                    ],
                ];
            } elseif ($image['type'] === 'url') {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'url',
                        'url' => $image['value'],
                    ],
                ];
            }
        }

        return $content;
    }
}
