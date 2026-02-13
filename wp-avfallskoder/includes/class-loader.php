<?php

declare(strict_types=1);
namespace Avfall\Koder;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;

final class Loader
{
    private string $plugin_file;
    private string $plugin_url;
    private string $plugin_dir;

    private Search $search;
    private Rest $rest;

    public static function init(string $plugin_file): void
    {
        $self = new self($plugin_file);
        $self->register_hooks();
    }

    private function __construct(string $plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_dir = plugin_dir_path($plugin_file);
        $this->plugin_url = plugin_dir_url($plugin_file);

        $data_path_php = $this->plugin_dir . 'data/avfallskoder-data.php';
        $this->search = new Search($data_path_php);
        $this->rest = new Rest($this->search);
    }

    private function register_hooks(): void
    {
        add_action('rest_api_init', array($this->rest, 'register_routes'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets_if_needed'), 20);
        add_shortcode('avfallskoder_search', array($this, 'shortcode'));
    }

    public function register_assets(): void
    {
        $style_path = $this->plugin_dir . 'assets/style.css';
        $script_path = $this->plugin_dir . 'assets/search.js';

        $style_ver = file_exists($style_path) ? (int) filemtime($style_path) : '2.0.0';
        $script_ver = file_exists($script_path) ? (int) filemtime($script_path) : '2.0.0';

        wp_register_style(
            'avk-avfallskoder-style',
            $this->plugin_url . 'assets/style.css',
            array(),
            $style_ver
        );

        wp_register_script(
            'avk-avfallskoder-search',
            $this->plugin_url . 'assets/search.js',
            array(),
            $script_ver,
            true
        );
    }

    public function enqueue_assets_if_needed(): void
    {
        if (!$this->should_enqueue_assets()) {
            return;
        }

        $this->localize_settings();
        wp_enqueue_style('avk-avfallskoder-style');
        wp_enqueue_script('avk-avfallskoder-search');
    }

    private function should_enqueue_assets(): bool
    {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return false;
        }

        return has_shortcode($post->post_content, 'avfallskoder_search');
    }

    public function shortcode($atts = array()): string
    {
        $this->localize_settings();
        wp_enqueue_style('avk-avfallskoder-style');
        wp_enqueue_script('avk-avfallskoder-search');

        $widget_id = 'avk-widget-' . wp_generate_uuid4();

        $html  = '<div class="avk-widget" id="' . esc_attr($widget_id) . '" data-avk-widget="1">';
        $html .= '  <div class="avk-panel">';
        $html .= '    <label class="avk-label" for="' . esc_attr($widget_id . '-q') . '">' . esc_html__('Sök', 'avk') . '</label>';
        $html .= '    <input class="avk-input" type="search" id="' . esc_attr($widget_id . '-q') . '" name="avk_q" autocomplete="off" inputmode="search" aria-label="' . esc_attr__('Sök avfallskod eller avfallstyp', 'avk') . '" />';
        $html .= '    <div class="avk-suggest" role="listbox" aria-label="' . esc_attr__('Förslag', 'avk') . '" hidden></div>';
        $html .= '  </div>';
        $html .= '  <div class="avk-results" aria-live="polite" aria-busy="false"></div>';
        $html .= '</div>';

        return $html;
    }

    private function localize_settings(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $data = $this->search->get_data();
        $data_ok = !is_wp_error($data);

        $load_error_message = '';
        if (!$data_ok) {
            $load_error_message = 'Tekniskt fel: kunde inte ladda avfallskoder. Försök igen senare.';
        }

        wp_localize_script('avk-avfallskoder-search', 'AVK_SETTINGS', array(
            'restUrl' => esc_url_raw(rest_url('avfall/v1/search')),
            'nonce' => wp_create_nonce('wp_rest'),
            'dataOk' => (bool) $data_ok,
            'dataLoadError' => esc_html($load_error_message),
            'i18n' => array(
                'searchPlaceholder' => __('Sök på kod eller avfallstyp…', 'avk'),
                'noResults' => __('Inga träffar.', 'avk'),
                'loadError' => __('Tekniskt fel: kunde inte ladda avfallskoder. Försök igen senare.', 'avk'),
                'prompt' => __('Skriv för att söka (t.ex. "asfalt" eller "17 03").', 'avk'),
                'hazardBadge' => __('FARLIGT AVFALL', 'avk'),
                'legalMore' => __('Mer juridik', 'avk'),
                'suggestionsLabel' => __('Förslag', 'avk'),
            ),
        ));
    }
}
