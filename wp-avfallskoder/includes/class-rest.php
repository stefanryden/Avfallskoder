<?php

declare(strict_types=1);
namespace Avfall\Koder;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Rest
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    private Search $search;

    public function __construct(Search $search)
    {
        $this->search = $search;
    }

    public function register_routes(): void
    {
        register_rest_route('avfall/v1', '/search', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'handle_search'),
            'args' => array(
                'q' => array(
                    'type' => 'string',
                    'required' => true,
                ),
            ),
        ));

        add_filter('rest_post_dispatch', array($this, 'add_noindex_header'), 10, 3);
    }

    public function permission_check(WP_REST_Request $request): bool
    {
        // Public endpoint.
        return true;
    }

    public function handle_search(WP_REST_Request $request)
    {
        if (strtoupper((string) $request->get_method()) !== 'GET') {
            return new WP_Error('method_not_allowed', 'Method not allowed', array('status' => 405));
        }

        $rate_limited = $this->maybe_rate_limit($request);
        if ($rate_limited instanceof WP_Error) {
            return $rate_limited;
        }

        $q = (string) $request->get_param('q');
        $q = sanitize_text_field($q);

        $len = function_exists('mb_strlen') ? (int) mb_strlen($q) : (int) strlen($q);
        if ($len > 200) {
            return new WP_Error('query_too_long', 'Sökfrågan är för lång.', array('status' => 400));
        }

        if ($q === '') {
            return new WP_Error('invalid_query', 'Ogiltig sökfråga.', array('status' => 400));
        }

        $result = $this->search->search($q);
        if (is_wp_error($result)) {
            error_log('Avfallskoder REST-fel: ' . $result->get_error_code() . ' - ' . $result->get_error_message());
            return new WP_REST_Response(array(
                'ok' => false,
                'error' => $this->public_error_message(),
            ), 500);
        }

        $response = new WP_REST_Response(array(
            'ok' => true,
            'results' => $result['results'],
            'suggestions' => $result['suggestions'],
        ), 200);

        $response->header('X-Robots-Tag', 'noindex');
        return $response;
    }

    private function public_error_message(): string
    {
        return 'Tekniskt fel: kunde inte ladda avfallskoder. Försök igen senare.';
    }

    private function maybe_rate_limit(WP_REST_Request $request): ?WP_Error
    {
        $ip = $this->get_client_ip($request);
        if ($ip === '') {
            $ip = 'unknown';
        }

        $now = time();
        $bucket = (int) floor($now / 60);
        $key = 'avk_rl_' . md5($ip . '|' . (string) $bucket);

        $count = get_transient($key);
        $count = is_numeric($count) ? (int) $count : 0;
        $count++;

        // Keep the transient slightly longer than a minute to cover clock skew.
        set_transient($key, $count, 70);

        if ($count <= self::RATE_LIMIT_PER_MINUTE) {
            return null;
        }

        $retry_after = 60 - ($now % 60);
        if ($retry_after < 1) {
            $retry_after = 1;
        }

        return new WP_Error('rate_limited', 'Rate limit exceeded', array(
            'status' => 429,
            'headers' => array(
                'Retry-After' => (string) $retry_after,
                'X-Robots-Tag' => 'noindex',
            ),
        ));
    }

    public function add_noindex_header($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        $route = (string) $request->get_route();
        if (strpos($route, '/avfall/v1/') !== 0) {
            return $result;
        }

        $response = rest_ensure_response($result);
        if ($response instanceof WP_REST_Response) {
            $response->header('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    private function get_client_ip(WP_REST_Request $request): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = trim($ip);

        /**
         * Allow overriding the client IP used for rate limiting.
         *
         * IMPORTANT: If you use forwarded headers, validate them at the edge/proxy.
         */
        $ip = (string) apply_filters('avk_client_ip', $ip, $request);

        return $ip;
    }
}
