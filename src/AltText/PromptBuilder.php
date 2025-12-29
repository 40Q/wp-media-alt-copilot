<?php

namespace FortyQ\MediaAltSuggester\AltText;

class PromptBuilder
{
    public function build(array $context, array $config): string
    {
        $tone = $config['prompt']['tone'] ?? 'neutral and descriptive';
        $maxWords = $config['prompt']['max_words'] ?? 20;

        $parentText = $context['parent']
            ? sprintf(
                "Related content (%s): %s. Summary: %s.",
                $context['parent']['type'],
                $context['parent']['title'],
                $context['parent']['excerpt']
            )
            : 'No related post context was provided.';

        $lines = [
            'You are an assistant that writes concise, accessible alt text that follows the W3C alt decision tree.',
            'If the image appears purely decorative or conveys no meaningful info, return an empty string.',
            'Otherwise, describe the image\u2019s purpose in page context in one sentence, no more than ' . (int) $maxWords . ' words.',
            $forceVerbatim
                ? 'If on-image text is visible, copy the exact words verbatim into the alt text (including headings, labels, CTAs, and prominent body copy). If multiple fragments are visible, include them all; if none, state that no on-image text was detected.'
                : 'If on-image text is visible and relevant, include the key wording concisely in the alt text.',
            'Do not start with "Image of" or similar, and do not repeat file names, URLs, or camera metadata.',
            'Use a ' . $tone . ' tone.',
        ];

        if ($custom !== '') {
            $lines[] = 'Custom instructions: ' . $custom;
        }

        $lines = array_filter($lines);

        $lines[] = '';
        $lines[] = 'Attachment data:';
        $lines[] = 'Title: ' . ($context['title'] ?: 'N/A');
        $lines[] = 'Caption: ' . ($context['caption'] ?: 'N/A');
        $lines[] = 'Description: ' . ($context['description'] ?: 'N/A');
        $lines[] = 'Existing alt (if any): ' . ($context['existing_alt'] ?: 'N/A');
        $lines[] = 'File name: ' . ($context['filename'] ?: 'N/A');
        $lines[] = 'Mime type: ' . ($context['mime_type'] ?: 'N/A');
        $lines[] = $parentText;
        $lines[] = 'If an image is provided, use it to improve accuracy.';

        return implode("\n", $lines);
    }
}
