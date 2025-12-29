<?php

namespace FortyQ\MediaAltSuggester\AltText;

use FortyQ\MediaAltSuggester\Clients\AiClientFactory;
use FortyQ\MediaAltSuggester\Clients\AiClientInterface;
use Illuminate\Support\Str;

class AltTextManager
{
    public function __construct(
        protected AiClientFactory $clients,
        protected PromptBuilder $promptBuilder,
        protected array $config = []
    ) {
    }

    public function suggest(int $attachmentId, ?string $provider = null): array
    {
        $attachment = get_post($attachmentId);
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            throw new \InvalidArgumentException('Invalid attachment ID.');
        }

        $context = $this->buildContext($attachment);
        $client = $this->resolveClient($provider);
        $prompt = $this->promptBuilder->build($context, $this->config);
        $image = $this->resolveImageSource($context);

        $suggestion = $client->generateAltText($prompt, [
            'max_tokens' => $this->config['providers'][$client->getName()]['max_tokens'] ?? null,
            'image' => $image,
        ]);

        $suggestion = $this->truncateSuggestion($suggestion);

        return [
            'suggestion' => $suggestion,
            'provider' => $client->getName(),
            'prompt' => $prompt,
            'image_mode' => $image['type'] ?? 'none',
        ];
    }

    protected function buildContext(\WP_Post $attachment): array
    {
        $parent = $attachment->post_parent ? get_post($attachment->post_parent) : null;
        $title = get_the_title($attachment);
        $caption = wp_get_attachment_caption($attachment->ID);
        $description = $attachment->post_content;
        $alt = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);

        $filename = basename(get_attached_file($attachment->ID) ?: $attachment->guid);
        $shortFilename = preg_replace('/\\.[^.]+$/', '', $filename);

        $parentSummary = $parent ? $this->summarizePost($parent) : null;

        return [
            'attachment_id' => $attachment->ID,
            'title' => $title,
            'caption' => $caption,
            'description' => $description,
            'existing_alt' => $alt,
            'filename' => $filename,
            'short_filename' => $shortFilename,
            'parent' => $parentSummary,
            'mime_type' => get_post_mime_type($attachment),
            'url' => wp_get_attachment_url($attachment->ID),
            'file_path' => get_attached_file($attachment->ID) ?: null,
        ];
    }

    protected function summarizePost(\WP_Post $post): array
    {
        $content = wp_strip_all_tags($post->post_content);
        $excerpt = $post->post_excerpt ?: wp_trim_words($content, 30, '…');

        return [
            'id' => $post->ID,
            'type' => $post->post_type,
            'title' => get_the_title($post),
            'excerpt' => $excerpt,
            'permalink' => get_permalink($post),
        ];
    }

    protected function resolveClient(?string $provider): AiClientInterface
    {
        $driver = $provider ?: $this->config['default_provider'] ?? 'openai';

        return $this->clients->driver($driver);
    }

    protected function truncateSuggestion(string $suggestion): string
    {
        $suggestion = trim($suggestion);
        $suggestion = preg_replace('/^(image of|photo of|picture of)\\s*/i', '', $suggestion);

        $maxWords = (int) ($this->config['prompt']['max_words'] ?? 20);

        if ($maxWords > 0) {
            $suggestion = Str::words($suggestion, $maxWords, '');
        }

        // Guard against models echoing the file name or URL.
        $suggestion = preg_replace('/https?:\\/\\S+/i', '', $suggestion);
        $suggestion = trim($suggestion, " \t\n\r\0\x0B\"'");

        return $suggestion;
    }

    protected function resolveImageSource(array $context): ?array
    {
        $vision = $this->config['vision'] ?? [];
        if (empty($vision['enabled'])) {
            return null;
        }

        $mode = $vision['mode'] ?? 'auto';
        $urls = $this->candidateUrls($context, $vision);
        $path = $context['file_path'] ?? null;
        $maxBytes = (int) ($vision['max_base64_bytes'] ?? 1500000);

        $canUseUrl = function (string $url) use ($vision): bool {
            if (empty($vision['check_url'])) {
                return true;
            }
            $response = wp_remote_head($url);
            if (is_wp_error($response)) {
                return false;
            }
            $code = wp_remote_retrieve_response_code($response);
            return $code && $code < 400;
        };

        $makeBase64 = function () use ($path, $maxBytes, $context): ?array {
            if (!$path || !file_exists($path)) {
                return null;
            }
            if (filesize($path) > $maxBytes) {
                return null;
            }

            $data = file_get_contents($path);
            if ($data === false) {
                return null;
            }
            $mime = $context['mime_type'] ?? 'application/octet-stream';
            $encoded = base64_encode($data);
            return [
                'type' => 'base64',
                'value' => 'data:' . $mime . ';base64,' . $encoded,
            ];
        };

        $useUrl = function () use ($urls, $canUseUrl): ?array {
            foreach ($urls as $url) {
                if ($canUseUrl($url)) {
                    return ['type' => 'url', 'value' => $url];
                }
            }
            return null;
        };

        return match ($mode) {
            'url' => $useUrl() ?? $makeBase64(),
            'base64' => $makeBase64() ?? $useUrl(),
            default => $useUrl() ?? $makeBase64(),
        };
    }

    protected function candidateUrls(array $context, array $vision): array
    {
        $urls = [];
        $original = $context['url'] ?? null;
        if ($original) {
            $urls[] = $original;
        }

        $publicHost = $vision['public_host'] ?? null;
        if ($publicHost && $original) {
            $parts = wp_parse_url($original);
            if (!empty($parts['host']) && $parts['host'] !== $publicHost) {
                $parts['host'] = $publicHost;
                $parts['scheme'] = $vision['public_scheme'] ?? 'https';
                $rebuilt = $this->buildUrl($parts);
                if ($rebuilt) {
                    $urls[] = $rebuilt;
                }
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    protected function buildUrl(array $parts): ?string
    {
        if (empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return sprintf('%s://%s%s%s', $scheme, $parts['host'], $path, $query);
    }
}
