<?php

namespace FortyQ\MediaAltSuggester\Clients;

class OpenAiClient extends BaseClient
{
    public function generateAltText(string $prompt, array $options = []): string
    {
        $apiKey = $this->getConfig('api_key');
        if (!$apiKey) {
            throw new \RuntimeException('OpenAI API key is missing.');
        }

        $endpoint = $this->getConfig('endpoint', 'https://api.openai.com/v1/chat/completions');
        $model = $this->getConfig('model', 'gpt-4o-mini');

        return $this->withExceptionHandling(function () use ($endpoint, $apiKey, $model, $prompt, $options) {
            $response = $this->http->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $this->buildMessages($prompt, $options['image'] ?? null),
                    'max_tokens' => $options['max_tokens'] ?? $this->getConfig('max_tokens', 120),
                    'temperature' => 0.4,
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return trim($data['choices'][0]['message']['content'] ?? '');
        });
    }

    protected function buildMessages(string $prompt, ?array $image = null): array
    {
        if (!$image) {
            return [
                ['role' => 'system', 'content' => 'You write concise, accessible alt text.'],
                ['role' => 'user', 'content' => $prompt],
            ];
        }

        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];

        if ($image['type'] === 'base64') {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $image['value'],
                    'detail' => 'auto',
                ],
            ];
        } elseif ($image['type'] === 'url') {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $image['value'],
                    'detail' => 'auto',
                ],
            ];
        }

        return [
            ['role' => 'system', 'content' => 'You write concise, accessible alt text.'],
            ['role' => 'user', 'content' => $content],
        ];
    }
}
