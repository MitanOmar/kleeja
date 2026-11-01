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

/**
 *  Detect a bot activity an record it
 */
function kleeja_detecting_bots(): void
{
    global $SQL, $dbprefix, $config;

    // get information ..
    //it stays escaped for SQL, it is given to the anotherbots_onlline_func hook, and plugins may use it in their queries
    $agent = $SQL->escape($_SERVER['HTTP_USER_AGENT'] ?? '');
    $time = time();

    //for stats
    if (strpos($agent, 'Google') !== false) {
        $update_query = [
            'UPDATE' => "{$dbprefix}stats",
            'SET' => 'last_google = :time, google_num = google_num + 1',
            'BIND' => ['time' => $time],
        ];
        extract(runHook('qr_update_google_lst_num', get_defined_vars()));
        $SQL->build($update_query);
    } elseif (strpos($agent, 'Bing') !== false) {
        $update_query = [
            'UPDATE' => "{$dbprefix}stats",
            'SET' => 'last_bing = :time, bing_num = bing_num + 1',
            'BIND' => ['time' => $time],
        ];
        extract(runHook('qr_update_bing_lst_num', get_defined_vars()));
        $SQL->build($update_query);
    }

    //put another bots as a hook if you want !
    extract(runHook('anotherbots_onlline_func', get_defined_vars()));

    //clean online table
    if (time() - $config['last_online_time_update'] >= 3600) {
        //what to add here ?
        //update last_online_time_update
        update_config('last_online_time_update', time());
    }

    extract(runHook('KleejaOnline_func', get_defined_vars()));
}

/**
 * Ban system
 */
function get_ban(): void
{
    global $banss, $lang, $SQL, $usrcp;

    //visitor ip now
    $ip = get_ip();
    $username = $usrcp->name();

    //now .. loop for banned ips
    if (is_array($banss) && (!empty($ip) || !empty($username))) {
        foreach ($banss as $banned_item) {
            $banned_item = trim($banned_item);

            if (empty($banned_item)) {
                continue;
            }

            $is_banned = false;

            //first .. replace all * with something good .

            if (!empty($ip) && strpos($banned_item, '.') !== false) {
                $replace_it = str_replace('*', '([0-9]{1,3})', $banned_item);
                $replace_it = str_replace('.', '\.', $replace_it);

                $is_banned = $ip === $banned_item || @preg_match('/' . preg_quote($replace_it, '/') . '/i', $ip);
            } elseif (
                //both are saved encoded, but not always the same times (older lists encoded every item again at each change)
                !empty($username) &&
                kleeja_html_decode($banned_item) === kleeja_html_decode($username)
            ) {
                $is_banned = true;
            }

            if ($is_banned) {
                extract(runHook('banned_get_ban_func', get_defined_vars()));

                //
                // if the request is an image
                //
                if (
                    (defined('IN_DOWNLOAD') && (ig('img') || ig('thmb') || ig('thmbf') || ig('imgf'))) ||
                    g('go', 'str', '') === 'queue'
                ) {
                    @$SQL->close();
                    $fullname = 'images/banned_user.jpg';
                    $filesize = filesize($fullname);
                    header("Content-length: $filesize");
                    header('Content-type: image/jpg');
                    readfile($fullname);

                    exit();
                } else {
                    kleeja_info($lang['U_R_BANNED'], $lang['U_R_BANNED'], true);
                }
            }
        }
    }

    extract(runHook('get_ban_func', get_defined_vars()));
}

/**
 * Check if the given plugin installed ?
 * @param  string $plugin_name
 * @return int|string|false the plugin id as the database gave it, or false when it is not installed
 */
function kleeja_plugin_exists(string $plugin_name): int|string|false
{
    global $SQL, $dbprefix;

    $query = [
        'SELECT' => 'p.plg_id',
        'FROM' => "{$dbprefix}plugins p",
        'WHERE' => 'p.plg_name = :name',
        'BIND' => ['name' => kleeja_html_encode($plugin_name)],
    ];

    $result = $SQL->build($query);
    $num = $SQL->num_rows($result);

    if ($num) {
        $d = $SQL->fetch($result);
        $SQL->freeresult();

        return $d['plg_id'] ?? false;
    }

    return false;
}

/**
 * Return current page url
 */
function kleeja_get_page(): string
{
    if (isset($_SERVER['REQUEST_URI'])) {
        $location = $_SERVER['REQUEST_URI'];
    } elseif (isset($_ENV['REQUEST_URI'])) {
        $location = $_ENV['REQUEST_URI'];
    } else {
        if (isset($_SERVER['PATH_INFO'])) {
            $location = $_SERVER['PATH_INFO'];
        } elseif (isset($_ENV['PATH_INFO'])) {
            $location = $_SERVER['PATH_INFO'];
        } elseif (isset($_ENV['PHP_SELF'])) {
            $location = $_ENV['PHP_SELF'];
        } else {
            $location = $_SERVER['PHP_SELF'];
        }

        if (isset($_SERVER['QUERY_STRING'])) {
            $location .= '?' . $_SERVER['QUERY_STRING'];
        } elseif (isset($_ENV['QUERY_STRING'])) {
            $location = '?' . $_ENV['QUERY_STRING'];
        }
    }

    $return = str_replace(['&amp;'], ['&'], htmlspecialchars($location));

    return $return;
}

/**
 * Fix email string to be UTF8
 * @param  string $text
 * @return string
 */
function _sm_mk_utf8(string $text): string
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Send an email message, the HTML of includes/mail_template.html with a plain text copy
 * @param  string       $to
 * @param  string|array $body        the blocks of kleeja_mail_template(), or a plain text
 * @param  string       $subject
 * @param  string       $fromAddress
 * @param  string       $fromName
 * @param  string       $bcc
 * @return bool
 */
function send_mail(
    string $to,
    string|array $body,
    string $subject,
    string $fromAddress,
    string $fromName,
    string $bcc = '',
): bool {
    $sending_mail_handled = false;
    $mail_sent = false;

    //the headers are plain text, but the names come from the database encoded, like the site name
    $subject = kleeja_html_decode($subject);
    $fromName = kleeja_html_decode($fromName);

    //a plain text is one text block, and the hooks get $body as a plain text, like before, and $html_body
    $blocks = is_array($body) ? $body : [['type' => 'text', 'content' => $body]];
    $html_body = kleeja_mail_template($blocks);
    $body = kleeja_mail_text($blocks);

    extract(runHook('kleeja_begin_send_mail_func', get_defined_vars()));

    // handling email sending does not mean that it's successfully sent :)
    if ($sending_mail_handled) {
        return $mail_sent;
    }

    $eol = "\r\n";
    $boundary = 'kleeja-' . bin2hex(random_bytes(16));
    $headers = '';
    $headers .=
        'From: ' .
        _sm_mk_utf8(trim(preg_replace('#[\n\r:]+#s', '', $fromName))) .
        ' <' .
        trim(preg_replace('#[\n\r:]+#s', '', $fromAddress)) .
        '>' .
        $eol;
    $headers .= 'MIME-Version: 1.0' . $eol;
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . $eol;
    $headers .= 'X-Mailer: Kleeja Mailer' . $eol;
    $headers .=
        'Reply-To: ' .
        _sm_mk_utf8(trim(preg_replace('#[\n\r:]+#s', '', $fromName))) .
        ' <' .
        trim(preg_replace('#[\n\r:]+#s', '', $fromAddress)) .
        '>' .
        $eol;

    if (!empty($bcc)) {
        $headers .= 'Bcc: ' . trim(preg_replace('#[\n\r:]+#s', '', $bcc)) . $eol;
    }

    extract(runHook('kleeja_send_mail', get_defined_vars()));

    //the plain text goes first, a mail app shows the last part it can read
    $message = '';

    foreach (['text/plain' => $body, 'text/html' => $html_body] as $content_type => $part) {
        $part = preg_replace('#\r\n|\r|\n#', $eol, str_replace("\0", '', $part));

        $message .=
            '--' .
            $boundary .
            $eol .
            'Content-Type: ' .
            $content_type .
            '; charset=utf-8' .
            $eol .
            'Content-Transfer-Encoding: quoted-printable' .
            $eol .
            $eol .
            quoted_printable_encode($part) .
            $eol;
    }

    $message .= '--' . $boundary . '--' . $eol;

    // Change the line breaks used in the headers and the message according to OS
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'MAC') {
        $headers = str_replace("\r\n", "\r", $headers);
        $message = str_replace("\r\n", "\r", $message);
    } elseif (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $headers = str_replace("\r\n", "\n", $headers);
        $message = str_replace("\r\n", "\n", $message);
    }

    extract(runHook('kleeja_send_mail_handle', get_defined_vars()));

    if ($sending_mail_handled) {
        return $mail_sent;
    }

    $mail_sent = @mail(
        trim(preg_replace('#[\n\r]+#s', '', $to)),
        _sm_mk_utf8(trim(preg_replace('#[\n\r]+#s', '', $subject))),
        $message,
        $headers,
    );

    return $mail_sent;
}

/**
 * The blocks of an email that can be sent, see kleeja_mail_template()
 *
 * A block of another type, or one with an empty text, is skipped.
 * @param  array $blocks
 * @return array
 */
function kleeja_mail_blocks(array $blocks): array
{
    $mail_blocks = [];

    foreach ($blocks as $block) {
        $type = $block['type'] ?? '';

        if ($type === 'text' || $type === 'alert') {
            $content = trim((string) ($block['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $mail_block = ['type' => $type, 'content' => $content];

            if ($type === 'alert') {
                $level = $block['level'] ?? 'info';
                $mail_block['level'] = in_array($level, ['info', 'success', 'warning', 'error'], true)
                    ? $level
                    : 'info';
            }

            $mail_blocks[] = $mail_block;
        } elseif ($type === 'button') {
            $link = trim((string) ($block['link'] ?? ''));
            $label = trim((string) ($block['label'] ?? ''));

            if ($link === '' || $label === '') {
                continue;
            }

            //a link without a scheme, like example.com, is a website
            if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $link)) {
                $link = 'https://' . ltrim($link, '/');
            }

            //no javascript: or data: links
            if (!preg_match('#^(https?|mailto):#i', $link)) {
                continue;
            }

            $mail_blocks[] = ['type' => 'button', 'link' => $link, 'label' => $label];
        }
    }

    return $mail_blocks;
}

/**
 * Render the HTML of an email with includes/mail_template.html, from a list of blocks
 *
 *     ['type' => 'text', 'content' => 'Hello']
 *     ['type' => 'button', 'link' => 'https://example.com', 'label' => 'Visit']
 *     ['type' => 'alert', 'content' => 'Careful', 'level' => 'warning']   info (the default), success, warning, error
 *
 * The texts are printed as text, not HTML, and their line breaks are kept.
 * @param  array  $blocks
 * @return string
 */
function kleeja_mail_template(array $blocks): string
{
    global $tpl;

    $mail_blocks = [];

    foreach (kleeja_mail_blocks($blocks) as $block) {
        //the texts may be HTML encoded already, once or twice as Kleeja saves them, so they are decoded first,
        //and the other entities of the language texts, like &raquo;, are kept
        $block = array_map(
            fn($value) => htmlspecialchars(kleeja_html_decode($value), ENT_QUOTES, 'UTF-8', false),
            $block,
        );

        if (isset($block['content'])) {
            $block['content'] = nl2br($block['content']);
        }

        $mail_blocks[] = $block;
    }

    $template_name = 'mail_template';
    $template_path = PATH . 'includes/';

    extract(runHook('kleeja_mail_template_func', get_defined_vars()));

    $tpl->assign('mail_blocks', $mail_blocks);

    return $tpl->display($template_name, $template_path);
}

/**
 * The plain text of an email, from the blocks of kleeja_mail_template()
 * @param  array  $blocks
 * @return string
 */
function kleeja_mail_text(array $blocks): string
{
    $texts = [];

    foreach (kleeja_mail_blocks($blocks) as $block) {
        $text = $block['type'] === 'button' ? $block['label'] . ': ' . $block['link'] : $block['content'];
        $texts[] = html_entity_decode(kleeja_html_decode($text), ENT_QUOTES, 'UTF-8');
    }

    return implode("\n\n", $texts);
}

/**
 * Delete cache
 * @param  string|array $name
 * @param  bool         $all  if true, all cache in cache folder will be deleted
 * @return bool
 */
function delete_cache(string|array $name, bool $all = false): bool
{
    //Those files are exceptions and not for deletion
    //backup.zip is the backup of the last update, it is kept until the next update
    $exceptions = ['.htaccess', 'index.html', 'php.ini', 'web.config', 'backup.zip'];

    //ignore kleeja_log in dev stage.
    if (defined('DEV_STAGE')) {
        array_push($exceptions, 'kleeja_log.log');
    }

    extract(runHook('delete_cache_func', get_defined_vars()));

    //handle array of cached files
    if (is_array($name)) {
        foreach ($name as $n) {
            delete_cache($n, false);
        }

        return true;
    }

    $path_to_cache = PATH . 'cache';

    if ($all) {
        $del = true;

        if ($dh = @opendir($path_to_cache)) {
            while (($file = @readdir($dh)) !== false) {
                if ($file !== '.' && $file !== '..' && !in_array($file, $exceptions)) {
                    kleeja_unlink($path_to_cache . '/' . $file, cache_file: true);
                }
            }
            @closedir($dh);
        }
    } else {
        if (strpos($name, 'tpl_') !== false && strpos($name, '.html') !== false) {
            $name = str_replace('.html', '', $name);
        }

        $del = true;
        $name = str_replace('.php', '', $name) . '.php';

        if (file_exists($path_to_cache . '/' . $name)) {
            $del = kleeja_unlink($path_to_cache . '/' . $name, cache_file: true);
        }
    }

    return $del;
}

/**
 * Try delete files or at least change its name.
 * for those who have dirty hosting
 * @param  string $filePath
 * @param  bool   $cache_file
 * @return bool
 */
function kleeja_unlink(string $filePath, bool $cache_file = false): bool
{
    $return = false;

    extract(runHook('kleeja_unlink_func', get_defined_vars()));

    if ($return) {
        return true;
    }

    //99.9% who use this
    if (function_exists('unlink')) {
        if (is_dir($filePath)) {
            $it = new RecursiveDirectoryIterator($filePath, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            return rmdir($filePath);
        } else {
            return unlink($filePath);
        }
    }
    //just rename cache file if there is new thing
    elseif (function_exists('rename') && $cache_file) {
        $new_name = substr($filePath, 0, strrpos($filePath, '/') + 1) . 'old_' . md5($filePath . time()) . '.php';

        return rename($filePath, $new_name);
    }

    return false;
}

/**
 * Get mime header
 * @param  string $ext file extension
 * @return string mime
 */
function get_mime_for_header(string $ext): string
{
    $mime_types = [
        '323' => 'text/h323',
        'rar' => 'application/x-rar-compressed',
        'acx' => 'application/internet-property-stream',
        'ai' => 'application/postscript',
        'aif' => 'audio/x-aiff',
        'aifc' => 'audio/x-aiff',
        'aiff' => 'audio/x-aiff',
        'asf' => 'video/x-ms-asf',
        'asr' => 'video/x-ms-asf',
        'asx' => 'video/x-ms-asf',
        'au' => 'audio/basic',
        'avi' => 'video/x-msvideo',
        'axs' => 'application/olescript',
        'bas' => 'text/plain',
        'bcpio' => 'application/x-bcpio',
        'bin' => 'application/octet-stream',
        'bmp' => 'image/bmp', // this is not a good mime, but it work anyway
        //"bmp"    => "image/x-ms-bmp", # @see bugs.php.net/47359
        'c' => 'text/plain',
        'cat' => 'application/vnd.ms-pkiseccat',
        'cdf' => 'application/x-cdf',
        'cer' => 'application/x-x509-ca-cert',
        'class' => 'application/octet-stream',
        'clp' => 'application/x-msclip',
        'cmx' => 'image/x-cmx',
        'cod' => 'image/cis-cod',
        'psd' => 'image/psd',
        'cpio' => 'application/x-cpio',
        'crd' => 'application/x-mscardfile',
        'crl' => 'application/pkix-crl',
        'crt' => 'application/x-x509-ca-cert',
        'csh' => 'application/x-csh',
        'css' => 'text/css',
        'dcr' => 'application/x-director',
        'der' => 'application/x-x509-ca-cert',
        'dir' => 'application/x-director',
        'dll' => 'application/x-msdownload',
        'dms' => 'application/octet-stream',
        'doc' => 'application/msword',
        'dot' => 'application/msword',
        'dvi' => 'application/x-dvi',
        'dxr' => 'application/x-director',
        'eps' => 'application/postscript',
        'etx' => 'text/x-setext',
        'evy' => 'application/envoy',
        'exe' => 'application/octet-stream',
        'fif' => 'application/fractals',
        'flr' => 'x-world/x-vrml',
        'gif' => 'image/gif',
        'gtar' => 'application/x-gtar',
        'gz' => 'application/x-gzip',
        'h' => 'text/plain',
        'hdf' => 'application/x-hdf',
        'hlp' => 'application/winhlp',
        'hqx' => 'application/mac-binhex40',
        'hta' => 'application/hta',
        'htc' => 'text/x-component',
        'htm' => 'text/html',
        'html' => 'text/html',
        'htt' => 'text/webviewhtml',
        'ico' => 'image/x-icon',
        'ief' => 'image/ief',
        'iii' => 'application/x-iphone',
        'ins' => 'application/x-internet-signup',
        'isp' => 'application/x-internet-signup',
        'jfif' => 'image/pipeg',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'js' => 'application/x-javascript',
        'latex' => 'application/x-latex',
        'lha' => 'application/octet-stream',
        'lsf' => 'video/x-la-asf',
        'lsx' => 'video/x-la-asf',
        'lzh' => 'application/octet-stream',
        'm13' => 'application/x-msmediaview',
        'm14' => 'application/x-msmediaview',
        'man' => 'application/x-troff-man',
        'mdb' => 'application/x-msaccess',
        'me' => 'application/x-troff-me',
        'mht' => 'message/rfc822',
        'mhtml' => 'message/rfc822',
        'mid' => 'audio/mid',
        'mny' => 'application/x-msmoney',
        'mov' => 'video/quicktime',
        'movie' => 'video/x-sgi-movie',
        'mp2' => 'video/mpeg',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'm4a' => 'audio/mp4',
        'mpa' => 'video/mpeg',
        'mpe' => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
        'amr' => 'audio/3gpp',
        'mpp' => 'application/vnd.ms-project',
        'mpv2' => 'video/mpeg',
        'ms' => 'application/x-troff-ms',
        'mvb' => 'application/x-msmediaview',
        'nws' => 'message/rfc822',
        'oda' => 'application/oda',
        'p10' => 'application/pkcs10',
        'p12' => 'application/x-pkcs12',
        'p7b' => 'application/x-pkcs7-certificates',
        'p7c' => 'application/x-pkcs7-mime',
        'p7m' => 'application/x-pkcs7-mime',
        'p7r' => 'application/x-pkcs7-certreqresp',
        'p7s' => 'application/x-pkcs7-signature',
        'pbm' => 'image/x-portable-bitmap',
        'pdf' => 'application/pdf',
        'pfx' => 'application/x-pkcs12',
        'pgm' => 'image/x-portable-graymap',
        'pko' => 'application/ynd.ms-pkipko',
        'pma' => 'application/x-perfmon',
        'pmc' => 'application/x-perfmon',
        'pml' => 'application/x-perfmon',
        'pmr' => 'application/x-perfmon',
        'pmw' => 'application/x-perfmon',
        'pnm' => 'image/x-portable-anymap',
        'pot' => 'application/vnd.ms-powerpoint',
        'ppm' => 'image/x-portable-pixmap',
        'pps' => 'application/vnd.ms-powerpoint',
        'ppt' => 'application/vnd.ms-powerpoint',
        'prf' => 'application/pics-rules',
        'ps' => 'application/postscript',
        'pub' => 'application/x-mspublisher',
        'qt' => 'video/quicktime',
        'ra' => 'audio/x-pn-realaudio',
        'ram' => 'audio/x-pn-realaudio',
        'ras' => 'image/x-cmu-raster',
        'rgb' => 'image/x-rgb',
        'rmi' => 'audio/mid',
        'roff' => 'application/x-troff',
        'rtf' => 'application/rtf',
        'rtx' => 'text/richtext',
        'swf' => 'application/x-shockwave-flash',
        'scd' => 'application/x-msschedule',
        'sct' => 'text/scriptlet',
        'setpay' => 'application/set-payment-initiation',
        'setreg' => 'application/set-registration-initiation',
        'sh' => 'application/x-sh',
        'shar' => 'application/x-shar',
        'sit' => 'application/x-stuffit',
        'snd' => 'audio/basic',
        'spc' => 'application/x-pkcs7-certificates',
        'spl' => 'application/futuresplash',
        'src' => 'application/x-wais-source',
        'sst' => 'application/vnd.ms-pkicertstore',
        'stl' => 'application/vnd.ms-pkistl',
        'stm' => 'text/html',
        'svg' => 'image/svg+xml',
        'sv4cpio' => 'application/x-sv4cpio',
        'sv4crc' => 'application/x-sv4crc',
        't' => 'application/x-troff',
        'tar' => 'application/x-tar',
        'tcl' => 'application/x-tcl',
        'tex' => 'application/x-tex',
        'texi' => 'application/x-texinfo',
        'texinfo' => 'application/x-texinfo',
        'tgz' => 'application/x-compressed',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'tr' => 'application/x-troff',
        'trm' => 'application/x-msterminal',
        'tsv' => 'text/tab-separated-values',
        'txt' => 'text/plain',
        'uls' => 'text/iuls',
        'ustar' => 'application/x-ustar',
        'vcf' => 'text/x-vcard',
        'vrml' => 'x-world/x-vrml',
        'wav' => 'audio/x-wav',
        'wcm' => 'application/vnd.ms-works',
        'wdb' => 'application/vnd.ms-works',
        'wks' => 'application/vnd.ms-works',
        'wmf' => 'application/x-msmetafile',
        'wps' => 'application/vnd.ms-works',
        'wri' => 'application/x-mswrite',
        'wrl' => 'x-world/x-vrml',
        'wrz' => 'x-world/x-vrml',
        'xaf' => 'x-world/x-vrml',
        'xbm' => 'image/x-xbitmap',
        'xla' => 'application/vnd.ms-excel',
        'xlc' => 'application/vnd.ms-excel',
        'xlm' => 'application/vnd.ms-excel',
        'xls' => 'application/vnd.ms-excel',
        'xlt' => 'application/vnd.ms-excel',
        'xlw' => 'application/vnd.ms-excel',
        'xof' => 'x-world/x-vrml',
        'xpm' => 'image/x-xpixmap',
        'xwd' => 'image/x-xwindowdump',
        'z' => 'application/x-compress',
        'zip' => 'application/zip',
        '3gpp' => 'video/3gpp',
        '3gp' => 'video/3gpp',
        '3gpp2' => 'video/3gpp2',
        '3g2' => 'video/3gpp2',
        'midi' => 'audio/midi',
        'pmd' => 'application/x-pmd',
        'jar' => 'application/java-archive',
        'jad' => 'text/vnd.sun.j2me.app-descriptor',
        'apk' => 'application/vnd.android.package-archive',
        'mkv' => 'video/x-matroska',
        'm3u' => 'text/plain',
        //add more mime here
    ];

    //return mime
    $ext = strtolower($ext);

    if (in_array($ext, array_keys($mime_types))) {
        $return = $mime_types[$ext];
    } else {
        $return = 'application/force-download';
    }

    extract(runHook('get_mime_for_header_func', get_defined_vars()));

    return $return;
}

/**
 * Include language file
 * @param  string $name   language filename, 'acp, common..'
 * @param  string $folder
 * @return bool
 */
function get_lang(string $name, string $folder = ''): bool
{
    global $config, $lang;

    if (is_null($lang) || !is_array($lang)) {
        $lang = [];
    }

    extract(runHook('get_lang_func', get_defined_vars()));

    $name = str_replace('..', '', $name);

    if ($folder !== '') {
        $folder = str_replace(['..', '/'], '', $folder);
        $name = $folder . '/' . $name;
    }

    $path = PATH . 'lang/' . $config['language'] . '/' . str_replace('.php', '', $name) . '.php';

    $lang_to_add = @include_once $path;

    if ($lang_to_add === false) {
        //fallback to English
        $path_en = PATH . 'lang/en/' . str_replace('.php', '', $name) . '.php';
        $lang_to_add = @include_once $path_en;

        if ($lang_to_add === false) {
            big_error(
                'There is no language file in the current path',
                'lang/' . $config['language'] . '/' . str_replace('.php', '', $name) . '.php  not found',
            );
        }
    }

    if (is_array($lang_to_add)) {
        $lang = array_merge($lang, $lang_to_add);
    }

    return true;
}

/**
 * Include translation from the database
 * @param  string $name -> the value of $config['language']
 * @return bool
 */
function get_olang(string $name): bool
{
    global $olang, $dbprefix, $SQL;

    if (is_null($olang) || !is_array($olang)) {
        $olang = [];
    }
    if (!($lang_to_add = cache()->get('data_lang' . $name))) {
        $lang_to_add = [];

        $query = [
            'SELECT' => 'l.word, l.trans',
            'FROM' => "{$dbprefix}lang l",
            'WHERE' => 'l.lang_id = :lang_id',
            'BIND' => ['lang_id' => kleeja_html_encode($name)],
        ];

        extract(runHook('qr_select_lang_cache', get_defined_vars()));

        $result = $SQL->build($query);

        while ($row = $SQL->fetch_array($result)) {
            $lang_to_add[$row['word']] = $row['trans'];
        }

        $SQL->freeresult($result);

        cache()->save('data_lang' . $name, $lang_to_add);
    }

    $olang = array_merge($olang, $lang_to_add);

    return true;
}

/*
 * Get fresh config value
 * some time cache doesn't not work as well, so some important
 * events need fresh version of config values ...
 */
function get_config(string $name): ?string
{
    global $dbprefix, $SQL, $d_groups, $userinfo;

    $table = "{$dbprefix}config c";
    $bind = ['name' => kleeja_html_encode($name)];

    //what if this config is a group-configs related ?
    $group_id_sql = '';

    if (array_key_exists($name, $d_groups[$userinfo['group_id']]['configs'])) {
        $table = "{$dbprefix}groups_data c";
        $group_id_sql = ' AND c.group_id = :group_id';
        $bind['group_id'] = $userinfo['group_id'];
    }

    $query = [
        'SELECT' => 'c.value',
        'FROM' => $table,
        'WHERE' => 'c.name = :name' . $group_id_sql,
        'BIND' => $bind,
    ];

    $result = $SQL->build($query);
    $v = $SQL->fetch($result);
    $SQL->freeresult($result);
    $return = $v['value'] ?? null;

    extract(runHook('get_config_func', get_defined_vars()));

    return $return;
}

/*
 * Add new config option
 * type: where does your config belone, 0 = system, genetal = has no specifc cat., other = other items.
 * html: the input or radio to let the user type or choose from them, see the database:configs to understand.
 * dynamic: every refresh of the page, the config data will be brought from db, not from the cache !
 * plg_id: if this config belong to plugin .. see devKit.
 */
function add_config(
    string $name,
    string $value,
    int $order = 0,
    string $html = '',
    string $type = '0',
    int $plg_id = 0,
    bool $dynamic = false,
): bool {
    global $dbprefix, $SQL, $config, $d_groups;

    if (get_config($name) !== null) {
        return true;
    }

    if ($html !== '' && $type === '0') {
        $type = 'other';
    }

    if ($type === 'groups') {
        //add this option to all groups
        $group_ids = array_keys($d_groups);

        foreach ($group_ids as $g_id) {
            $insert_query = [
                'INSERT' => '`name`, `value`, `group_id`',
                'INTO' => "{$dbprefix}groups_data",
                'VALUES' => ':name, :value, :group_id',
                'BIND' => [
                    'name' => kleeja_html_encode($name),
                    'value' => kleeja_html_encode($value),
                    'group_id' => $g_id,
                ],
            ];

            extract(runHook('insert_sql_add_config_func_groups_data', get_defined_vars()));

            $SQL->build($insert_query);
        }
    }

    $insert_query = [
        'INSERT' => '`name` ,`value` ,`option` ,`display_order`, `type`, `plg_id`, `dynamic`',
        'INTO' => "{$dbprefix}config",
        'VALUES' => ':name, :value, :option, :display_order, :type, :plg_id, :dynamic',
        'BIND' => [
            'name' => kleeja_html_encode($name),
            'value' => kleeja_html_encode($value),
            'option' => $html,
            'display_order' => $order,
            'type' => kleeja_html_encode($type),
            'plg_id' => $plg_id,
            'dynamic' => (int) $dynamic,
        ],
    ];

    extract(runHook('insert_sql_add_config_func', get_defined_vars()));

    $SQL->build($insert_query);

    if ($SQL->affected()) {
        delete_cache('data_config');
        $config[$name] = $value;

        return true;
    }

    return false;
}

/**
 * add an array of new configs
 * @param  array $configs
 * @return bool
 */
function add_config_r(array $configs): bool
{
    //array(name=>array(value=>,order=>,html=>),...);
    foreach ($configs as $n => $m) {
        add_config(
            $n,
            $m['value'] ?? '',
            $m['order'] ?? 0,
            $m['html'] ?? '',
            $m['type'] ?? 'other',
            $m['plg_id'] ?? 0,
            !empty($m['dynamic']),
        );
    }

    return true;
}

/**
 * update a config value
 *
 * @param  string $name
 * @param  string $value
 * @param  bool   $escape HTML encode the value, false to keep it as it is, like a serialized value,
 *                        the values are always bound to the query, so there is no SQL escaping
 * @param  int    $group  group id for group configs, -1 for the group of the current user, 0 for the general config
 * @return bool
 */
function update_config(string $name, string $value, bool $escape = true, int $group = 0): bool
{
    global $SQL, $dbprefix, $d_groups, $userinfo, $config;

    $value = $escape ? kleeja_html_encode($value) : $value;
    $table = "{$dbprefix}config";
    $bind = ['value' => $value, 'name' => kleeja_html_encode($name)];

    //what if this config is a group-configs related ?
    $group_id_sql = '';

    if (array_key_exists($name, $d_groups[$userinfo['group_id']]['configs']) && $group !== 0) {
        $table = "{$dbprefix}groups_data";
        $group_id_sql = ' AND group_id = :group_id';
        $bind['group_id'] = $group === -1 ? $userinfo['group_id'] : $group;
    }

    $update_query = [
        'UPDATE' => $table,
        'SET' => 'value = :value',
        'WHERE' => 'name = :name' . $group_id_sql,
        'BIND' => $bind,
    ];

    extract(runHook('update_sql_update_config_func', get_defined_vars()));

    $SQL->build($update_query);

    if ($SQL->affected()) {
        if ($table === "{$dbprefix}groups_data") {
            $d_groups[$userinfo['group_id']]['configs'][$name] = $value;
            delete_cache('data_groups');

            return true;
        }

        $config[$name] = $value;
        delete_cache('data_config');

        return true;
    }

    return false;
}

/**
 * run the database updates that are newer than the current db version, in order,
 * it stops at the first failed update and keeps db_version before it, so it runs again on the next try
 *
 * @param  array $update_schema      [db version => ['sql' => [name => query], 'functions' => [callables]]]
 * @param  int   $current_db_version
 * @return array the errors, empty if all the updates are done
 */
function kleeja_run_db_updates(array $update_schema, int $current_db_version): array
{
    global $SQL, $dbprefix;

    //the failed queries are collected below, not shown in the error page
    if (!defined('SQL_NO_ERRORS')) {
        define('SQL_NO_ERRORS', true);
    }

    $available_db_updates = array_filter(array_keys($update_schema), function (int $v) use ($current_db_version): bool {
        return $v > $current_db_version;
    });

    sort($available_db_updates);

    //an update that was applied before, but its version was not saved, is not an error
    $done_before = function (array $error, string $sql_content): bool {
        //MySQL: 1060 duplicate column, 1061 duplicate key, 1062 duplicate entry, 1091 dropped column or key
        return in_array((int) ($error[0] ?? 0), [1060, 1061, 1062, 1091], true) ||
            preg_match('/duplicate|already exists|UNIQUE constraint failed/i', (string) ($error[1] ?? '')) === 1 ||
            //SQLite: the column of a DROP COLUMN is gone already
            (preg_match('/\bDROP\s+COLUMN\b/i', $sql_content) === 1 &&
                str_contains((string) ($error[1] ?? ''), 'no such column'));
    };

    $errors = [];

    foreach ($available_db_updates as $db_update_version) {
        foreach ($update_schema[$db_update_version]['sql'] ?? [] as $name => $sql_content) {
            if (!$SQL->query($sql_content) && !$done_before($SQL->get_error(), $sql_content)) {
                $errors[] = $db_update_version . ' - ' . $name . ' : ' . implode(':', $SQL->get_error());
            }
        }

        //stop here, so this version is applied again on the next try
        if (sizeof($errors)) {
            break;
        }

        foreach ($update_schema[$db_update_version]['functions'] ?? [] as $n) {
            if (is_callable($n)) {
                $n();
            }
        }

        $SQL->query("UPDATE `{$dbprefix}config` SET `value` = :version WHERE `name` = 'db_version'", [
            'version' => $db_update_version,
        ]);
    }

    return $errors;
}

// Delete config
function delete_config(string|array $name): bool
{
    if (is_array($name)) {
        foreach ($name as $n) {
            delete_config($n);
        }

        return true;
    }

    global $dbprefix, $SQL, $d_groups, $userinfo;

    //
    // 'IN' doesnt work here with delete, i dont know why ?
    //
    $delete_query = [
        'DELETE' => "{$dbprefix}config",
        'WHERE' => 'name = :name',
        'BIND' => ['name' => kleeja_html_encode($name)],
    ];
    extract(runHook('del_sql_delete_config_func', get_defined_vars()));

    $SQL->build($delete_query);

    if (array_key_exists($name, $d_groups[$userinfo['group_id']]['configs'])) {
        $delete_query = [
            'DELETE' => "{$dbprefix}groups_data",
            'WHERE' => 'name = :name',
            'BIND' => ['name' => kleeja_html_encode($name)],
        ];
        extract(runHook('del_sql_delete_config_func2', get_defined_vars()));

        $SQL->build($delete_query);
    }

    if ($SQL->affected()) {
        return true;
    }

    return false;
}

//
//update words to lang
//
function update_olang(string $name, string $value, string $lang = 'en'): bool
{
    global $SQL, $dbprefix, $olang;

    $update_query = [
        'UPDATE' => "{$dbprefix}lang",
        'SET' => 'trans = :trans',
        'WHERE' => 'word = :word AND lang_id = :lang_id',
        'BIND' => [
            'trans' => kleeja_html_encode($value),
            'word' => kleeja_html_encode($name),
            'lang_id' => kleeja_html_encode($lang),
        ],
    ];
    extract(runHook('update_sql_update_olang_func', get_defined_vars()));

    $SQL->build($update_query);

    if ($SQL->affected()) {
        delete_cache('data_lang' . $lang);
        $olang[$name] = htmlspecialchars($value);

        return true;
    }

    return false;
}

//
//add words to lang
//
function add_olang(array $words = [], string $lang = 'en', int $plg_id = 0): void
{
    global $dbprefix, $SQL;

    foreach ($words as $w => $t) {
        $insert_query = [
            'INSERT' => 'word ,trans ,lang_id, plg_id',
            'INTO' => "{$dbprefix}lang",
            'VALUES' => ':word, :trans, :lang_id, :plg_id',
            'BIND' => [
                'word' => kleeja_html_encode($w),
                'trans' => $t,
                'lang_id' => kleeja_html_encode($lang),
                'plg_id' => $plg_id,
            ],
        ];
        extract(runHook('insert_sql_add_olang_func', get_defined_vars()));
        $SQL->build($insert_query);
    }

    delete_cache('data_lang' . $lang);
}

//
//delete words from lang
//
/**
 * @param  string|array|null $words  language terms to use a in $olang[word] or olang.word, empty for all the terms
 * @param  string|array|null $lang   langauge of given word, empty for all the languages
 * @param  int               $plg_id plugin id associated with these words, optional
 * @return bool
 */
function delete_olang(string|array|null $words = '', string|array|null $lang = 'en', int $plg_id = 0): bool
{
    global $dbprefix, $SQL;

    if (is_array($words)) {
        foreach ($words as $w) {
            delete_olang($w, $lang, $plg_id);
        }

        return true;
    }

    $where = [];

    if (!empty($words)) {
        $where[] = 'word = :word';
    }

    if (!empty($lang)) {
        $where[] = 'lang_id IN (:lang_id)';
    }

    if (!empty($plg_id)) {
        $where[] = 'plg_id = :plg_id';
    }

    $delete_query = [
        'DELETE' => "{$dbprefix}lang",
        'WHERE' => implode(' AND ', $where),
        'BIND' => [
            'word' => kleeja_html_encode((string) $words),
            'lang_id' => array_map('kleeja_html_encode', (array) $lang),
            'plg_id' => $plg_id,
        ],
    ];

    extract(runHook('del_sql_delete_olang_func', get_defined_vars()));

    if (empty($delete_query['WHERE'])) {
        return false;
    }

    $SQL->build($delete_query);

    return (bool) $SQL->affected();
}

/**
 *  Administrator sometime needs some files and delete other ..
 *  we do that for him .. because he has no time .. :)
 * last_down - $config[del_f_day]
 * @param int $from
 */
function klj_clean_old_files(int $from = 0): void
{
    global $config, $SQL, $stat_last_f_del, $dbprefix;

    $return = false;
    extract(runHook('klj_clean_old_files_func', get_defined_vars()));

    if ((int) $config['del_f_day'] <= 0 || $return) {
        return;
    }

    if (!$stat_last_f_del || empty($stat_last_f_del)) {
        $stat_last_f_del = time();
    }

    if (time() - $stat_last_f_del >= 86400) {
        $totaldays = time() - $config['del_f_day'] * 86400;
        $not_today = time() - 86400;

        //This feature will work only if id_form is not empty or direct !
        $query = [
            'SELECT' => 'f.id, f.last_down, f.name, f.type, f.folder, f.time, f.size, f.id_form',
            'FROM' => "{$dbprefix}files f",
            'WHERE' =>
                "f.last_down < :last_down AND f.time < :time AND f.id > :from AND f.id_form <> '' AND f.id_form <> 'direct'",
            'BIND' => ['last_down' => $totaldays, 'time' => $not_today, 'from' => $from],
            'ORDER BY' => 'f.id ASC',
            'LIMIT' => '20',
        ];

        extract(runHook('qr_select_klj_clean_old_files_func', get_defined_vars()));

        $result = $SQL->build($query);

        $num_of_files_to_delete = $SQL->num_rows($result);

        if ($num_of_files_to_delete === 0) {
            //update $stat_last_f_del !!
            $update_query = [
                'UPDATE' => "{$dbprefix}stats",
                'SET' => 'last_f_del = :time',
                'BIND' => ['time' => time()],
            ];

            extract(runHook('qr_update_lstf_del_date_kcof', get_defined_vars()));

            $SQL->build($update_query);
            //delete stats cache
            delete_cache('data_stats');
            update_config('klj_clean_files_from', '0');
            $SQL->freeresult($result);

            return;
        }

        $last_id_from = $files_num = $imgs_num = $real_num = $sizes = 0;
        $ids = [];
        $ex_ids = [];
        //$ex_types = explode(',', $config['livexts']);

        extract(runHook('beforewhile_klj_clean_old_files_func', get_defined_vars()));

        //phpfalcon plugin
        $exlive_types = explode(',', $config['imagefolderexts']);

        //delete files
        while ($row = $SQL->fetch_array($result)) {
            $continue = true;
            $real_num++;
            $last_id_from = $row['id'];
            $is_image = in_array(strtolower(trim($row['type'])), ['gif', 'jpg', 'jpeg', 'bmp', 'png']) ? true : false;

            /*
            //exceptions
            if(in_array($row['type'], $ex_types) || $config['id_form'] == 'direct')
            {
                $ex_ids[] = $row['id'];
                continue;
            }
            */

            //exceptions
            //if($config['id_form'] == 'direct')
            //{
            //$ex_ids[] = $row['id'];
            //move on
            //continue;
            //}

            //your exepctions
            extract(runHook('while_klj_clean_old_files_func', get_defined_vars()));

            if ($continue) {
                //delete from folder ..
                if (file_exists($row['folder'] . '/' . $row['name'])) {
                    @kleeja_unlink($row['folder'] . '/' . $row['name']);
                }

                //delete thumb
                if (file_exists($row['folder'] . '/thumbs/' . $row['name'])) {
                    @kleeja_unlink($row['folder'] . '/thumbs/' . $row['name']);
                }

                $ids[] = $row['id'];

                if ($is_image) {
                    $imgs_num++;
                } else {
                    $files_num++;
                }
                $sizes += $row['size'];
            }
        } //END WHILE

        $SQL->freeresult($result);

        if (sizeof($ex_ids)) {
            $update_query = [
                'UPDATE' => "{$dbprefix}files",
                'SET' => 'last_down = :last_down',
                'WHERE' => 'id IN (:ids)',
                'BIND' => ['last_down' => time() + 2 * 86400, 'ids' => $ex_ids],
            ];
            extract(runHook('qr_update_lstdown_old_files', get_defined_vars()));
            $SQL->build($update_query);
        }

        if (sizeof($ids)) {
            $query_del = [
                'DELETE' => "{$dbprefix}files",
                'WHERE' => 'id IN (:ids)',
                'BIND' => ['ids' => $ids],
            ];

            //update number of stats
            $update_query = [
                'UPDATE' => "{$dbprefix}stats",
                'SET' => 'sizes = sizes - :sizes, files = files - :files, imgs = imgs - :imgs',
                'BIND' => ['sizes' => $sizes, 'files' => $files_num, 'imgs' => $imgs_num],
            ];

            extract(runHook('qr_del_delf_old_files', get_defined_vars()));

            $SQL->build($query_del);
            $SQL->build($update_query);
        }

        update_config('klj_clean_files_from', $last_id_from);
    } //stat_del
}

/**
 * klj_clean_old
 * @param string         $table database table
 * @param string $for   can be 'all, or a number of days like 30'
 */
function klj_clean_old(string $table, string $for = 'all'): void
{
    global $SQL, $config, $dbprefix;

    $days = time() - 3600 * 24 * intval($for);

    $query = [
        'SELECT' => 'f.id, f.time',
        'FROM' => "`{$dbprefix}" . $table . '` f',
        'ORDER BY' => 'f.id ASC',
        'LIMIT' => '30',
    ];

    if ($for !== 'all') {
        $query['WHERE'] = 'f.time < :time';
        $query['BIND'] = ['time' => $days];
    }

    extract(runHook('qr_select_klj_clean_old_func', get_defined_vars()));

    $result = $SQL->build($query);
    $num_to_delete = $SQL->num_rows($result);

    if ($num_to_delete === 0) {
        $t = $table === 'call' ? 'calls' : $table;
        update_config('queue', preg_match('/:del_' . $for . $t . ':/i', '', $config['queue']));
        $SQL->freeresult($result);

        return;
    }

    $ids = [];
    while ($row = $SQL->fetch_array($result)) {
        $ids[] = $row['id'];
    }

    $SQL->freeresult($result);

    $query_del = [
        'DELETE' => '`' . $dbprefix . $table . '`',
        'WHERE' => 'id IN (:ids)',
        'BIND' => ['ids' => $ids],
    ];

    extract(runHook('qr_del_delf_old_table', get_defined_vars()));

    $SQL->build($query_del);
}

/**
 * get_ip() for the user
 */
function get_ip(): string
{
    $ip = '';

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    //if IP chain
    if (strpos($ip, ',') !== false) {
        $ip = explode(',', $ip);
        $ip = trim($ip[0]);
    }

    //is it IPv6?
    $ip_v6 = preg_match('/^[0-9a-f]{1,4}:([0-9a-f]{0,4}:){1,6}[0-9a-f]{1,4}$/', $ip);

    if ($ip_v6) {
        //does it IPv4 hide in a IPv6 style
        if (stripos($ip, '::ffff:') === 0) {
            $ip = substr($ip, 7);
        }
    }

    $return = preg_replace('/[^0-9a-z.:]/i', '', $ip);
    extract(runHook('kleeja_get_ip_func', get_defined_vars()));

    return $return;
}

/**
 * Check and verify captcha field after submit
 * @return bool
 */
function kleeja_check_captcha(): bool
{
    global $config;

    if ((int) $config['enable_captcha'] === 0 && !defined('IN_REAL_INDEX') && !defined('IN_ADMIN')) {
        return true;
    }

    $return = false;

    if (!empty($_SESSION['klj_sec_code']) && ip('kleeja_code_answer')) {
        if ($_SESSION['klj_sec_code'] === trim(p('kleeja_code_answer'))) {
            $return = true;
        }

        //one answer for each image, so the codes can't be tried one after another
        unset($_SESSION['klj_sec_code']);
    }

    extract(runHook('kleeja_check_captcha_func', get_defined_vars()));

    return $return;
}

/**
 * For logging and testing, enabled only for DEV_STAGE!
 * @param string $text a string to log
 */
function kleeja_log(string $text): void
{
    if (!defined('DEV_STAGE')) {
        return;
    }

    file_put_contents(
        __DIR__ . '/../cache/kleeja_log.log',
        date_format(date_create(), 'Y-m-d h:i:s.ua') . ' | INFO | ' . $text . PHP_EOL,
        FILE_APPEND | LOCK_EX,
    );
}

/**
 * Return the first and last seek of range to be flushed.
 * @param  string $range
 * @param  int    $fileSize
 * @return array
 */
function kleeja_set_range(string $range, int $fileSize): array
{
    $dash = strpos($range, '-');
    $first = trim(substr($range, 0, $dash));
    $last = trim(substr($range, $dash + 1));

    if (!$first) {
        $suffix = $last;
        $last = $fileSize - 1;
        $first = $fileSize - $suffix;

        if ($first < 0) {
            $first = 0;
        }
    } else {
        if (!$last || $last > $fileSize - 1) {
            $last = $fileSize - 1;
        }
    }

    if ($first > $last) {
        //unsatisfiable range
        header('Status: 416 Requested range not satisfiable');
        header("Content-Range: */$fileSize");

        exit();
    }

    return [$first, $last];
}

/**
 * Outputs up to $bytes from the file $file to standard output,
 * $buffer_size bytes at a time.
 * @param resource $file
 * @param integer  $bytes
 * @param integer  $buffer_size
 */
function kleeja_buffered_range($file, int $bytes, int $buffer_size = 1024): void
{
    $bytes_left = $bytes;
    while ($bytes_left > 0 && !feof($file)) {
        if ($bytes_left > $buffer_size) {
            $bytes_to_read = $buffer_size;
        } else {
            $bytes_to_read = $bytes_left;
        }

        $bytes_left -= $bytes_to_read;
        $contents = fread($file, $bytes_to_read);
        echo $contents;
        @flush();
        @ob_flush();
    }
}

/**
 * user_can, used for checking the acl for the current user
 * @param  string $acl_name
 * @param  int    $group_id
 * @return bool
 */
function user_can(string $acl_name, int $group_id = 0): bool
{
    global $d_groups, $userinfo;

    if ($group_id === 0) {
        $group_id = $userinfo['group_id'];
    }

    return (bool) $d_groups[$group_id]['acls'][$acl_name];
}

function ig(string $name): bool
{
    return isset($_GET[$name]);
}

function ip(string $name): bool
{
    return isset($_POST[$name]);
}

//an array given instead of a single value (name[]=...) is treated as not sent, so $default is returned
function g(string $name, string $type = 'str', string $default = ''): string|int
{
    if (isset($_GET[$name]) && is_string($_GET[$name])) {
        return $type === 'str' ? htmlspecialchars($_GET[$name], ENT_QUOTES) : intval($_GET[$name]);
    }

    return $type === 'str' ? htmlspecialchars($default, ENT_QUOTES) : intval($default);
}

//an array given instead of a single value (name[]=...) is treated as not sent, so $default is returned
function p(string $name, string $type = 'str', string $default = ''): string|int
{
    if (isset($_POST[$name]) && is_string($_POST[$name])) {
        return $type === 'str'
            ? str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], htmlspecialchars(trim($_POST[$name]), ENT_QUOTES))
            : intval($_POST[$name]);
    }

    return $type === 'str' ? htmlspecialchars($default) : intval($default);
}

/**
 * encode a text for HTML, as Kleeja keeps the texts in the database,
 * it is what $SQL->escape() did before the SQL part, so the values stay the same as the saved ones
 *
 * @param  string|null $text
 * @return string
 */
function kleeja_html_encode(?string $text): string
{
    return htmlspecialchars($text ?? '', ENT_QUOTES);
}

/**
 * the text as it was typed, from a text that is HTML encoded once or more.
 * p() encodes what it reads, and the text is encoded again when it is saved, so some texts
 * are kept encoded twice in the database and others once. It decodes until nothing is left to decode
 *
 * @param  string|null $text
 * @return string
 */
function kleeja_html_decode(?string $text): string
{
    $text ??= '';

    //every decoding makes the text shorter, so it ends
    while (($decoded = htmlspecialchars_decode($text, ENT_QUOTES)) !== $text) {
        $text = $decoded;
    }

    return $text;
}

/**
 * a text from the database, encoded once to be printed in HTML, whatever times it was encoded when it was saved.
 * the database is not changed, it is fixed when it is shown
 *
 * @param  string|null $text
 * @return string
 */
function kleeja_html_display(?string $text): string
{
    return kleeja_html_encode(kleeja_html_decode($text));
}

/**
 * add rewrite rules to the serve.php file
 * @param  array|string $rules
 * @param  string       $unique_id useful for the deletion later
 * @return bool
 */
function add_to_serve_rules(string|array $rules, string $unique_id = ''): bool
{
    if (!file_exists(PATH . 'plugins_rules.php')) {
        if (!is_writable(PATH)) {
            chmod(PATH, K_DIR_CHMOD);
        }

        file_put_contents(PATH . 'plugins_rules.php', '<?php return [' . PHP_EOL . '];');
    }

    $current_serve_content = file_get_contents(PATH . 'plugins_rules.php');

    $rules = is_array($rules) ? implode(PHP_EOL, $rules) : $rules;

    if (!empty($unique_id)) {
        $rules = '#start_' . $unique_id . PHP_EOL . $rules . PHP_EOL . '#end_' . $unique_id;
    }

    $current_serve_content = preg_replace('/return\s{0,4}\[/', 'return [' . PHP_EOL . $rules, $current_serve_content);

    if (!is_writable(PATH . 'plugins_rules.php')) {
        chmod(PATH . 'plugins_rules.php', K_FILE_CHMOD);
    }

    file_put_contents(PATH . 'plugins_rules.php', $current_serve_content);

    return true;
}

/**
 * remove rewrite rules by previously set unique id
 * @param  string $unique_id
 * @return bool
 */
function remove_from_serve_rules(string $unique_id): bool
{
    $file = PATH . 'plugins_rules.php';

    $current_serve_content = file_get_contents($file);

    $new_serve_content = preg_replace(
        '/^#start_' . preg_quote($unique_id) . '.*' . '#end_' . preg_quote($unique_id) . '$/sm',
        '',
        $current_serve_content,
    );

    if ($new_serve_content === $current_serve_content) {
        return false;
    }

    if (!is_writable(PATH . 'plugins_rules.php')) {
        chmod(PATH . 'plugins_rules.php', K_FILE_CHMOD);
    }

    file_put_contents($file, $new_serve_content);

    return true;
}

/**
 * parse rewrite rule. currently added separately for plugins
 * @param  string $regex
 * @param  string $args
 * @param  bool   $is_unicode
 * @return bool
 */
function parse_serve_rule(string $regex, string $args, bool $is_unicode = false): bool
{
    $request_uri = urldecode(trim(strtok($_SERVER['REQUEST_URI'], '?'), '/'));

    if (preg_match("/{$regex}/" . ($is_unicode ? 'u' : ''), $request_uri, $matches)) {
        if (!empty($args)) {
            parse_str($args, $parsed_args);

            foreach ($parsed_args as $arg_key => $arg_value) {
                if (preg_match('/^\$/', $arg_value)) {
                    $match_number = ltrim($arg_value, '$');

                    if (isset($matches[$match_number])) {
                        $_GET[$arg_key] = $matches[$match_number];
                    }
                } else {
                    $_GET[$arg_key] = $arg_value;
                }
            }
        }

        return true;
    }

    return false;
}
