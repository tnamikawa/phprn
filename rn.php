<?php

# SETTING
error_reporting(E_ALL);
mb_language('uni');
date_default_timezone_set('Asia/Tokyo');

# ERROR HANDLER
function runtime_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
  echo "RUNTIME ERROR $errfile:$errline:$errstr\n";
  die;
}
set_error_handler('runtime_error_handler');

# ASSERTION HANDLER
function assertion_handler($errfile, $errline, $err1 = '', $err2 = '', $err3 = '') {
  print "ASSERTION ERROR $errfile:$errline:$err1:$err2:$err3\n";
  die;
}
assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_BAIL, 0);
assert_options(ASSERT_CALLBACK, 'assertion_handler');

class Http {
  private $cookies = array();
  private $referer = '';

  public function Http($agent = null) {
    if (! is_null($agent)) {
      $this->agent = $agent;
    }
  }

  public function get($url, $referer = '', $redirect_cnt = 3) {
    Log::i('Http->get(' . Log::dump($url) . ', ' . Log::dump($referer) . ', ' . Log::dump($redirect_cnt) . ')');
    return $this->request('GET', $url, null, $referer, $redirect_cnt, null);
  }

  public function getAndStore($url, $path, $referer = '', $redirect_cnt = 3) {
    Log::i('Http->getAndStore(' . Log::dump($url) . ', ' . Log::dump($path) . ', ' . Log::dump($referer) . ', ' . Log::dump($redirect_cnt) . ')');
    return $this->request('GET', $url, null, $referer, $redirect_cnt, $path);
  }

  public function getHeaders() {
    return $this->headers;
  }

  public function post($url, $buff, $referer = '', $redirect_cnt = 3) {
    Log::i('Http->post(' . Log::dump($url) . ', ' . Log::dump($buff) . ', ' . Log::dump($referer) . ', ' . Log::dump($redirect_cnt) . ')');
    return $this->request('POST', $url, $buff, $referer, $redirect_cnt, null);
  }

  public function postAndStore($url, $buff, $path, $referer = '', $redirect_cnt = 3) {
    Log::i('Http->post(' . Log::dump($url) . ', ' . Log::dump($buff) . ', ' . Log::dump($path) . ', ' . Log::dump($referer) . ', ' . Log::dump($redirect_cnt) . ')');
    return $this->request('POST', $url, $buff, $referer, $redirect_cnt, $path);
  }

  // private

  private $agent = 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/536.5 (KHTML, like Gecko) Chrome/19.0.1084.46 Safari/536.5';
  private $headers = array();

  private function request($method, $url, $buff, $referer, $redirect_cnt, $path = null) {
    Log::i('Http->request(' . Log::dump($method) . ', ' . Log::dump($url) . ', ' . Log::dump($buff) . ', ' . Log::dump($referer) . ', ' . Log::dump($redirect_cnt) . ', ' . Log::dump($path) . ')');
    $this->headers = array();

    $url_info = parse_url($url);
    $port = isset($url_info['port']) ? $url_info['port'] : 80;

    if ('https' === $url_info['scheme']) {
      return $this->requestSsl($method, $url, $buff, $referer, $path);
    }

    $errno = null;
    $errstr = null;
    $sock = fsockopen($url_info['host'], $port, $errno, $errstr, 25);
    if (! $sock) {
      return false;
    }

    $requestpath = $url_info['path'];
    if (isset($url_info['query'])) {
      $requestpath .= '?' . $url_info['query'];
    }

    fputs($sock, "$method $requestpath HTTP/1.0\r\n");
    fputs($sock, "Accept: text/css, text/plain, text/html, image/gif, image/jpeg, image/png, application/x-shockwave-flash, */*\r\n");
    fputs($sock, "Accept-Language: ja\r\n");
    fputs($sock, "User-Agent: " . $this->agent . "\r\n");

    if (0 < count($this->cookies)) {
      fputs($sock, 'Cookie: ' . $this->getRequestCookieValue() . "\r\n");
    }

    if (strlen($referer)) {
      fputs($sock, "Referer: $referer\r\n");
    }
    fputs($sock, "Host: " . $url_info['host'] . "\r\n");
    if ('POST' === $method) {
      fputs($sock, "Content-Type: application/x-www-form-urlencoded\r\n");
      fputs($sock, "Content-Length: " . strlen($buff) . "\r\n");
    }
    fputs($sock, "Connection: Close\r\n");
    fputs($sock, "\r\n");
    if ('POST' === $method) {
      fputs($sock, $buff);
    }

    $status_code = null;
    $this->cookies = array();
    $head = '';
    while ($head != "\r\n" && (! feof($sock))) {
      $head = fgets($sock, 8192);
      $lhead = strtolower($head);

      // ステータスコード取得
      if (is_null($status_code)) {
        if (0 !== strpos($lhead, 'http/')) {
          return false;
        };
        $status_code = trim(preg_replace('/^http[\d\.\/]+\s+(\d{3})\s+.*$/', '\1', $lhead));
        $this->headers['status_code'] = $status_code;
        continue;
      }

      $tmp = null;
      if (preg_match('/^([\w\-]+)\s*:\s*(.+)$/', $head, $tmp)) {
        $k = strtolower($tmp[1]);
        $v = trim($tmp[2]);

        if ($k === 'set-cookie') {
          $tmp = null;
          if (preg_match('/^(\w+)=([^;\r\n\s]+)/', $v, $tmp)) {
            $this->cookies[$tmp[1]] = $tmp[2];
          }
        }

        $this->headers[$k] = $v;
      }
    }

    Log::i('Header: ' . Log::dump($this->headers));
    Log::i('Cookie: ' . Log::dump($this->cookies));

    // リダイレクト
    if (isset($this->headers['location']) && $url !== $this->headers['location']) {
      if (! $redirect_cnt) {
        return false;
      }
      return $this->request('GET', $this->headers['location'], null, $referer, $redirect_cnt - 1, $path);
    }

    if (200 != $status_code) {
      return false;
    }

    if (is_null($path)) {
      $ret = '';
      while (! feof($sock)) {
        $ret .= fgets($sock, 8192);
        if (isset($this->headers['content-length'])) {
          if ($this->headers['content-length'] < strlen($ret)) {
            $ret = substr($ret, 0, $this->headers['content-length']);
            break;
          }
        }
      }
      fclose($sock);
      return $ret;
    }

    $fp = fopen($path, 'w');
    $got_len = 0;
    while (! feof($sock)) {
      $req_len = 8192;
      if (isset($this->headers['content-length'])) {
        if ($this->headers['content-length'] < $got_len + $req_len) {
          $req_len = $this->headers['content-length'] - $got_len;
        }
      }
      fwrite($fp, fread($sock, $req_len));
      if (isset($this->headers['content-length'])) {
        if ($this->headers['content-length'] == $got_len) {
          break;
        }
      }
    }
    fclose($fp);
    fclose($sock);
    return true;
  }

  private function requestSsl($method, $url, $buff, $referer, $path = null) {
    global $curl_headers;

    Log::i('Http->requestSsl(' . Log::dump($method) . ', ' . Log::dump($url) . ', ' . Log::dump($buff) . ', ' . Log::dump($referer) . ', ' . Log::dump($path) . ')');

    $reqHeaders = array(
        "Accept-Language: ja"
    );

    $fp = null;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HTTPHEADER, $reqHeaders);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($curl, CURLOPT_HEADER, false);
    if (is_null($path)) {
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
    } else {
      $fp = fopen($path, 'w');
      curl_setopt($curl, CURLOPT_FILE, $fp);
    }
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, 'POST' === $method);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
    curl_setopt($curl, CURLOPT_VERBOSE, true);
    curl_setopt($curl, CURLOPT_HEADERFUNCTION, 'parseResponseHeader');

    if (0 < count($this->cookies)) {
      curl_setopt($curl, CURLOPT_COOKIE, $this->getRequestCookieValue());
    }
    if ('POST' === $method) {
      curl_setopt($curl, CURLOPT_POSTFIELDS, $buff);
    }

    $result = curl_exec($curl);
    Log::i('result: ' . Log::dump($result));

    curl_close($curl);
    if (! is_null($fp)) {
      fclose($fp);
    }
    $this->headers = $curl_headers;

    foreach ($curl_headers as $head) {
      $lhead = strtolower($head);

      $tmp = null;
      if (preg_match('/^([\w\-]+)\s*:\s*(.+)$/', $head, $tmp)) {
        $k = strtolower($tmp[1]);
        $v = trim($tmp[2]);
         
        if ($k === 'set-cookie') {
          $tmp = null;
          if (preg_match('/^(\w+)=([^;\r\n\s]+)/', $v, $tmp)) {
            $this->cookies[$tmp[1]] = $tmp[2];
          }
        }
      }
    }

    return true;
  }

  private function getRequestCookieValue() {
    $cookieValue = '';
    foreach ($this->cookies as $k => $v) {
      $cookieValue .= $k .= '="' . $v . '"; ';
    }
    return trim($cookieValue);
  }
}

$curl_headers = array();
function parseResponseHeader($curl, $line) {
  global $curl_headers;

  $m = null;
  if (preg_match('/^HTTP\/\d.\d\s(\d+)/', $line, $m)) {
    $curl_headers['status_code'] = $m[1];
  }
  if (preg_match('/^([\w\-]+): (.+)$/', $line, $m)) {
    $curl_headers[strtolower($m[1])] = trim($m[2]);
  }
  return strlen($line);
}

class Log {
  public static function i($message) {
    echo self::getDate() . ' ' . $message . "\n";
  }

  public static function dump($var) {
    if (FALSE === $var) {
      return 'FALSE';
    }
    if (TRUE === $var) {
      return 'TRUE';
    }
    if (is_null($var)) {
      return 'NULL';
    }
    if (is_string($var) && strlen($var) > 1024) {
      return 'String(' . strlen($var) . 'bytes)';
    }
    if (is_array($var) && 0 == sizeof($var)) {
      return 'Array(blank)';
    }
    if (is_array($var)) {
      $ret = 'Array(';
      foreach ($var as $k => $v) {
        $ret .= $k . ' => ' . Log::dump($v) . ', ';
      }
      $ret = trim($ret, ', ');
      $ret .= ')';
      return $ret;
    }
    if (is_numeric($var)) {
      return 'Numeric(' . $var . ')';
    }
    if (is_string($var)) {
      return 'String(' . $var . ')';
    }
    if (is_resource($var)) {
      return 'Resource(' . $var . ')';
    }
    if (is_object($var)) {
      return 'Object';
    }

    $ret = print_r($var, TRUE);
    $ret = preg_replace('/\r\n|\r|\n/u', '', $ret);
    $ret = preg_replace('/\t/', ' ', $ret);
    $ret = preg_replace('/  /', ' ', $ret);
    return $ret;
  }

  private static function getDate() {
    return strftime('%Y-%m-%d %H:%M:%S');
  }
}

class Util {
  public static function randomString($len) {
    assert(is_numeric($len));
    assert(0 < $len);

    $candidates = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ09123456789_';
    $ret = '';
    srand();
    for ($i = 0; $i < $len; $i++) {
      $ret .= substr($candidates, rand(0, strlen($candidates) - 1), 1);
    }
    return $ret;
  }
}

class Files {
  public static function copyRecursive($regx, $srcDir, $dstDir) {
    assert(is_string($regx));
    assert(file_exists($srcDir));
    assert(is_dir($srcDir));
    assert(file_exists($dstDir));
    assert(is_dir($dstDir));
    assert(is_writable($dstDir));

    Log::i('Files::copyRecursive ' . $regx . ', ' . $srcDir . ', ' . $dstDir);

    $srcDir = trim($srcDir);
    $dstDir = trim($dstDir);

    // 末尾が "/" でなければ付加する
    if (! preg_match('/\/$/', $srcDir)) {
      $srcDir .= '/';
    }
    if (! preg_match('/\/$/', $dstDir)) {
      $dstDir .= '/';
    }

    $dp = opendir($srcDir);

    while (($file = readdir($dp)) !== false) {
      $file = trim($file);
      if (preg_match('/^\./', $file)) {
        continue;
      }

      $srcPath = $srcDir . $file;
      $dstPath = $dstDir . $file;

      if (is_dir($srcPath)) {
        Files::copyRecursive($regx, $srcPath, $dstDir);
        continue;
      }

      if (preg_match($regx, $file)) {
        copy($srcPath, $dstPath);
      }
    }

    closedir($dp);
  }
}


