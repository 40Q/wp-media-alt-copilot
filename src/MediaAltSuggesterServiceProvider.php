<?php

namespace FortyQ\MediaAltSuggester;

use FortyQ\MediaAltSuggester\AltText\AltTextManager;
use FortyQ\MediaAltSuggester\AltText\PromptBuilder;
use FortyQ\MediaAltSuggester\Clients\AiClientFactory;
use FortyQ\MediaAltSuggester\Admin\SettingsPage;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use WP_REST_Request;

class MediaAltSuggesterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/media-alt.php', 'media-alt');
        $this->applySettingsOverrides();

        $this->app->singleton(AiClientFactory::class, function (Container $app) {
            return new AiClientFactory($app->make('config')->get('media-alt.providers', []));
        });

        $this->app->singleton(AltTextManager::class, function (Container $app) {
            return new AltTextManager(
                $app->make(AiClientFactory::class),
                new PromptBuilder(),
                $app->make('config')->get('media-alt', [])
            );
        });

        $this->publishes([
            __DIR__ . '/../config/media-alt.php' => $this->app->configPath('media-alt.php'),
        ], 'media-alt-config');
    }

    public function boot(): void
    {
        if (!$this->isHubAvailable()) {
            if (is_admin()) {
                add_action('admin_notices', [$this, 'missingHubNotice']);
            }
            return;
        }

        add_action('rest_api_init', [$this, 'registerRoutes']);

        // Admin-only hooks.
        if (is_admin()) {
            add_filter('attachment_fields_to_edit', [$this, 'addSuggestionField'], 20, 2);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
            add_action('add_attachment', [$this, 'primeSuggestion']);
            add_action('edit_attachment', [$this, 'primeSuggestion']);
            (new SettingsPage())->hooks();
            add_action('admin_menu', [$this, 'registerHubMenu'], 20);
            add_action('admin_menu', [$this, 'removeHubDuplicateMenu'], 25);
            add_action('admin_menu', [$this, 'hideSettingsEntry'], 99);
        }
    }

    public function registerRoutes(): void
    {
        register_rest_route('media-alt-suggester/v1', '/suggest/(?P<id>\\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handleSuggestion'],
            'permission_callback' => function () {
                return current_user_can('upload_files');
            },
        ]);
    }

    public function handleSuggestion(WP_REST_Request $request)
    {
        $attachmentId = (int) $request->get_param('id');
        $provider = $request->get_param('provider') ?: null;

        try {
            $result = $this->app->make(AltTextManager::class)->suggest($attachmentId, $provider);
            if ($result['suggestion']) {
                update_post_meta($attachmentId, '_ai_alt_suggestion', $result['suggestion']);
            }

            return rest_ensure_response($result);
        } catch (\Throwable $exception) {
            return new \WP_Error(
                'media_alt_suggestion_failed',
                $exception->getMessage(),
                ['status' => 500]
            );
        }
    }

    public function primeSuggestion(int $attachmentId): void
    {
        if (!(bool) $this->app->make('config')->get('media-alt.autosuggest_on_upload')) {
            return;
        }

        $existingAlt = get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
        if (!empty($existingAlt)) {
            return;
        }

        try {
            $result = $this->app->make(AltTextManager::class)->suggest($attachmentId);
            if (!empty($result['suggestion'])) {
                update_post_meta($attachmentId, '_ai_alt_suggestion', $result['suggestion']);

                if ($this->app->make('config')->get('media-alt.auto_fill_empty_alt')) {
                    update_post_meta($attachmentId, '_wp_attachment_image_alt', $result['suggestion']);
                }
            }
        } catch (\Throwable $exception) {
            do_action('media_alt_suggester_generation_failed', $attachmentId, $exception);
        }
    }

    public function addSuggestionField(array $fields, \WP_Post $post): array
    {
        $suggestion = get_post_meta($post->ID, '_ai_alt_suggestion', true);
        $providers = $this->app->make('config')->get('media-alt.providers', []);
        $defaultProvider = $this->app->make('config')->get('media-alt.default_provider', 'openai');

        $providerOptions = '';
        foreach ($providers as $provider => $config) {
            $selected = $provider === $defaultProvider ? 'selected' : '';
            $label = ucfirst($provider);
            $providerOptions .= "<option value=\"{$provider}\" {$selected}>{$label}</option>";
        }

        $field = [
            'label' => __('AI Alt Suggestion', 'media-alt-suggester'),
            'input' => 'html',
            'html' => sprintf(
                '<div class="media-alt-suggester-field" data-attachment-id="%1$d">
                    <p class="description">%2$s</p>
                    <div style="margin-top:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <button type="button" class="button button-secondary media-alt-suggester__generate" data-attachment-id="%1$d">%5$s</button>
                        <button type="button" class="button media-alt-suggester__apply" data-attachment-id="%1$d">%6$s</button>
                        <label style="display:flex; gap:4px; align-items:center;">
                            <span class="screen-reader-text">%7$s</span>
                            <select class="media-alt-suggester__provider">%8$s</select>
                        </label>
                    </div>
                    <p class="description">%9$s</p>
                    <div class="media-alt-suggester__suggestion" data-suggestion="%3$s" aria-live="polite" style="margin-top:8px; padding:8px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:3px;">%3$s</div>
                </div>',
                (int) $post->ID,
                esc_html__('Generate a short, purposeful alt text. If decorative, leave the alt empty.', 'media-alt-suggester'),
                esc_html($suggestion),
                esc_html__('Use the decision tree: leave the alt empty if the image is decorative; otherwise describe the image’s purpose in context.', 'media-alt-suggester'),
                esc_html__('Generate suggestion', 'media-alt-suggester'),
                esc_html__('Use suggestion for alt text', 'media-alt-suggester'),
                esc_html__('AI Provider', 'media-alt-suggester'),
                $providerOptions,
                esc_html__('Suggestion appears below the Alternative Text field; click apply to copy it into the native field.', 'media-alt-suggester')
            ),
            'show_in_edit' => true,
            'show_in_modal' => false,
        ];

        $ordered = [];
        foreach ($fields as $key => $value) {
            $ordered[$key] = $value;

            if ('image_alt' === $key) {
                $ordered['ai_alt_suggestion'] = $field;
            }
        }

        // Fallback if image_alt is missing.
        if (!isset($ordered['ai_alt_suggestion'])) {
            $ordered['ai_alt_suggestion'] = $field;
        }

        return $ordered;
    }

    public function registerHubMenu(): void
    {
        if (!current_user_can('manage_options') || !$this->isHubAvailable()) {
            return;
        }

        // Parent slug from Autonomy AI hub.
        $parent = '40q-autonomy-ai';
        $title = __('Alt Text Copilot', 'media-alt-suggester');

        add_submenu_page(
            $parent,
            $title,
            $title,
            'manage_options',
            '40q-autonomy-ai-media-alt-suggester',
            fn () => $this->app->make(SettingsPage::class)->renderPage()
        );
    }

    public function hideSettingsEntry(): void
    {
        if (!current_user_can('manage_options') || !$this->isHubAvailable()) {
            return;
        }
        remove_submenu_page('options-general.php', 'media-alt-suggester');
    }

    public function removeHubDuplicateMenu(): void
    {
        if (!current_user_can('manage_options') || !$this->isHubAvailable()) {
            return;
        }
        // Remove any hub-generated submenu for media-alt-suggester to avoid duplicates.
        remove_submenu_page('40q-autonomy-ai', '40q-autonomy-ai-media-alt-suggester');
    }

    protected function isHubAvailable(): bool
    {
        return class_exists('FortyQ\\AutonomyAiHub\\AutonomyAiServiceProvider', false);
    }

    public function missingHubNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        esc_html_e('Alt Text Copilot requires the 40Q Autonomy AI hub to be installed and active.', 'media-alt-suggester');
        echo '</p></div>';
    }

    protected function applySettingsOverrides(): void
    {
        $settings = get_option(SettingsPage::OPTION_KEY, []);
        if (empty($settings)) {
            return;
        }

        $config = $this->app['config'];
        $prompt = $config->get('media-alt.prompt', []);

        if (isset($settings['tone'])) {
            $prompt['tone'] = $settings['tone'];
        }
        if (isset($settings['max_words'])) {
            $prompt['max_words'] = (int) $settings['max_words'];
        }
        if (array_key_exists('custom_instructions', $settings)) {
            $prompt['custom_instructions'] = (string) $settings['custom_instructions'];
        }
        if (array_key_exists('force_verbatim_text', $settings)) {
            $prompt['force_verbatim_text'] = (bool) $settings['force_verbatim_text'];
        }

        $config->set('media-alt.prompt', $prompt);

        if (array_key_exists('vision_enabled', $settings)) {
            $vision = $config->get('media-alt.vision', []);
            $vision['enabled'] = (bool) $settings['vision_enabled'];
            if (!empty($settings['vision_mode'])) {
                $vision['mode'] = $settings['vision_mode'];
            }
            $config->set('media-alt.vision', $vision);
        }
    }

    public function enqueueAdminAssets(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['attachment', 'upload'], true)) {
            return;
        }

        wp_register_script(
            'media-alt-suggester-admin',
            false,
            ['wp-api-fetch'],
            '1.0.0',
            true
        );

        $data = [
            'restRoute' => '/media-alt-suggester/v1/suggest',
            'nonce' => wp_create_nonce('wp_rest'),
            'defaultProvider' => $this->app->make('config')->get('media-alt.default_provider', 'openai'),
            'autoFill' => (bool) $this->app->make('config')->get('media-alt.auto_fill_empty_alt'),
            'labels' => [
                'generating' => __('Generating…', 'media-alt-suggester'),
                'generate' => __('Generate suggestion', 'media-alt-suggester'),
                'error' => __('Could not generate alt text. Please try again.', 'media-alt-suggester'),
            ],
        ];

        wp_add_inline_script('media-alt-suggester-admin', $this->adminScript($data));
        wp_enqueue_script('media-alt-suggester-admin');
    }

    protected function adminScript(array $data): string
    {
        $json = wp_json_encode($data);

        $script = <<<'JS'
(function(window, document, wp) {
    if (!wp || !wp.apiFetch) { return; }
    const settings = __SETTINGS__;

    const findAltField = (id) => {
        const selectors = [
            '#attachment_alt',
            `[name="attachments[${id}][image_alt]"]`,
            `[name="attachments[${id}][alt]"]`,
            'textarea[name="attachment[alt]"]',
        ];
        for (const selector of selectors) {
            const field = document.querySelector(selector);
            if (field) return field;
        }
        return null;
    };

    const moveFieldNextToAlt = () => {
        const wrappers = document.querySelectorAll('.media-alt-suggester-field');
        wrappers.forEach((wrapper) => {
            const attachmentId = wrapper.getAttribute('data-attachment-id');
            const altField = findAltField(attachmentId);
            if (altField && altField.parentNode) {
                altField.parentNode.insertBefore(wrapper, altField.nextSibling);
                wrapper.style.marginTop = '8px';
            }
        });
    };

    document.addEventListener('DOMContentLoaded', moveFieldNextToAlt);

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('.media-alt-suggester__generate');
        if (!button) return;

        const wrapper = button.closest('.media-alt-suggester-field');
        const attachmentId = button.getAttribute('data-attachment-id');
        const providerSelect = wrapper?.querySelector('.media-alt-suggester__provider');
        const provider = providerSelect ? providerSelect.value : settings.defaultProvider;
        const suggestionBox = wrapper?.querySelector('.media-alt-suggester__suggestion');

        button.disabled = true;
        button.textContent = settings.labels.generating;

        try {
            const response = await wp.apiFetch({
                path: `${settings.restRoute}/${attachmentId}`,
                method: 'POST',
                data: { provider },
                headers: { 'X-WP-Nonce': settings.nonce },
            });

            const suggestion = response.suggestion || '';
            if (suggestionBox) {
                suggestionBox.textContent = suggestion;
                suggestionBox.setAttribute('data-suggestion', suggestion);
            }

            if (settings.autoFill) {
                const altField = findAltField(attachmentId);
                if (altField && !altField.value) {
                    altField.value = suggestion;
                }
            }
        } catch (error) {
            window.console?.error?.(error);
            alert(settings.labels.error);
        } finally {
            button.disabled = false;
            button.textContent = settings.labels.generate;
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.media-alt-suggester__apply');
        if (!button) return;

        const wrapper = button.closest('.media-alt-suggester-field');
        const attachmentId = button.getAttribute('data-attachment-id');
        const suggestionBox = wrapper?.querySelector('.media-alt-suggester__suggestion');
        const altField = findAltField(attachmentId);

        if (altField && suggestionBox) {
            const suggestion = suggestionBox.getAttribute('data-suggestion') || suggestionBox.textContent || '';
            if (suggestion) {
                altField.value = suggestion;
            }
        }
    });
})(window, document, window.wp);
JS;

        return str_replace('__SETTINGS__', $json, $script);
    }
}
