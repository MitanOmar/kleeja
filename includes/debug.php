<?php
/**
 *
 * @package Kleeja
 * @copyright (c) 2007 Kleeja.net
 * @license ./docs/license.txt
 *
 */

//no for directly open
if (!defined('IN_COMMON')) {
    exit();
}

/*
 * Debug toolbar and the history of the last requests, this file is loaded only while DEV_STAGE is defined.
 * The data are collected by KleejaDatabase::query() for the queries, Plugins::run() for the hooks,
 * kleeja_style::display() for the templates and kleeja_show_error() for the warnings.
 */

//a query that takes this time or more, in seconds, is marked as slow, it can be changed in config.php
if (!defined('KLEEJA_DEBUG_SLOW_QUERY')) {
    define('KLEEJA_DEBUG_SLOW_QUERY', 0.05);
}

//number of the last requests that are kept for the history page, it can be changed in config.php
if (!defined('KLEEJA_DEBUG_HISTORY')) {
    define('KLEEJA_DEBUG_HISTORY', 30);
}

//absolute, the working folder can be changed while the shutdown functions run
define('KLEEJA_DEBUG_PATH', dirname(__DIR__) . '/cache/debug');

/**
 * Show the debug toolbar of the current page, it is open when ?debug is in the url
 */
function kleeja_debug(): void
{
    $data = kleeja_debug_collect();

    is_array($plugin_run_result = Plugins::getInstance()->run('kleeja_debug_func', get_defined_vars()))
        ? extract($plugin_run_result)
        : null; //run hook

    echo kleeja_debug_toolbar($data, ig('debug'));
}

/**
 * Debug data of the current request, the same array is saved for the history page
 * @param  bool  $finished true when it is called at the end of the request
 * @return array
 */
function kleeja_debug_collect(bool $finished = false): array
{
    global $SQL, $tpl, $usrcp, $config, $d_groups, $lang, $starttm, $STYLE_PATH_ADMIN;

    $now = get_microtime();
    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $relative = fn(string $file): string => str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    $plugins_info = Plugins::getInstance()->getDebugInfo();

    //times are kept from the start of the page
    $queries = [];

    foreach (isset($SQL) ? $SQL->debugr : [] as $number => $query) {
        $queries[$number] = ['start' => $query['start'] - $starttm] + $query;
    }

    $plugins = [];

    foreach ($plugins_info['installed_plugins'] ?? [] as $name => $version) {
        $plugins[$name] = [
            'version' => $version,
            //the same icon of the plugin in the admin panel
            'icon' => file_exists($root . KLEEJA_PLUGINS_FOLDER . '/' . $name . '/icon.png')
                ? ($config['siteurl'] ?? '') . KLEEJA_PLUGINS_FOLDER . '/' . rawurlencode($name) . '/icon.png'
                : ($STYLE_PATH_ADMIN ?? '') . 'images/plugin.png',
        ];
    }

    $hooks = [];

    foreach ($plugins_info['hooks_plugins'] ?? [] as $hook => $priorities) {
        $hooks[$hook] = [
            'priorities' => array_keys($priorities),
            'runs' => $plugins_info['hooks_runs'][$hook] ?? 0,
            //[name, calls, time] in the running order
            'plugins' => array_map(
                fn(string $name): array => [$name, ...$plugins_info['hooks_time'][$hook][$name] ?? [0, 0.0]],
                array_merge(...array_values($priorities)),
            ),
        ];
    }

    ksort($hooks);
    $hooks_runs = $plugins_info['hooks_runs'] ?? [];
    ksort($hooks_runs);

    $group_id = isset($usrcp) ? $usrcp->group_id() : false;

    return [
        'id' => '',
        'finished' => $finished,
        'error' => $GLOBALS['kleeja_debug_error'] ?? null,
        'request' => [
            'time' => time(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'url' => kleeja_get_page(),
            'status' => http_response_code() ?: 200,
            'ajax' => ig('_ajax_') || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest',
            'admin' => defined('IN_ADMIN'),
            //the id first, the browser moves a number that comes after a name in Arabic
            'user' => isset($usrcp) && $usrcp->name() ? '#' . $usrcp->id() . ' ' . $usrcp->name() : 'Guest',
            'group' =>
                $group_id !== false
                    ? '#' .
                        $group_id .
                        ' ' .
                        preg_replace_callback(
                            '/\{lang\.([A-Z_]+)\}/',
                            fn(array $m): string => $lang[$m[1]] ?? $m[1],
                            (string) ($d_groups[$group_id]['data']['group_name'] ?? ''),
                        )
                    : '',
            'style' => $config['style'] ?? '',
            'language' => $config['language'] ?? '',
        ],
        'env' => [
            'kleeja' => KLEEJA_VERSION,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'database' => isset($SQL) ? $SQL->driver ?? '' : '',
            'gzip' => !in_array(strtolower((string) ini_get('zlib.output_compression')), ['', '0', 'off'], true),
            'hooks' => !defined('STOP_PLUGINS'),
            'files' => count(get_included_files()),
        ],
        'page_time' => $now - $starttm,
        'memory' => memory_get_usage(),
        'memory_peak' => memory_get_peak_usage(),
        'query_num' => isset($SQL) ? $SQL->query_num : 0,
        'queries' => $queries,
        'warnings' => array_values(
            array_map(
                fn(array $warning): array => ['file' => $relative($warning['file'])] + $warning,
                $GLOBALS['kleeja_debug_warnings'] ?? [],
            ),
        ),
        'plugins' => $plugins,
        'hooks' => $hooks,
        'hooks_runs' => $hooks_runs,
        'hooks_timeline' => array_map(
            fn(array $call): array => [$call[0] - $starttm, $call[1], $call[2], $call[3]],
            $plugins_info['hooks_timeline'] ?? [],
        ),
        'templates' => array_map(
            fn(array $template): array => ['start' => $template['start'] - $starttm] + $template,
            isset($tpl) ? $tpl->debug_templates : [],
        ),
    ];
}

/**
 * Save the debug data of the current request for the history page, it runs at the end of every request
 */
function kleeja_debug_save(): void
{
    //the history page itself is not kept
    if (defined('KLEEJA_DEBUG_VIEWER')) {
        return;
    }

    if (!is_dir(KLEEJA_DEBUG_PATH) && !@mkdir(KLEEJA_DEBUG_PATH, K_DIR_CHMOD, true)) {
        return;
    }

    //a fatal error of PHP does not pass by kleeja_show_error()
    $last_error = error_get_last();

    if (
        empty($GLOBALS['kleeja_debug_error']) &&
        $last_error &&
        in_array($last_error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
    ) {
        $GLOBALS['kleeja_debug_error'] = [
            'type' => 'Fatal error',
            'message' => $last_error['message'],
            'file' => $last_error['file'],
            'line' => $last_error['line'],
        ];
    }

    $data = kleeja_debug_collect(true);
    //starts with the time to the microsecond, so the names are sorted from the oldest
    $data['id'] = date_create()->format('Ymd-His-u') . '-' . bin2hex(random_bytes(3));

    //a PHP file that exits, so it can not be read from the web even without .htaccess, the values can be private
    @file_put_contents(
        KLEEJA_DEBUG_PATH . '/' . $data['id'] . '.php',
        "<?php exit(); ?>\n" .
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_INVALID_UTF8_SUBSTITUTE |
                    JSON_PARTIAL_OUTPUT_ON_ERROR |
                    JSON_PRESERVE_ZERO_FRACTION,
            ),
        LOCK_EX,
    );

    //keep only the last requests
    $files = glob(KLEEJA_DEBUG_PATH . '/*.php') ?: [];
    rsort($files);

    foreach (array_slice($files, KLEEJA_DEBUG_HISTORY) as $file) {
        @unlink($file);
    }
}

/**
 * Read a saved request of the history
 * @param  string     $file
 * @return array|null
 */
function kleeja_debug_load(string $file): ?array
{
    $content = @file_get_contents($file);

    if ($content === false || ($start = strpos($content, "\n")) === false) {
        return null;
    }

    $data = json_decode(substr($content, $start + 1), true);

    return is_array($data) ? $data : null;
}

/**
 * Page of the last requests, with the debug panel of the chosen one, then the script stops
 * @param string $id
 */
function kleeja_debug_history(string $id): void
{
    global $SQL;

    define('KLEEJA_DEBUG_VIEWER', true);

    $files = glob(KLEEJA_DEBUG_PATH . '/*.php') ?: [];
    rsort($files);
    $id = preg_match('/^\d{8}-\d{6}-\d{6}-[a-f0-9]{6}$/', $id) ? $id : '';
    $current = null;
    $rows = '';

    foreach ($files as $file) {
        if (!($data = kleeja_debug_load($file))) {
            continue;
        }

        //the newest one is shown if none is chosen
        if ($current === null && ($id === '' || $id === $data['id'])) {
            $current = $data;
        }

        $request = $data['request'];
        $rows .=
            '<tr' .
            ($current !== null && $current['id'] === $data['id'] ? ' class="is-current"' : '') .
            '><td class="kj-debug-num">' .
            date('H:i:s', $request['time']) .
            '</td><td><a href="' .
            kleeja_debug_e(kleeja_debug_history_url($data['id'])) .
            '"><code>' .
            kleeja_debug_e($request['method'] . ' ' . $request['url']) .
            '</code></a>' .
            ($request['admin'] ? ' <span class="kj-debug-tag">Admin</span>' : '') .
            ($request['ajax'] ? ' <span class="kj-debug-tag">AJAX</span>' : '') .
            ($data['error'] ? ' ' . kleeja_debug_flag('Error', 'error') : '') .
            '</td><td class="kj-debug-num">' .
            (int) $request['status'] .
            '</td><td class="kj-debug-num">' .
            kleeja_debug_ms($data['page_time']) .
            '</td><td class="kj-debug-num">' .
            count($data['queries']) .
            '</td><td class="kj-debug-num">' .
            count($data['warnings']) .
            '</td></tr>';
    }

    $history =
        '<div class="kj-debug-head"><picture>' .
        '<source srcset="https://kleeja.net/images/logo-light.svg" media="(prefers-color-scheme: dark)">' .
        '<img src="https://kleeja.net/images/logo.svg" alt="Kleeja" width="32" height="32"></picture>' .
        '<div><h1 class="kj-debug-title">Debug history</h1><p class="kj-debug-subtitle">The last ' .
        KLEEJA_DEBUG_HISTORY .
        ' requests of this site, kept in cache/debug while DEV_STAGE is defined.</p></div></div>' .
        ($rows !== ''
            ? '<div class="kj-debug-history"><table class="kj-debug-table"><thead><tr>' .
                '<th class="kj-debug-num">Time</th><th>Request</th><th class="kj-debug-num">Status</th>' .
                '<th class="kj-debug-num">Duration</th><th class="kj-debug-num">Queries</th>' .
                '<th class="kj-debug-num">Warnings</th></tr></thead><tbody>' .
                $rows .
                '</tbody></table></div>'
            : '<p class="kj-debug-empty">No requests are saved yet.</p>');

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!DOCTYPE html><html lang="en" dir="ltr"><head><meta charset="UTF-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">' .
        '<meta name="robots" content="noindex, nofollow"><title>Debug history · Kleeja</title>' .
        '<style>' .
        kleeja_debug_css() .
        '</style></head><body class="kj-debug-body"><div class="kj-debug kj-debug-page" id="kj-debug" dir="ltr">' .
        '<div class="kj-debug-panel">' .
        $history .
        '</div>' .
        ($current !== null ? '<div class="kj-debug-panel">' . kleeja_debug_panel($current) . '</div>' : '') .
        '</div><script>' .
        kleeja_debug_js() .
        '</script></body></html>';

    $SQL->close();

    exit();
}

/**
 * Url of the history page, or of one request in it
 * @param  string $id
 * @return string
 */
function kleeja_debug_history_url(string $id = ''): string
{
    global $config;

    return ($config['siteurl'] ?? './') . 'go.php?go=debug' . ($id !== '' ? '&id=' . $id : '');
}

/**
 * The toolbar in the bottom of the page, and the panel that it opens
 * @param  array  $data
 * @param  bool   $open show the panel from the start
 * @return string
 */
function kleeja_debug_toolbar(array $data, bool $open = false): string
{
    $stats = kleeja_debug_stats($data);
    $warnings = count($data['warnings']);
    //the most important first, the ones that do not fit in a small screen are hidden from the end
    $items = [
        '<b>' . kleeja_debug_ms($data['page_time']) . '</b>',
        '<b>' . $stats['query_num'] . '</b> queries',
        $data['error'] ? kleeja_debug_flag('Error', 'error') : '',
        $warnings ? kleeja_debug_flag($warnings . ($warnings > 1 ? ' warnings' : ' warning'), 'warning') : '',
        $stats['repeated'] ? kleeja_debug_flag($stats['repeated'] . ' repeated', 'warning') : '',
        $stats['slow'] ? kleeja_debug_flag($stats['slow'] . ' slow', 'warning') : '',
        '<b>' . kleeja_debug_ms($stats['plugins_time']) . '</b> plugins',
        '<b>' . readable_size($data['memory_peak']) . '</b>',
    ];

    return '<div class="kj-debug kj-debug-toolbar' .
        ($open ? ' is-open' : '') .
        '" id="kj-debug" dir="ltr"><style>' .
        kleeja_debug_css() .
        '</style>' .
        '<div class="kj-debug-sheet" id="kj-debug-sheet" role="region" aria-label="Debug information">' .
        '<div class="kj-debug-panel">' .
        kleeja_debug_panel($data) .
        '</div></div>' .
        '<div class="kj-debug-bar">' .
        '<button type="button" class="kj-debug-toggle" aria-controls="kj-debug-sheet" aria-expanded="' .
        ($open ? 'true' : 'false') .
        '" title="Show or hide the debug panel">' .
        '<picture><source srcset="https://kleeja.net/images/logo-light.svg" media="(prefers-color-scheme: dark)">' .
        '<img src="https://kleeja.net/images/logo.svg" alt="Kleeja debug" width="24" height="24"></picture>' .
        '<span class="kj-debug-bar-items">' .
        implode(
            '',
            array_map(
                fn(string $item): string => '<span class="kj-debug-bar-item">' . $item . '</span>',
                array_filter($items),
            ),
        ) .
        '</span><svg class="kj-debug-chevron" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">' .
        '<path d="M3 10l5-5 5 5" fill="none" stroke="currentColor" stroke-width="2"/></svg></button>' .
        '<a class="kj-debug-bar-link" href="' .
        kleeja_debug_e(kleeja_debug_history_url()) .
        '">History</a>' .
        '<button type="button" class="kj-debug-hide" title="Hide the toolbar" aria-label="Hide the toolbar">' .
        '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" ' .
        'stroke="currentColor" stroke-width="2"/></svg></button></div>' .
        '<button type="button" class="kj-debug-tab" title="Show the debug toolbar">' .
        '<picture><source srcset="https://kleeja.net/images/logo-light.svg" media="(prefers-color-scheme: dark)">' .
        '<img src="https://kleeja.net/images/logo.svg" alt="Show the debug toolbar" width="24" height="24">' .
        '</picture></button>' .
        '</div><div class="kj-debug-spacer"></div><script>' .
        kleeja_debug_js() .
        '</script>';
}

/**
 * Numbers that are shown in the toolbar and the panel
 * @param  array $data
 * @return array
 */
function kleeja_debug_stats(array $data): array
{
    $durations = array_map(fn(array $query): float => (float) $query['time'], $data['queries']);
    //the same query that runs more than once, like inside a loop
    $repeats = array_count_values(array_map(fn(array $query): string => $query['sql'], $data['queries']));

    //the calls of plugins can be inside each other, so the time is of the merged periods, not a sum
    $calls = $data['hooks_timeline'];
    usort($calls, fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $plugins_time = 0.0;
    $end = -1.0;

    foreach ($calls as [$start, $time]) {
        $plugins_time += max(0.0, $start + $time - max($start, $end));
        $end = max($end, $start + $time);
    }

    return [
        'durations' => $durations,
        'sql_time' => array_sum($durations),
        'slowest' => $durations ? max($durations) : 0.0,
        'repeats' => $repeats,
        'repeated' => count(array_filter($repeats, fn(int $count): bool => $count > 1)),
        'slow' => count(array_filter($durations, fn(float $time): bool => $time >= KLEEJA_DEBUG_SLOW_QUERY)),
        'query_num' => max($data['query_num'], count($data['queries'])),
        'plugins_time' => $plugins_time,
        'plugins_calls' => array_sum(
            array_map(
                fn(array $hook): int => array_sum(array_map(fn(array $plugin): int => $plugin[1], $hook['plugins'])),
                $data['hooks'],
            ),
        ),
    ];
}

/**
 * The content of the debug panel
 * @param  array  $data
 * @return string
 */
function kleeja_debug_panel(array $data): string
{
    $stats = kleeja_debug_stats($data);
    $request = $data['request'];
    $env = $data['env'];
    $suppressed = count(array_filter($data['warnings'], fn(array $warning): bool => $warning['suppressed']));

    //[label, value, note]
    $tiles = [
        [
            'Generation time',
            kleeja_debug_ms($data['page_time']),
            $data['finished'] ? 'whole request' : 'until this toolbar',
        ],
        [
            'Queries',
            $stats['query_num'],
            implode(
                ' · ',
                array_filter([
                    $stats['repeated'] ? $stats['repeated'] . ' repeated' : '',
                    $stats['slow'] ? $stats['slow'] . ' slow' : '',
                ]),
            ) ?:
            'no repeated or slow ones',
        ],
        [
            'SQL time',
            kleeja_debug_ms($stats['sql_time']),
            $data['page_time'] > 0
                ? round(($stats['sql_time'] / $data['page_time']) * 100) . '% of generation time'
                : '',
        ],
        [
            'Plugins time',
            kleeja_debug_ms($stats['plugins_time']),
            $stats['plugins_calls'] . ' calls in ' . count($data['hooks_runs']) . ' hooks that ran',
        ],
        ['Warnings', count($data['warnings']), $suppressed ? $suppressed . ' hidden by @' : ''],
        ['Memory', readable_size($data['memory']), 'peak ' . readable_size($data['memory_peak'])],
        ['PHP', $env['php'], 'Kleeja ' . $env['kleeja'] . ' · ' . $env['os']],
        ['Database', $env['database'] ?: '-', 'PDO'],
    ];

    $tiles_html = '';

    foreach ($tiles as [$label, $value, $note]) {
        $tiles_html .=
            '<div class="kj-debug-stat"><dt>' .
            $label .
            '</dt><dd class="kj-debug-stat-value">' .
            kleeja_debug_e($value) .
            '</dd>' .
            ($note !== '' ? '<dd class="kj-debug-stat-note">' . kleeja_debug_e($note) . '</dd>' : '') .
            '</div>';
    }

    return '<div class="kj-debug-head"><picture>' .
        '<source srcset="https://kleeja.net/images/logo-light.svg" media="(prefers-color-scheme: dark)">' .
        '<img src="https://kleeja.net/images/logo.svg" alt="Kleeja" width="32" height="32"></picture>' .
        '<div class="kj-debug-head-text"><h2 class="kj-debug-title">Page analysis</h2>' .
        '<p class="kj-debug-subtitle"><code>' .
        kleeja_debug_e($request['method'] . ' ' . $request['url']) .
        '</code> · ' .
        (int) $request['status'] .
        ' · ' .
        date('H:i:s', $request['time']) .
        ($request['admin'] ? ' <span class="kj-debug-tag">Admin</span>' : '') .
        ($request['ajax'] ? ' <span class="kj-debug-tag">AJAX</span>' : '') .
        '</p></div>' .
        '<a class="kj-debug-history-link" href="' .
        kleeja_debug_e(kleeja_debug_history_url()) .
        '">History of requests</a></div>' .
        ($data['error']
            ? '<div class="kj-debug-error">' .
                kleeja_debug_flag($data['error']['type'], 'error') .
                ' <strong>This request stopped with an error.</strong><p>' .
                nl2br(kleeja_debug_e($data['error']['message'])) .
                '</p><code>' .
                kleeja_debug_e(basename($data['error']['file']) . ':' . $data['error']['line']) .
                '</code></div>'
            : '') .
        '<dl class="kj-debug-stats">' .
        $tiles_html .
        '</dl>' .
        kleeja_debug_section('Timeline', '', kleeja_debug_timeline($data)) .
        kleeja_debug_section('SQL queries', count($data['queries']), kleeja_debug_queries($data, $stats)) .
        kleeja_debug_section('Warnings', count($data['warnings']), kleeja_debug_warnings($data['warnings'])) .
        kleeja_debug_section('Plugins', count($data['plugins']), kleeja_debug_plugins($data)) .
        kleeja_debug_section('Templates', count($data['templates']), kleeja_debug_templates($data['templates'])) .
        kleeja_debug_section('Request', '', kleeja_debug_request($data), false);
}

/**
 * A part of the panel that can be closed
 * @param  string     $title
 * @param  int|string $count shown next to the title if it is not empty
 * @param  string     $content
 * @param  bool       $open
 * @return string
 */
function kleeja_debug_section(string $title, int|string $count, string $content, bool $open = true): string
{
    return '<details class="kj-debug-section"' .
        ($open ? ' open' : '') .
        '><summary>' .
        $title .
        ($count !== '' ? ' <span class="kj-debug-count">' . $count . '</span>' : '') .
        '</summary>' .
        $content .
        '</details>';
}

/**
 * When the queries, the plugins and the templates ran, in one time axis of the page
 * @param  array  $data
 * @return string
 */
function kleeja_debug_timeline(array $data): string
{
    $total = max((float) $data['page_time'], 0.000001);

    //[start, time, details]
    $lanes = [
        'SQL' => array_map(
            fn(int|string $number, array $query): array => [
                $query['start'],
                $query['time'],
                '#' .
                $number .
                ' · ' .
                kleeja_debug_ms($query['time']) .
                ' · ' .
                mb_strimwidth($query['sql'], 0, 120, '…'),
            ],
            array_keys($data['queries']),
            $data['queries'],
        ),
        'Plugins' => array_map(
            fn(array $call): array => [
                $call[0],
                $call[1],
                $call[3] . ' in ' . $call[2] . ' · ' . kleeja_debug_ms($call[1]),
            ],
            $data['hooks_timeline'],
        ),
        'Templates' => array_map(
            fn(array $template): array => [
                $template['start'],
                $template['time'],
                $template['name'] . ' · ' . kleeja_debug_ms($template['time']),
            ],
            $data['templates'],
        ),
    ];

    $html = '<div class="kj-debug-timeline">';

    foreach ($lanes as $lane => $marks) {
        $html .=
            '<div class="kj-debug-lane-label">' . $lane . '</div><div class="kj-debug-lane" data-lane="' . $lane . '">';

        foreach ($marks as [$start, $time, $details]) {
            $left = min(100, max(0, ($start / $total) * 100));
            $html .=
                '<span class="kj-debug-mark" style="left: ' .
                round($left, 2) .
                '%; width: ' .
                round(min(100 - $left, ($time / $total) * 100), 2) .
                '%" title="' .
                kleeja_debug_e($details) .
                '"></span>';
        }

        $html .= '</div>';
    }

    $html .= '<div></div><div class="kj-debug-axis">';

    foreach ([0, 25, 50, 75, 100] as $percent) {
        $html .= '<span style="left: ' . $percent . '%">' . kleeja_debug_ms(($total * $percent) / 100) . '</span>';
    }

    return $html .
        '</div></div><p class="kj-debug-hint">Hover a mark to see what it is, the details are in the parts below.</p>';
}

/**
 * The list of queries
 * @param  array  $data
 * @param  array  $stats of kleeja_debug_stats()
 * @return string
 */
function kleeja_debug_queries(array $data, array $stats): string
{
    if (!$data['queries']) {
        return '<p class="kj-debug-empty">No queries were run.</p>';
    }

    //marking the slowest one is useful only if there is more than one query
    $slowest_key = count($stats['durations']) > 1 ? array_search($stats['slowest'], $stats['durations'], true) : null;
    $html = '';

    //the repeated ones first, as a summary
    $repeated = array_filter($stats['repeats'], fn(int $count): bool => $count > 1);

    if ($repeated) {
        arsort($repeated);
        $rows = '';

        foreach ($repeated as $sql => $count) {
            $time = array_sum(
                array_map(
                    fn(array $query): float => $query['sql'] === $sql ? (float) $query['time'] : 0.0,
                    $data['queries'],
                ),
            );
            $rows .=
                '<tr><td><code>' .
                kleeja_debug_e(mb_strimwidth((string) $sql, 0, 160, '…')) .
                '</code></td><td class="kj-debug-num">' .
                $count .
                '×</td><td class="kj-debug-num">' .
                kleeja_debug_ms($time) .
                '</td></tr>';
        }

        $html .=
            '<h3 class="kj-debug-subhead">Repeated queries, maybe they can be one query or cached</h3>' .
            '<table class="kj-debug-table"><thead><tr><th>Query</th><th class="kj-debug-num">Runs</th>' .
            '<th class="kj-debug-num">Time</th></tr></thead><tbody>' .
            $rows .
            '</tbody></table>';
    }

    $html .= '<ol class="kj-debug-queries">';

    foreach ($data['queries'] as $number => $query) {
        $time = (float) $query['time'];
        $values = '';

        foreach ($query['params'] as $name => $value) {
            $values .=
                '<dt>' .
                kleeja_debug_e(is_int($name) ? '?' . ($name + 1) : ':' . ltrim($name, ':')) .
                '</dt><dd>' .
                kleeja_debug_e(
                    json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                ) .
                '</dd>';
        }

        $origin = array_map(
            fn(array $call): string => '<code>' .
                kleeja_debug_e($call['file'] . ':' . $call['line']) .
                '</code>' .
                ($call['function'] !== '' ? ' in <code>' . kleeja_debug_e($call['function']) . '</code>' : ''),
            $query['origin'],
        );

        $html .=
            '<li class="kj-debug-query' .
            ($time >= KLEEJA_DEBUG_SLOW_QUERY ? ' is-slow' : '') .
            '"><div class="kj-debug-query-head">' .
            '<span class="kj-debug-query-num">#' .
            $number .
            '</span>' .
            '<span class="kj-debug-meter" aria-hidden="true" title="' .
            number_format($time * 1000, 3) .
            ' ms"><span style="width: ' .
            ($stats['slowest'] > 0 ? round(($time / $stats['slowest']) * 100, 1) : 0) .
            '%"></span></span>' .
            '<span class="kj-debug-query-time">' .
            kleeja_debug_ms($time) .
            '</span>' .
            ($number === $slowest_key ? '<span class="kj-debug-badge">Slowest</span>' : '') .
            ($time >= KLEEJA_DEBUG_SLOW_QUERY
                ? kleeja_debug_flag('Slow, ' . kleeja_debug_ms(KLEEJA_DEBUG_SLOW_QUERY) . ' or more', 'warning')
                : '') .
            ($stats['repeats'][$query['sql']] > 1
                ? kleeja_debug_flag('Repeated ' . $stats['repeats'][$query['sql']] . '×', 'warning')
                : '') .
            '<button type="button" class="kj-debug-copy" data-copy="' .
            kleeja_debug_e(kleeja_debug_sql_values($query['sql'], $query['params'], $data['env']['database'])) .
            '" title="Copy the query with its values, to run it in a database tool">Copy SQL</button>' .
            '</div><pre class="kj-debug-code"><code>' .
            kleeja_debug_sql_html($query['sql']) .
            '</code></pre>' .
            ($values !== '' ? '<dl class="kj-debug-values">' . $values . '</dl>' : '') .
            ($origin ? '<p class="kj-debug-origin">Called from ' . implode(' ← ', $origin) . '</p>' : '') .
            '</li>';
    }

    return $html . '</ol>';
}

/**
 * The list of PHP warnings, notices and deprecations
 * @param  array  $warnings
 * @return string
 */
function kleeja_debug_warnings(array $warnings): string
{
    if (!$warnings) {
        return '<p class="kj-debug-empty">No PHP warnings, notices or deprecations.</p>';
    }

    $html = '<ul class="kj-debug-warnings">';

    foreach ($warnings as $warning) {
        $html .=
            '<li class="kj-debug-warning' .
            ($warning['suppressed'] ? ' is-suppressed' : '') .
            '"><div class="kj-debug-warning-head">' .
            kleeja_debug_flag($warning['type'], 'warning') .
            ($warning['count'] > 1 ? ' <span class="kj-debug-tag">' . $warning['count'] . '×</span>' : '') .
            ($warning['suppressed'] ? ' <span class="kj-debug-tag" title="Hidden by the @ operator">@</span>' : '') .
            ' <code>' .
            kleeja_debug_e($warning['file'] . ':' . $warning['line']) .
            '</code></div><p>' .
            kleeja_debug_e($warning['message']) .
            '</p></li>';
    }

    return $html . '</ul>';
}

/**
 * The installed plugins and the hooks
 * @param  array  $data
 * @return string
 */
function kleeja_debug_plugins(array $data): string
{
    $icon = fn(string $name): string => '<img src="' .
        kleeja_debug_e($data['plugins'][$name]['icon'] ?? '') .
        '" alt="" width="24" height="24" loading="lazy">';

    $plugins_html = '';

    foreach ($data['plugins'] as $name => $plugin) {
        $plugins_html .=
            '<tr><td><span class="kj-debug-plugin">' .
            $icon($name) .
            kleeja_debug_e($name) .
            '</span></td><td>' .
            kleeja_debug_e($plugin['version']) .
            '</td></tr>';
    }

    $hooks_html = '';

    foreach ($data['hooks'] as $hook => $info) {
        $plugins = '';

        foreach ($info['plugins'] as [$name, $calls, $time]) {
            $plugins .=
                '<span class="kj-debug-plugin" title="' .
                kleeja_debug_e($calls . ' calls') .
                '">' .
                $icon($name) .
                kleeja_debug_e($name) .
                '<span class="kj-debug-plugin-time">' .
                ($calls ? kleeja_debug_ms($time) : 'did not run') .
                '</span></span>';
        }

        $hooks_html .=
            '<tr><td><code>' .
            kleeja_debug_e($hook) .
            '</code></td><td><div class="kj-debug-plugins">' .
            $plugins .
            '</div></td><td class="kj-debug-num" data-label="Runs">' .
            $info['runs'] .
            '</td><td class="kj-debug-num" data-label="Priorities">' .
            kleeja_debug_e(implode(', ', $info['priorities'])) .
            '</td></tr>';
    }

    $runs_html = '';

    foreach ($data['hooks_runs'] as $hook => $runs) {
        $runs_html .=
            '<li><code>' .
            kleeja_debug_e($hook) .
            '</code>' .
            ($runs > 1 ? ' <span>' . $runs . '×</span>' : '') .
            '</li>';
    }

    return '<h3 class="kj-debug-subhead">Installed plugins</h3>' .
        ($plugins_html !== ''
            ? '<table class="kj-debug-table"><thead><tr><th>Name</th><th>Version</th></tr></thead><tbody>' .
                $plugins_html .
                '</tbody></table>'
            : '<p class="kj-debug-empty">No plugins are installed.</p>') .
        '<h3 class="kj-debug-subhead">Hooks of the plugins</h3>' .
        ($hooks_html !== ''
            ? '<table class="kj-debug-table kj-debug-table-stack"><thead><tr><th>Hook</th>' .
                '<th>Plugins (run order) and their time</th><th class="kj-debug-num">Runs</th>' .
                '<th class="kj-debug-num">Priorities</th></tr></thead><tbody>' .
                $hooks_html .
                '</tbody></table>'
            : '<p class="kj-debug-empty">No hooks are registered.</p>') .
        '<details class="kj-debug-more"><summary>All hooks that ran in this request <span class="kj-debug-count">' .
        count($data['hooks_runs']) .
        '</span></summary>' .
        ($runs_html !== ''
            ? '<ul class="kj-debug-hooks-runs">' . $runs_html . '</ul>'
            : '<p class="kj-debug-empty">No hooks ran.</p>') .
        '</details>';
}

/**
 * The templates that were shown
 * @param  array  $templates
 * @return string
 */
function kleeja_debug_templates(array $templates): string
{
    if (!$templates) {
        return '<p class="kj-debug-empty">No templates were shown.</p>';
    }

    $rows = '';

    foreach ($templates as $template) {
        $rows .=
            '<tr><td><code>' .
            kleeja_debug_e($template['name']) .
            '</code></td><td class="kj-debug-num">' .
            kleeja_debug_ms($template['start']) .
            '</td><td class="kj-debug-num">' .
            kleeja_debug_ms($template['time']) .
            '</td></tr>';
    }

    return '<table class="kj-debug-table"><thead><tr><th>Template</th><th class="kj-debug-num">Started at</th>' .
        '<th class="kj-debug-num">Time</th></tr></thead><tbody>' .
        $rows .
        '</tbody></table>';
}

/**
 * Information of the request and the server
 * @param  array  $data
 * @return string
 */
function kleeja_debug_request(array $data): string
{
    $request = $data['request'];
    $env = $data['env'];
    $rows = [
        'Method' => $request['method'],
        'URL' => $request['url'],
        'Status' => $request['status'],
        'Page' => ($request['admin'] ? 'Admin panel' : 'Site') . ($request['ajax'] ? ', AJAX' : ''),
        'User' => $request['user'],
        'Group' => $request['group'],
        'Style' => $request['style'],
        'Language' => $request['language'],
        'Kleeja' => $env['kleeja'],
        'PHP' => $env['php'] . ' (' . $env['sapi'] . ', ' . $env['os'] . ')',
        'Included files' => $env['files'],
        'Gzip' => $env['gzip'] ? 'Enabled' : 'Disabled',
        'Hook system' => $env['hooks'] ? 'Enabled' : 'Disabled',
    ];

    $html = '<dl class="kj-debug-list">';

    foreach ($rows as $label => $value) {
        $html .= '<dt>' . $label . '</dt><dd>' . kleeja_debug_e($value) . '</dd>';
    }

    return $html . '</dl>';
}

/**
 * The query as highlighted HTML, with a new line before the main clauses
 * @param  string $sql
 * @return string
 */
function kleeja_debug_sql_html(string $sql): string
{
    $clauses =
        'SELECT|FROM|WHERE|(?:(?:LEFT|RIGHT|INNER|OUTER|CROSS)\s+)*JOIN|ORDER\s+BY|GROUP\s+BY|HAVING|LIMIT|SET|VALUES|UNION(?:\s+ALL)?';
    $keywords =
        'INSERT\s+INTO|REPLACE\s+INTO|UPDATE|DELETE|AND|OR|NOT|IN|IS|NULL|AS|ON|LIKE|BETWEEN|DESC|ASC|DISTINCT|' .
        'CASE|WHEN|THEN|ELSE|END|EXISTS|COUNT|SUM|MIN|MAX|CREATE|TABLE|ALTER|DROP|INDEX|PRIMARY|KEY|DEFAULT';

    //texts between quotes are split first, so the words inside them are not changed
    $tokens = preg_split(
        '/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|:[a-z_][a-z0-9_]*|\?|\b(?:' .
            $clauses .
            '|' .
            $keywords .
            ')\b|\b\d+(?:\.\d+)?\b)/i',
        trim($sql),
        -1,
        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
    );

    $html = '';

    foreach ($tokens as $token) {
        if (preg_match('/^[\'"]/', $token)) {
            $html .= '<span class="kj-debug-sql-str">' . kleeja_debug_e($token) . '</span>';
        } elseif ($token === '?' || preg_match('/^:[a-z_]/i', $token)) {
            $html .= '<span class="kj-debug-sql-ph">' . kleeja_debug_e($token) . '</span>';
        } elseif (preg_match('/^\d+(?:\.\d+)?$/', $token)) {
            $html .= '<span class="kj-debug-sql-num">' . $token . '</span>';
        } elseif (preg_match('/^(?:' . $clauses . ')$/i', $token)) {
            $html =
                ($html === '' ? '' : rtrim($html) . "\n") .
                '<span class="kj-debug-sql-kw">' .
                preg_replace('/\s+/', ' ', $token) .
                '</span>';
        } elseif (preg_match('/^(?:' . $keywords . ')$/i', $token)) {
            //the case is kept, a keyword can be a name of a column too
            $html .= '<span class="kj-debug-sql-kw">' . preg_replace('/\s+/', ' ', $token) . '</span>';
        } else {
            $html .= kleeja_debug_e(preg_replace('/\s+/', ' ', $token));
        }
    }

    return $html;
}

/**
 * The query with its values in the place of the placeholders, to be copied
 * @param  string $sql
 * @param  array  $params
 * @param  string $driver mysql or sqlite
 * @return string
 */
function kleeja_debug_sql_values(string $sql, array $params, string $driver): string
{
    $quote = fn(mixed $value): string => match (true) {
        $value === null => 'NULL',
        is_bool($value) => $value ? '1' : '0',
        is_int($value), is_float($value) => (string) $value,
        //a backslash is an escape character only in MySQL
        default => "'" .
            str_replace(
                $driver === 'mysql' ? ['\\', "'"] : ["'"],
                $driver === 'mysql' ? ['\\\\', "''"] : ["''"],
                (string) $value,
            ) .
            "'",
    };
    $position = 0;

    return preg_replace_callback(
        '/(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`)|:([a-z_][a-z0-9_]*)|\?/i',
        function (array $m) use ($params, $quote, &$position): string {
            //texts between quotes stay as they are
            if (($m[1] ?? '') !== '') {
                return $m[0];
            }

            if (($m[2] ?? '') !== '') {
                return array_key_exists($m[2], $params) ? $quote($params[$m[2]]) : $m[0];
            }

            return array_key_exists($position, $params) ? $quote($params[$position++]) : $m[0];
        },
        $sql,
    );
}

/**
 * A label with an icon, for warnings and errors, so it is not known by the color only
 * @param  string $label
 * @param  string $type  warning or error
 * @return string
 */
function kleeja_debug_flag(string $label, string $type): string
{
    return '<span class="kj-debug-flag is-' .
        $type .
        '"><svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">' .
        ($type === 'error'
            ? '<circle cx="8" cy="8" r="7"/><path d="M8 4v5M8 11v1.5" stroke="#FFFFFF" stroke-width="2"/>'
            : '<path d="M8 1l7.5 13.5H.5z"/><path d="M8 6v4M8 11.5V13" stroke="#FFFFFF" stroke-width="1.8"/>') .
        '</svg>' .
        kleeja_debug_e($label) .
        '</span>';
}

/**
 * Escape a value for HTML
 * @param  mixed  $value
 * @return string
 */
function kleeja_debug_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Seconds as milliseconds text
 * @param  float  $seconds
 * @return string
 */
function kleeja_debug_ms(float $seconds): string
{
    return number_format($seconds * 1000, 2) . ' ms';
}

/**
 * Opening the panel, hiding the toolbar and copying the queries
 * @return string
 */
function kleeja_debug_js(): string
{
    return <<<'JS'
    (function () {
        var root = document.getElementById('kj-debug');

        if (!root) {
            return;
        }

        //it is only a convenience, the storage can be blocked
        var store = function (value) {
            try {
                if (value === undefined) {
                    return localStorage.getItem('kj-debug-toolbar');
                }

                localStorage.setItem('kj-debug-toolbar', value);
            } catch (e) {}

            return null;
        };

        var toggle = root.querySelector('.kj-debug-toggle');
        var setOpen = function (open) {
            root.classList.toggle('is-open', open);

            if (toggle) {
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        };

        if (root.classList.contains('kj-debug-toolbar') && store() === 'hidden' && !root.classList.contains('is-open')) {
            root.classList.add('is-hidden');
        }

        //the old way, for http sites and when the clipboard is not allowed
        var copyOld = function (text) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();

            var copied = false;

            try {
                copied = document.execCommand('copy');
            } catch (e) {}

            document.body.removeChild(area);

            return copied ? Promise.resolve() : Promise.reject();
        };

        var copy = function (text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text).catch(function () {
                    return copyOld(text);
                });
            }

            return copyOld(text);
        };

        root.addEventListener('click', function (event) {
            var target = event.target.closest('button');

            if (!target || !root.contains(target)) {
                return;
            }

            if (target.classList.contains('kj-debug-toggle')) {
                setOpen(!root.classList.contains('is-open'));
            } else if (target.classList.contains('kj-debug-hide')) {
                setOpen(false);
                root.classList.add('is-hidden');
                store('hidden');
            } else if (target.classList.contains('kj-debug-tab')) {
                root.classList.remove('is-hidden');
                store('shown');
            } else if (target.classList.contains('kj-debug-copy')) {
                var label = target.getAttribute('data-label') || target.textContent;
                target.setAttribute('data-label', label);

                copy(target.getAttribute('data-copy')).then(
                    function () {
                        target.textContent = 'Copied';
                    },
                    function () {
                        target.textContent = 'Can not copy';
                    }
                );

                setTimeout(function () {
                    target.textContent = label;
                }, 1500);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.classList.contains('is-open')) {
                setOpen(false);
            }
        });
    })();
    JS;
}

/**
 * Styles of the debug toolbar and pages, scoped to .kj-debug since it is printed inside the page of the current style
 * @return string
 */
function kleeja_debug_css(): string
{
    return <<<'CSS'
    .kj-debug {
        --kjd-bg: #FFFFFF;
        --kjd-surface: #F6F7F8;
        --kjd-border: #D9DCE0;
        --kjd-text: #0B1F3A;
        --kjd-muted: #546275;
        --kjd-accent: #F45B69;
        --kjd-link: #C84B56;
        --kjd-focus: #0B1F3A;
        --kjd-track: #D9DCE0;
        --kjd-lane-plugins: #546275;
        --kjd-lane-templates: #949CA8;
        --kjd-badge-bg: #FEEBED;
        --kjd-badge-text: #7F2F37;
        --kjd-warning: #C97A12;
        --kjd-warning-bg: #FBF0DC;
        --kjd-error: #C2323F;
        --kjd-error-bg: #FBE8EA;
        --kjd-sql-str: #546275;
        --kjd-shadow: 0 8px 24px rgba(11, 31, 58, .12);
        color: var(--kjd-text);
        font: 14px/1.5 "Inter", "IBM Plex Sans Arabic", system-ui, -apple-system, "Segoe UI", sans-serif;
        text-align: left;
        direction: ltr;
    }
    @media (prefers-color-scheme: dark) {
        .kj-debug {
            --kjd-bg: #0B1F3A;
            --kjd-surface: #23354E;
            --kjd-border: #3C4C61;
            --kjd-text: #FFFFFF;
            --kjd-muted: #BBC0C8;
            --kjd-link: #F45B69;
            --kjd-focus: #F45B69;
            --kjd-track: #3C4C61;
            --kjd-lane-plugins: #BBC0C8;
            --kjd-lane-templates: #949CA8;
            --kjd-badge-bg: #582126;
            --kjd-badge-text: #FCD4D8;
            --kjd-warning: #E0A400;
            --kjd-warning-bg: transparent;
            --kjd-error: #F78C96;
            --kjd-error-bg: transparent;
            --kjd-sql-str: #BBC0C8;
            --kjd-shadow: 0 8px 24px rgba(0, 0, 0, .5);
        }
    }
    /* the style of the page can set the direction of all elements, like * { direction: rtl } */
    .kj-debug *, .kj-debug *::before, .kj-debug *::after { box-sizing: border-box; direction: ltr; }
    .kj-debug a { color: var(--kjd-link); text-decoration: underline; text-underline-offset: 2px; }
    .kj-debug :focus-visible { outline: 2px solid var(--kjd-focus); outline-offset: 2px; }
    .kj-debug button {
        margin: 0; padding: 0; background: none; border: 0; border-radius: 4px;
        color: inherit; font: inherit; line-height: inherit; text-transform: none; cursor: pointer;
    }
    .kj-debug img { max-width: none; margin: 0; border: 0; }
    .kj-debug code { padding: 0; background: none; color: inherit; font: inherit; }
    .kj-debug .kj-debug-title, .kj-debug .kj-debug-subhead {
        margin: 0; padding: 0; border: 0; background: none;
        font-family: inherit; letter-spacing: normal; text-transform: none;
    }

    /* toolbar */
    .kj-debug-toolbar {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147483000;
        display: flex; flex-direction: column;
    }
    .kj-debug-sheet {
        display: none; max-height: calc(85vh - 44px); overflow: auto;
        background: var(--kjd-bg); border-top: 4px solid var(--kjd-accent); box-shadow: var(--kjd-shadow);
    }
    .kj-debug-toolbar.is-open .kj-debug-sheet { display: block; }
    .kj-debug-toolbar .kj-debug-panel { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
    .kj-debug-bar {
        display: flex; align-items: center; gap: 8px; height: 44px; padding: 0 8px 0 12px;
        background: var(--kjd-bg); border-top: 1px solid var(--kjd-border); box-shadow: var(--kjd-shadow);
    }
    .kj-debug .kj-debug-toggle {
        display: flex; align-items: center; gap: 16px; flex: 1; min-width: 0; height: 36px; padding: 0 4px;
        text-align: left;
    }
    /* the items that do not fit go to a second line that is hidden, so none of them is cut in the middle */
    .kj-debug-bar-items {
        display: flex; flex-wrap: wrap; align-items: center; align-content: flex-start; gap: 12px 16px;
        flex: 1; min-width: 0; height: 24px; overflow: hidden;
    }
    .kj-debug-toggle img, .kj-debug-tab img { display: block; width: 24px; height: 24px; }
    .kj-debug-bar-item { flex: none; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .kj-debug-bar-item b { font-weight: 600; }
    .kj-debug-chevron { flex: none; color: var(--kjd-muted); transition: transform 160ms cubic-bezier(.2, .8, .2, 1); }
    .kj-debug-toolbar.is-open .kj-debug-chevron { transform: rotate(180deg); }
    .kj-debug .kj-debug-bar-link { flex: none; padding: 4px 8px; font-size: 13px; }
    .kj-debug .kj-debug-hide { display: flex; flex: none; padding: 8px; color: var(--kjd-muted); }
    .kj-debug .kj-debug-tab {
        display: none; position: fixed; right: 16px; bottom: 16px; padding: 8px;
        background: var(--kjd-bg); border: 1px solid var(--kjd-border); border-radius: 8px; box-shadow: var(--kjd-shadow);
    }
    .kj-debug-toolbar.is-hidden .kj-debug-bar, .kj-debug-toolbar.is-hidden .kj-debug-sheet { display: none; }
    .kj-debug-toolbar.is-hidden .kj-debug-tab { display: block; }
    .kj-debug-spacer { height: 44px; }

    /* history page */
    .kj-debug-body { margin: 0; background: #F6F7F8; }
    @media (prefers-color-scheme: dark) {
        .kj-debug-body { background: #0B1F3A; }
    }
    .kj-debug-page { max-width: 1100px; margin: 0 auto; padding: 32px 16px; }
    .kj-debug-page .kj-debug-panel {
        margin: 0 0 24px; padding: 24px; background: var(--kjd-bg);
        border: 1px solid var(--kjd-border); border-top: 4px solid var(--kjd-accent); border-radius: 8px;
        box-shadow: var(--kjd-shadow);
    }
    .kj-debug-history { max-height: 360px; overflow: auto; }
    .kj-debug .kj-debug-history .kj-debug-table { margin: 0; }
    .kj-debug .kj-debug-history tr.is-current td { background: var(--kjd-surface); }
    .kj-debug .kj-debug-history tr.is-current td:first-child { box-shadow: inset 4px 0 var(--kjd-accent); }
    .kj-debug .kj-debug-page .kj-debug-title { font-size: 20px; }

    /* panel */
    .kj-debug-head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 0 0 24px; }
    .kj-debug-head img { display: block; width: 32px; height: 32px; }
    .kj-debug-head-text { flex: 1; min-width: 0; }
    .kj-debug .kj-debug-title { font-size: 20px; font-weight: 700; line-height: 1.3; color: var(--kjd-text); }
    .kj-debug .kj-debug-subhead { margin: 16px 0 8px; font-size: 13px; font-weight: 600; color: var(--kjd-muted); }
    .kj-debug-subtitle { margin: 2px 0 0; color: var(--kjd-muted); font-size: 13px; overflow-wrap: anywhere; }
    .kj-debug-subtitle code { color: var(--kjd-text); font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; }
    .kj-debug .kj-debug-history-link { font-size: 13px; }
    .kj-debug-tag {
        display: inline-block; padding: 0 6px; border: 1px solid var(--kjd-border); border-radius: 4px;
        color: var(--kjd-muted); font-size: 11px; font-weight: 600; line-height: 18px; vertical-align: 1px;
    }
    .kj-debug-error {
        margin: 0 0 24px; padding: 12px 16px; border: 1px solid var(--kjd-error); border-left-width: 4px;
        border-radius: 4px; background: var(--kjd-error-bg);
    }
    .kj-debug-error p { margin: 8px 0 4px; overflow-wrap: anywhere; }
    .kj-debug-error code { color: var(--kjd-muted); font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
    .kj-debug-stats {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px;
        margin: 0 0 24px; padding: 0;
    }
    .kj-debug-stat {
        min-width: 0; padding: 12px 16px;
        background: var(--kjd-surface); border: 1px solid var(--kjd-border); border-radius: 8px;
    }
    .kj-debug-stat dt { margin: 0; color: var(--kjd-muted); font-size: 12px; font-weight: 500; }
    .kj-debug-stat dd { margin: 0; overflow-wrap: anywhere; }
    .kj-debug-stat-value { font-size: 20px; font-weight: 600; line-height: 1.4; font-variant-numeric: tabular-nums; }
    .kj-debug-stat-note { color: var(--kjd-muted); font-size: 12px; }
    .kj-debug-section { margin: 0; padding: 16px 0 0; border-top: 1px solid var(--kjd-border); }
    .kj-debug-section + .kj-debug-section { margin-top: 16px; }
    .kj-debug-section > summary { margin: 0 0 12px; cursor: pointer; font-size: 16px; font-weight: 600; }
    .kj-debug-section:not([open]) > summary { margin: 0; }
    .kj-debug-count {
        display: inline-block; margin-left: 8px; padding: 0 8px; vertical-align: 1px;
        background: var(--kjd-surface); border: 1px solid var(--kjd-border); border-radius: 999px;
        color: var(--kjd-muted); font-size: 12px; font-weight: 600; font-variant-numeric: tabular-nums;
    }
    .kj-debug-empty, .kj-debug-hint { margin: 0 0 12px; color: var(--kjd-muted); }
    .kj-debug-hint { font-size: 12px; }
    .kj-debug-flag {
        display: inline-flex; align-items: center; gap: 4px; padding: 1px 8px; border-radius: 999px;
        border: 1px solid var(--kjd-warning); background: var(--kjd-warning-bg);
        color: var(--kjd-text); font-size: 12px; font-weight: 600; white-space: nowrap;
    }
    .kj-debug-flag svg { flex: none; fill: var(--kjd-warning); }
    .kj-debug-flag.is-error { border-color: var(--kjd-error); background: var(--kjd-error-bg); }
    .kj-debug-flag.is-error svg { fill: var(--kjd-error); }
    .kj-debug-badge {
        padding: 2px 10px; border-radius: 999px;
        background: var(--kjd-badge-bg); color: var(--kjd-badge-text); font-size: 12px; font-weight: 600;
    }

    /* timeline */
    .kj-debug-timeline { display: grid; grid-template-columns: 80px minmax(0, 1fr); gap: 8px 12px; align-items: center; margin: 0 0 8px; }
    .kj-debug-lane-label { color: var(--kjd-muted); font-size: 12px; font-weight: 600; }
    .kj-debug-lane {
        position: relative; height: 20px; background: var(--kjd-surface); border-radius: 4px;
        background-image: linear-gradient(90deg, var(--kjd-border) 1px, transparent 1px);
        background-size: 25% 100%; background-position: -1px 0;
    }
    .kj-debug-mark { position: absolute; top: 3px; bottom: 3px; min-width: 2px; border-radius: 2px; background: var(--kjd-accent); }
    .kj-debug-mark:hover { outline: 2px solid var(--kjd-bg); box-shadow: 0 0 0 4px var(--kjd-text); z-index: 1; }
    .kj-debug-lane[data-lane="Plugins"] .kj-debug-mark { background: var(--kjd-lane-plugins); }
    .kj-debug-lane[data-lane="Templates"] .kj-debug-mark { background: var(--kjd-lane-templates); }
    .kj-debug-axis { position: relative; height: 16px; color: var(--kjd-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
    .kj-debug-axis span { position: absolute; top: 0; transform: translateX(-50%); white-space: nowrap; }
    .kj-debug-axis span:first-child { transform: none; }
    .kj-debug-axis span:last-child { transform: translateX(-100%); }

    /* queries */
    .kj-debug-queries { display: grid; gap: 12px; margin: 16px 0 12px; padding: 0; list-style: none; }
    .kj-debug-query {
        margin: 0; padding: 12px 16px;
        background: var(--kjd-surface); border: 1px solid var(--kjd-border); border-radius: 8px;
    }
    .kj-debug-query.is-slow { border-left: 4px solid var(--kjd-warning); }
    .kj-debug-query-head {
        display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: 0 0 8px;
        font-variant-numeric: tabular-nums;
    }
    .kj-debug-query-num { min-width: 32px; color: var(--kjd-muted); font-weight: 600; }
    .kj-debug-meter { flex: 0 1 200px; height: 6px; background: var(--kjd-track); }
    .kj-debug-meter > span {
        display: block; height: 100%; min-width: 4px;
        background: var(--kjd-accent); border-radius: 0 3px 3px 0;
    }
    .kj-debug-query.is-slow .kj-debug-meter > span { background: var(--kjd-warning); }
    .kj-debug-query-time { font-weight: 600; }
    .kj-debug .kj-debug-copy {
        margin-left: auto; padding: 2px 10px; border: 1px solid var(--kjd-border);
        background: var(--kjd-bg); color: var(--kjd-text); font-size: 12px; font-weight: 600;
    }
    .kj-debug .kj-debug-copy:hover { border-color: var(--kjd-text); }
    .kj-debug .kj-debug-code {
        max-height: 240px; margin: 0; padding: 12px; overflow: auto;
        background: var(--kjd-bg); color: var(--kjd-text); border: 1px solid var(--kjd-border); border-radius: 4px;
        font: 13px/1.6 "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace;
        white-space: pre-wrap; overflow-wrap: anywhere;
    }
    .kj-debug-sql-kw { color: var(--kjd-link); font-weight: 700; }
    .kj-debug-sql-str { color: var(--kjd-sql-str); font-style: italic; }
    .kj-debug-sql-ph { color: var(--kjd-badge-text); background: var(--kjd-badge-bg); border-radius: 3px; }
    .kj-debug-sql-num { color: var(--kjd-sql-str); }
    .kj-debug-values {
        display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 4px 12px; margin: 8px 0 0;
        font: 12px/1.5 "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .kj-debug-values dt { margin: 0; color: var(--kjd-muted); }
    .kj-debug-values dd { margin: 0; overflow-wrap: anywhere; unicode-bidi: plaintext; }
    .kj-debug-origin { margin: 8px 0 0; color: var(--kjd-muted); font-size: 12px; overflow-wrap: anywhere; }
    .kj-debug-origin code { color: var(--kjd-text); font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; }

    /* warnings */
    .kj-debug-warnings { display: grid; gap: 8px; margin: 0 0 12px; padding: 0; list-style: none; }
    .kj-debug-warning { margin: 0; padding: 8px 12px; background: var(--kjd-surface); border: 1px solid var(--kjd-border); border-radius: 8px; }
    .kj-debug-warning.is-suppressed { opacity: .75; }
    .kj-debug-warning-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .kj-debug-warning-head code { color: var(--kjd-muted); font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
    .kj-debug-warning p { margin: 4px 0 0; overflow-wrap: anywhere; }

    /* tables and lists */
    .kj-debug .kj-debug-table { width: 100%; margin: 0 0 16px; border-collapse: collapse; background: none; }
    .kj-debug .kj-debug-table th, .kj-debug .kj-debug-table td {
        padding: 8px 12px; background: none; color: var(--kjd-text);
        border: 0; border-bottom: 1px solid var(--kjd-border); text-align: left; vertical-align: top;
    }
    .kj-debug .kj-debug-table th { color: var(--kjd-muted); font-size: 12px; font-weight: 600; }
    .kj-debug .kj-debug-table .kj-debug-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .kj-debug-table code { font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }
    .kj-debug .kj-debug-plugin { display: inline-flex; align-items: center; gap: 8px; }
    .kj-debug .kj-debug-plugin img {
        flex: none; width: 24px; height: 24px; padding: 1px;
        background: #FFFFFF; border: 1px solid var(--kjd-border); border-radius: 4px; object-fit: contain;
    }
    .kj-debug-plugin-time { color: var(--kjd-muted); font-size: 12px; font-variant-numeric: tabular-nums; }
    .kj-debug-plugins { display: flex; flex-wrap: wrap; gap: 4px 16px; }
    .kj-debug-more > summary { cursor: pointer; color: var(--kjd-muted); font-size: 13px; font-weight: 600; }
    .kj-debug-hooks-runs { columns: 3 220px; column-gap: 24px; margin: 8px 0 12px; padding: 0; list-style: none; font-size: 12px; }
    .kj-debug-hooks-runs li { overflow-wrap: anywhere; }
    .kj-debug-hooks-runs code { font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; }
    .kj-debug-hooks-runs span { color: var(--kjd-muted); }
    .kj-debug-list { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 4px 24px; margin: 0 0 12px; }
    .kj-debug-list dt { margin: 0; color: var(--kjd-muted); font-size: 13px; }
    .kj-debug-list dd { margin: 0; overflow-wrap: anywhere; }

    @media (max-width: 600px) {
        .kj-debug .kj-debug-toggle, .kj-debug-bar-items { gap: 12px; }
        .kj-debug-page .kj-debug-panel { padding: 16px; }
        .kj-debug-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .kj-debug-meter { flex-basis: 80px; }
        .kj-debug-timeline { grid-template-columns: 64px minmax(0, 1fr); }
        /* one row under the other, the hook names are too long for columns */
        .kj-debug .kj-debug-table-stack, .kj-debug .kj-debug-table-stack tbody { display: block; }
        .kj-debug .kj-debug-table-stack thead { display: none; }
        .kj-debug .kj-debug-table-stack tr { display: block; padding: 8px 0; border-bottom: 1px solid var(--kjd-border); }
        .kj-debug .kj-debug-table.kj-debug-table-stack td { display: block; padding: 4px 0; border: 0; text-align: left; }
        .kj-debug-table-stack td[data-label]::before {
            content: attr(data-label) " "; color: var(--kjd-muted); font-size: 12px; font-weight: 600;
        }
    }
    CSS;
}
