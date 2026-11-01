<?php
/**
 *
 * @package adm
 * @copyright (c) 2007 Kleeja.net
 * @license ./docs/license.txt
 *
 */

// not for directly open
if (!defined('IN_ADMIN')) {
    exit();
}

//for style ..
$stylee = 'admin_reports';
$current_smt = preg_replace('/[^a-z0-9_]/i', '', g('smt', default: 'general'));
$action =
    basename(ADMIN_PATH) .
    '?cp=' .
    basename(__FILE__, '.php') .
    '&amp;page=' .
    (ig('page') ? g('page', 'int') : 1) .
    '&amp;smt=' .
    $current_smt;
$msg_sent = ig('sent') ? g('sent', 'int') : false;
$H_FORM_KEYS = kleeja_add_form_key('adm_reports');
$GET_FORM_KEY = kleeja_add_form_key_get('adm_reports_get');
$there_queue = preg_match('!:del_[a-z0-9]{0,3}reports:!i', $config['queue']);

//
// Check form key
//
if (ip('submit')) {
    if (!kleeja_check_form_key('adm_reports')) {
        kleeja_admin_err($lang['INVALID_FORM_KEY'], title: $lang['ERROR'], redirect: $action, rs: 1);
    }
}

//add delete process to the queue
if ($current_smt === 'del_d30' || $current_smt === 'del_all') {
    //check _GET Csrf token
    if (!kleeja_check_form_key_get('adm_reports_get')) {
        http_response_code(401);
        kleeja_admin_err(
            $lang['INVALID_GET_KEY'],
            title: $lang['ERROR'],
            redirect: basename(ADMIN_PATH) . '?cp=' . basename(__FILE__, '.php'),
            rs: 1,
        );
    }

    if (strpos($config['queue'], ':' . $current_smt . 'reports:') !== false) {
        kleeja_admin_err(
            $lang['DELETE_PROCESS_IN_WORK'],
            title: $lang['ERROR'],
            redirect: basename(ADMIN_PATH) . '?cp=' . basename(__FILE__, '.php'),
            rs: 1,
        );
    } else {
        update_config('queue', $config['queue'] . ':' . $current_smt . 'reports:');
        kleeja_admin_info(
            $lang['DELETE_PROCESS_QUEUED'],
            redirect: basename(ADMIN_PATH) . '?cp=' . basename(__FILE__, '.php'),
        );
    }
}

$query = [
    'SELECT' => '*',
    'FROM' => "{$dbprefix}reports r",
    'ORDER BY' => 'r.id DESC',
];

if ($current_smt === 'show_h24') {
    $query['WHERE'] = 'r.time > :time';
    $query['BIND'] = ['time' => time() - 3600 * 24];
}

$result = $SQL->build($query);

//pagination
$nums_rows = $SQL->num_rows($result);
$currentPage = ig('page') ? g('page', 'int') : 1;
$Pager = new Pagination($perpage, $nums_rows, $currentPage);
$start = $Pager->getStartRow();

$no_results = false;
$del_nums = [];

if ($nums_rows > 0) {
    $query['LIMIT'] = ':start, :perpage';
    $query['BIND']['start'] = $start;
    $query['BIND']['perpage'] = $perpage;
    $result = $SQL->build($query);

    while ($row = $SQL->fetch_array($result)) {
        //make new lovely arrays !!
        $arr[] = [
            'id' => $row['id'],
            //saved encoded twice by go.php, older reports once
            'name' => kleeja_html_display($row['name']),
            'mail' => $row['mail'],
            'url' => kleeja_html_display($row['url']),
            //only http and https are links, a javascript: link of an older report is shown as a text
            'url_is_link' => (bool) preg_match('#^https?://#i', kleeja_html_decode($row['url'])),
            'text' => kleeja_html_display($row['text']),
            'human_time' => kleeja_date($row['time']),
            'time' => kleeja_date($row['time'], human_time: false),
            'ip' => $row['ip'],
            'sent' => (int) $row['id'] === $msg_sent,
            'ip_finder' => 'https://ipinfo.io/' . htmlspecialchars($row['ip']),
        ];

        $del[$row['id']] = p('del_' . $row['id']);
        $sen[$row['id']] = p('v_' . $row['id']);

        //when submit !!
        if (ip('submit')) {
            if ($del[$row['id']]) {
                $del_nums[] = $row['id'];
            }
        }

        if (ip('reply_submit')) {
            if ($sen[$row['id']]) {
                $to = $row['mail'];
                $subject = sprintf($lang['MAIL_REPORT_REPLY_SUBJECT'], $config['sitename']);
                $message = [
                    ['type' => 'text', 'content' => sprintf($lang['MAIL_HELLO'], $row['name'])],
                    ['type' => 'text', 'content' => sprintf($lang['MAIL_REPORT_REPLY_INTRO'], $config['sitename'])],
                    ['type' => 'alert', 'content' => $sen[$row['id']]],
                    //the quote is a block of its own, so it takes the direction of its own language
                    ['type' => 'text', 'content' => $lang['MAIL_YOUR_REPORT']],
                    ['type' => 'text', 'content' => $row['text']],
                    ['type' => 'text', 'content' => $lang['URL'] . ': ' . $row['url']],
                    ['type' => 'text', 'content' => sprintf($lang['MAIL_REGARDS'], $config['sitename'])],
                ];

                $send = send_mail($to, $message, $subject, $config['sitemail'], $config['sitename']);

                if ($send) {
                    //
                    //We will redirect to pages of results and show info msg there !
                    //
                    kleeja_admin_info(
                        $lang['IS_SEND_MAIL'],
                        redirect: basename(ADMIN_PATH) .
                            '?cp=' .
                            basename(__FILE__, '.php') .
                            '&page=' .
                            (ig('page') ? g('page', 'int') : 1) .
                            '&sent=' .
                            $row['id'],
                    );
                } else {
                    kleeja_admin_err(
                        $lang['ERR_SEND_MAIL'],
                        redirect: basename(ADMIN_PATH) .
                            '?cp=' .
                            basename(__FILE__, '.php') .
                            '&page=' .
                            (ig('page') ? g('page', 'int') : 1) .
                            '&sent=' .
                            $row['id'],
                    );
                }
            }
        }
    }
    $SQL->freeresult($result);
} else {
    //num rows
    $no_results = true;
}

//if deleted
if (sizeof($del_nums)) {
    $query_del = [
        'DELETE' => "{$dbprefix}reports",
        'WHERE' => 'id IN (:ids)',
        'BIND' => ['ids' => $del_nums],
    ];

    $SQL->build($query_del);
}

$total_pages = $Pager->getTotalPages();
$page_nums = $Pager->print_nums(basename(ADMIN_PATH) . '?cp=' . basename(__FILE__, '.php'));

//after submit
if (ip('submit')) {
    $text = $SQL->affected() ? $lang['REPORTS_UPDATED'] : $lang['NO_UP_CHANGE_S'];
    $text .=
        '<script type="text/javascript"> setTimeout("get_kleeja_link(\'' .
        $action .
        '\'); check_msg_and_reports();", 2000);</script>' .
        "\n";
    kleeja_admin_info($text, redirect: $action);
}

//secondary menu
$go_menu = [
    'general' => [
        'name' => $lang['R_REPORTS'],
        'link' => basename(ADMIN_PATH) . '?cp=f_reports&amp;smt=general',
        'goto' => 'general',
        'current' => $current_smt === 'general',
    ],
    'show_h24' => [
        'name' => $lang['SHOW_FROM_24H'],
        'link' => basename(ADMIN_PATH) . '?cp=f_reports&amp;smt=show_h24',
        'goto' => 'show_h24',
        'current' => $current_smt === 'show_h24',
    ],
    //TODO : CHECK IF IT'S ALREADY DONE ?
    'del_d30' => [
        'name' => $lang['DELETE_EARLIER_30DAYS'],
        'link' => basename(ADMIN_PATH) . '?cp=f_reports&amp;smt=del_d30&amp;' . $GET_FORM_KEY,
        'goto' => 'del_d30',
        'current' => $current_smt === 'del_d30',
        'confirm' => true,
    ],
    'del_all' => [
        'name' => $lang['DELETE_ALL'],
        'link' => basename(ADMIN_PATH) . '?cp=f_reports&amp;smt=del_all&amp;' . $GET_FORM_KEY,
        'goto' => 'del_all',
        'current' => $current_smt === 'del_all',
        'confirm' => true,
    ],
];
