<?php

declare(strict_types=1);
namespace Avfall\Koder;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;

final class Search
{
    private const TRANSIENT_KEY = 'avfallskoder_data_v2';
    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    private string $data_path;

    public function __construct(string $data_path)
    {
        $this->data_path = $data_path;
    }

    /**
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function get_data()
    {
        $mtime = @filemtime($this->data_path);
        if ($mtime === false) {
            error_log('Avfallskoder data kunde inte laddas');
            return new WP_Error('avfall_data_missing', 'Datafilen saknas.');
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)
            && isset($cached['mtime'], $cached['data'])
            && (int) $cached['mtime'] === (int) $mtime
            && is_array($cached['data'])
        ) {
            return $cached['data'];
        }

        $loaded = $this->load_from_disk();
        if (is_wp_error($loaded)) {
            error_log('Avfallskoder data kunde inte laddas');
            return $loaded;
        }

        set_transient(self::TRANSIENT_KEY, array(
            'mtime' => (int) $mtime,
            'data' => $loaded,
        ), self::CACHE_TTL);

        return $loaded;
    }

    public function maybe_bust_cache(): void
    {
        $mtime = @filemtime($this->data_path);
        if ($mtime === false) {
            delete_transient(self::TRANSIENT_KEY);
            return;
        }

        $cached = get_transient(self::TRANSIENT_KEY);
        if (!is_array($cached) || !isset($cached['mtime'])) {
            return;
        }

        if ((int) $cached['mtime'] !== (int) $mtime) {
            delete_transient(self::TRANSIENT_KEY);
        }
    }

    /**
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function load_from_disk()
    {
        if (!file_exists($this->data_path)) {
            return new WP_Error('avfall_data_missing', 'Datafilen saknas.');
        }

        if (!is_readable($this->data_path)) {
            return new WP_Error('avfall_data_unreadable', 'Kunde inte läsa datafilen.');
        }

        $ext = strtolower((string) pathinfo($this->data_path, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            $loaded = require $this->data_path;
            if (!is_array($loaded)) {
                return new WP_Error('avfall_data_invalid_shape', 'Fel format på datafilen.');
            }
            return $loaded;
        }

        $raw = @file_get_contents($this->data_path);
        if ($raw === false) {
            return new WP_Error('avfall_data_unreadable', 'Kunde inte läsa datafilen.');
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('avfall_data_invalid_json', 'Ogiltig JSON: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            return new WP_Error('avfall_data_invalid_shape', 'Fel format på datafilen.');
        }

        return $decoded;
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, suggestions: array<int, array<string, mixed>>}|WP_Error
     */
    public function search(string $query)
    {
        $this->maybe_bust_cache();

        $data = $this->get_data();
        if (is_wp_error($data)) {
            return $data;
        }

        $q = $this->normalize_text($query);
        if ($q === '') {
            return array('results' => array(), 'suggestions' => array());
        }

        $q_code = $this->normalize_code($query);
        $has_digit = $q_code !== '';

        $results = array();

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code_raw = (string) ($item['kod'] ?? '');
            $name_raw = (string) ($item['benamning'] ?? ($item['namn'] ?? ''));
            $desc_raw = (string) ($item['beskrivning'] ?? '');
            $keywords = $item['nyckelord'] ?? array();

            $code = $this->normalize_code($code_raw);
            $name = $this->normalize_text($name_raw);
            $desc = $this->normalize_text($desc_raw);

            $matched = false;

            if ($has_digit) {
                if ($code !== '' && strpos($code, $q_code) !== false) {
                    $matched = true;
                } elseif (substr($q_code, -1) !== '*' && $code !== '' && strpos($code, $q_code . '*') !== false) {
                    $matched = true;
                }
            }

            if (!$matched) {
                if ($name !== '' && strpos($name, $q) !== false) {
                    $matched = true;
                } elseif ($desc !== '' && strpos($desc, $q) !== false) {
                    $matched = true;
                } elseif (is_array($keywords)) {
                    foreach ($keywords as $kw) {
                        $kw_n = $this->normalize_text((string) $kw);
                        if ($kw_n !== '' && strpos($kw_n, $q) !== false) {
                            $matched = true;
                            break;
                        }
                    }
                }
            }

            if ($matched) {
                $results[] = $this->public_item($item);
                if (count($results) >= 50) {
                    break;
                }
            }
        }

        $suggestions = array_slice($results, 0, 5);

        return array(
            'results' => $results,
            'suggestions' => $suggestions,
        );
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function public_item(array $item): array
    {
        $kod = sanitize_text_field((string) ($item['kod'] ?? ''));
        $benamning = sanitize_text_field((string) ($item['benamning'] ?? ($item['namn'] ?? '')));

        $kapitel = sanitize_text_field((string) ($item['kapitel'] ?? ''));
        $beskrivning = sanitize_text_field((string) ($item['beskrivning'] ?? ''));
        $lagrum = sanitize_text_field((string) ($item['lagrum'] ?? 'Avfallsförordningen (2020:614) bilaga 3'));

        $nyckelord_raw = $item['nyckelord'] ?? array();
        $nyckelord = array();
        if (is_array($nyckelord_raw)) {
            foreach ($nyckelord_raw as $kw) {
                $kw_s = sanitize_text_field((string) $kw);
                if ($kw_s !== '') {
                    $nyckelord[] = $kw_s;
                }
            }
        }

        return array(
            'kod' => $kod,
            'benamning' => $benamning,
            'farligt' => (bool) ($item['farligt'] ?? false),
            'kapitel' => $kapitel,
            'beskrivning' => $beskrivning,
            'lagrum' => $lagrum,
            'nyckelord' => $nyckelord,
        );
    }

    private function normalize_text(string $input): string
    {
        return strtolower(trim($input));
    }

    private function normalize_code(string $input): string
    {
        $s = strtolower(trim($input));
        $s = str_replace(array(' ', "\t", "\n", "\r"), '', $s);

        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            $is_digit = ($ch >= '0' && $ch <= '9');
            if ($is_digit || $ch === '*') {
                $out .= $ch;
            }
        }

        return $out;
    }
}
