<?php
/**
 * Plugin Name: Avfallskoder Search (EWC)
 * Description: Sökfunktion för avfallskoder (EWC) med juridiska hänvisningar. Shortcode: [avfallskoder_search]
 * Version: 2.0.1
 * Author: Stefan Rydén
 * Author URI: https://github.com/stefanryden/Avfallskoder
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-search.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-loader.php';

\Avfall\Koder\Loader::init(__FILE__);
