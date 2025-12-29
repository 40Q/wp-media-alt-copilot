<?php

namespace FortyQ\MediaAltSuggester\Admin;

class SettingsPage
{
    public const OPTION_KEY = 'media_alt_suggester_settings';
    public const NONCE_ACTION = 'media_alt_suggester_save';

    public function hooks(bool $registerOptionsPage = true): void
    {
        if ($registerOptionsPage) {
            add_action('admin_menu', [$this, 'registerPage']);
        }
        add_action('admin_post_media_alt_suggester_save', [$this, 'handleSave']);
    }

    public function registerPage(): void
    {
        add_options_page(
            __('AI Alt Suggester', 'media-alt-suggester'),
            __('AI Alt Suggester', 'media-alt-suggester'),
            'manage_options',
            'media-alt-suggester',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'media-alt-suggester'));
        }

        $updated = isset($_GET['updated']) ? sanitize_text_field(wp_unslash($_GET['updated'])) : '';
        if ($updated === 'true') {
            add_settings_error('media-alt-suggester', 'media-alt-suggester-updated', __('Settings updated.', 'media-alt-suggester'), 'updated');
        } elseif ($updated === 'reset') {
            add_settings_error('media-alt-suggester', 'media-alt-suggester-reset', __('Settings reset to defaults.', 'media-alt-suggester'), 'updated');
        }
        settings_errors('media-alt-suggester');

        $settings = $this->getSettings();
        $defaults = $this->defaultSettings();

        $prompt = esc_textarea($settings['custom_instructions'] ?? '');
        $tone = esc_attr($settings['tone'] ?? $defaults['tone']);
        $maxWords = (int) ($settings['max_words'] ?? $defaults['max_words']);
        $visionEnabled = !empty($settings['vision_enabled']);
        $visionMode = esc_attr($settings['vision_mode'] ?? $defaults['vision_mode']);
        $forceVerbatim = !empty($settings['force_verbatim_text']);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Alt Suggester Settings', 'media-alt-suggester'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="media_alt_suggester_save" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="custom_instructions"><?php esc_html_e('Prompt instructions', 'media-alt-suggester'); ?></label></th>
                            <td>
                                <textarea name="custom_instructions" id="custom_instructions" rows="5" class="large-text" placeholder="<?php esc_attr_e('Add extra guidance (tone, accessibility rules, on-image text handling).', 'media-alt-suggester'); ?>"><?php echo $prompt; ?></textarea>
                                <p class="description"><?php esc_html_e('These lines are appended to the system prompt.', 'media-alt-suggester'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tone"><?php esc_html_e('Tone', 'media-alt-suggester'); ?></label></th>
                            <td>
                                <input type="text" name="tone" id="tone" value="<?php echo $tone; ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e('E.g., neutral and descriptive, friendly, authoritative.', 'media-alt-suggester'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="max_words"><?php esc_html_e('Max words', 'media-alt-suggester'); ?></label></th>
                            <td>
                                <input type="number" name="max_words" id="max_words" value="<?php echo $maxWords; ?>" min="5" max="60" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('On-image text (verbatim)', 'media-alt-suggester'); ?></th>
                            <td>
                                <label><input type="checkbox" name="force_verbatim_text" value="1" <?php checked($forceVerbatim); ?> /> <?php esc_html_e('Copy visible on-image text verbatim into the alt text (useful for testing).', 'media-alt-suggester'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Vision', 'media-alt-suggester'); ?></th>
                            <td>
                                <label><input type="checkbox" name="vision_enabled" value="1" <?php checked($visionEnabled); ?> /> <?php esc_html_e('Enable sending the image to the model (vision).', 'media-alt-suggester'); ?></label>
                                <p class="description"><?php esc_html_e('Use base64 for local/private testing, auto/url for production.', 'media-alt-suggester'); ?></p>
                                <select name="vision_mode">
                                    <?php foreach (['auto', 'url', 'base64'] as $mode): ?>
                                        <option value="<?php echo esc_attr($mode); ?>" <?php selected($visionMode, $mode); ?>><?php echo esc_html(ucfirst($mode)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button-primary"><?php esc_html_e('Save changes', 'media-alt-suggester'); ?></button>
                    <button type="submit" name="media_alt_suggester_reset" value="1" class="button"><?php esc_html_e('Restore defaults', 'media-alt-suggester'); ?></button>
                </p>

                <h2><?php esc_html_e('Prompt preview', 'media-alt-suggester'); ?></h2>
                <p class="description"><?php esc_html_e('This shows the system prompt prefix. Attachment data is appended at runtime.', 'media-alt-suggester'); ?></p>
                <textarea readonly rows="10" class="large-text code"><?php echo esc_textarea($this->previewPrompt($settings)); ?></textarea>
            </form>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'media-alt-suggester'));
        }
        check_admin_referer(self::NONCE_ACTION);

        if (!empty($_POST['media_alt_suggester_reset'])) {
            delete_option(self::OPTION_KEY);
            wp_safe_redirect($this->redirectUrl('reset'));
            exit;
        }

        $defaults = $this->defaultSettings();
        $maxWords = isset($_POST['max_words']) ? (int) $_POST['max_words'] : $defaults['max_words'];
        $maxWords = max(5, min(60, $maxWords));

        $visionMode = in_array($_POST['vision_mode'] ?? $defaults['vision_mode'], ['auto', 'url', 'base64'], true)
            ? $_POST['vision_mode']
            : $defaults['vision_mode'];

        $settings = [
            'custom_instructions' => sanitize_textarea_field($_POST['custom_instructions'] ?? ''),
            'tone' => sanitize_text_field($_POST['tone'] ?? $defaults['tone']),
            'max_words' => $maxWords,
            'force_verbatim_text' => !empty($_POST['force_verbatim_text']),
            'vision_enabled' => !empty($_POST['vision_enabled']),
            'vision_mode' => $visionMode,
        ];

        update_option(self::OPTION_KEY, $settings);

        wp_safe_redirect($this->redirectUrl('true'));
        exit;
    }

    protected function redirectUrl(string $status): string
    {
        $referer = wp_get_referer();
        if ($referer && false !== strpos($referer, 'page=40q-autonomy-ai-media-alt-suggester')) {
            return add_query_arg(['updated' => $status], $referer);
        }

        return add_query_arg(['page' => 'media-alt-suggester', 'updated' => $status], admin_url('options-general.php'));
    }

    public function getSettings(): array
    {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args($saved, $this->defaultSettings());
    }

    protected function defaultSettings(): array
    {
        return [
            'custom_instructions' => '',
            'tone' => 'neutral and descriptive',
            'max_words' => 20,
            'force_verbatim_text' => false,
            'vision_enabled' => false,
            'vision_mode' => 'auto',
        ];
    }

    protected function previewPrompt(array $settings): string
    {
        $lines = [
            'You are an assistant that writes concise, accessible alt text that follows the W3C alt decision tree.',
            'If the image appears purely decorative or conveys no meaningful info, return an empty string.',
            'Otherwise, describe the image\u2019s purpose in page context in one sentence, no more than ' . (int) $settings['max_words'] . ' words.',
            !empty($settings['force_verbatim_text'])
                ? 'If on-image text is visible, copy the exact words verbatim into the alt text.'
                : 'If on-image text is visible and relevant, include the key wording concisely.',
            'Do not start with "Image of" or similar, and do not repeat file names, URLs, or camera metadata.',
            'Use a ' . $settings['tone'] . ' tone.',
        ];

        if (!empty($settings['custom_instructions'])) {
            $lines[] = 'Custom instructions: ' . $settings['custom_instructions'];
        }

        return implode("\n", array_filter($lines));
    }
}
