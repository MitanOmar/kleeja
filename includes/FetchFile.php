<?php
/**
 *
 * @package Kleeja
 * @copyright (c) 2007 Kleeja.net
 * @license http://www.kleeja.net/license
 *
 */

//no for directly open
if (!defined('IN_COMMON')) {
    exit();
}

class FetchFile
{
    private string $url;
    private int $timeout = 60;
    private string $destinationPath = '';
    private int $maxRedirects = 3;
    private bool $binary = false;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public static function make(string $url): static
    {
        return new static($url);
    }

    public function setTimeOut(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function setDestinationPath(string $path): static
    {
        $this->destinationPath = $path;

        return $this;
    }

    public function setMaxRedirects(int $limit): static
    {
        $this->maxRedirects = $limit;

        return $this;
    }

    public function isBinaryFile(bool $val): static
    {
        $this->binary = $val;

        return $this;
    }

    /**
     * @return string|bool the content, or true when it was saved to the destination path, false on failure
     */
    public function get(): string|bool
    {
        $fetchType = '';

        $allow_url_fopen = function_exists('ini_get')
            ? strtolower(@ini_get('allow_url_fopen'))
            : strtolower(@get_cfg_var('allow_url_fopen'));

        if (function_exists('curl_init')) {
            $fetchType = 'curl';
        } elseif (in_array($allow_url_fopen, ['on', 'true', '1'])) {
            $fetchType = 'fopen';
        }

        //only a session that was open is opened again, install/update.php has none and its page is printed already
        $had_session = session_status() === PHP_SESSION_ACTIVE;

        session_write_close();

        $result = false;

        extract(runHook('kleeja_fetch_file_start', get_defined_vars()));

        if (!empty($fetchType)) {
            $result = $this->{$fetchType}();
        }

        if ($had_session) {
            $this->finishUp();
        }

        return $result;
    }

    protected function finishUp(): void
    {
        if (defined('KJ_SESSION')) {
            session_id(constant('KJ_SESSION'));
        }

        session_start();
    }

    protected function curl(): string|bool
    {
        $ch = curl_init($this->url);
        //verify the certificate, plugins, styles and updates are extracted over the site files
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0; Kleeja)');
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        if ($this->binary) {
            curl_setopt($ch, CURLOPT_ENCODING, '');
        }

        //let's open new file to save it in.
        if (!empty($this->destinationPath)) {
            $out = fopen($this->destinationPath, 'w');

            if ($out === false) {
                return false;
            }

            curl_setopt($ch, CURLOPT_FILE, $out);
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($result === false) {
                kleeja_log(sprintf("cUrl error (#%d): %s\n", curl_errno($ch), htmlspecialchars(curl_error($ch))));
            } elseif ($status >= 400) {
                kleeja_log(sprintf("FetchFile error (HTTP %d): %s\n", $status, $this->url));
            }

            fclose($out);

            //an error page (404, rate limit ...) is not the file we asked for, so don't leave it behind
            if ($result === false || $status >= 400) {
                kleeja_unlink($this->destinationPath);

                return false;
            }

            return true;
        } else {
            $data = curl_exec($ch);

            if ($data === false) {
                kleeja_log(
                    sprintf("FetchFile error (curl: #%d): %s\n", curl_errno($ch), htmlspecialchars(curl_error($ch))),
                );
            }

            return $data;
        }
    }

    protected function fopen(): string|bool
    {
        // Setup a stream context
        $stream_context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'user_agent' => 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0; Kleeja)',
                'max_redirects' => $this->maxRedirects + 1,
                'timeout' => $this->timeout,
            ],
        ]);

        $content = @file_get_contents($this->url, context: $stream_context);

        // Did we get anything?
        if ($content !== false) {
            if (!empty($this->destinationPath)) {
                $fp2 = fopen($this->destinationPath, 'w' . ($this->binary ? 'b' : ''));
                @fwrite($fp2, $content);
                @fclose($fp2);
                unset($content);

                return true;
            }

            return $content;
        } else {
            $error = error_get_last();
            kleeja_log(sprintf("FetchFile error (stream: #%s): %s\n", $error['type'], $error['message']));
        }

        return false;
    }
}
