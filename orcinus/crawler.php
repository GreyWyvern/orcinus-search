<?php /* ***** Orcinus Site Search - Web Crawling Engine *********** */


require __DIR__.'/config.php';
$_RDATA['debug'] = false;


/**
 * Log a notice (0), message (1) or error (2)
 *
 */
function OS_crawlLog($text, $level = 0) {
  global $_RDATA;

  switch ($level) {
    case 1: $prefix = ''; break;
    case 2: $prefix = '[ERROR] '; break;
    default: $prefix = ' -> ';
  }

  fwrite($_RDATA['sp_log'], $prefix.$text."\n");
  if ($_RDATA['debug'] ||
       ($_SERVER['REQUEST_METHOD'] == 'CLI' &&
        $level >= $_RDATA['sp_log_clilevel'])) {
     echo $prefix.$text."\n";
  }
}


/**
 * Final prep to store content in UTF-8 format in the database
 *
 */
function OS_cleanTextUTF8(&$_, $charset, $entity = false) {
  global $_RDATA;

  if (strtoupper($charset) != 'UTF-8')
    $_ = mb_convert_encoding($_, 'UTF-8', $charset);

  if ($entity)
    $_ = html_entity_decode($_, $entity | ENT_SUBSTITUTE, 'UTF-8');

  $_ = strtr($_, $_RDATA['sp_smart']);
  $_ = strtr($_, $_RDATA['sp_utf_replace']);
  $_ = preg_replace(array('/\s/', '/ {2,}/'), ' ', trim($_));
}


/**
 * Format a full or partial URL into a full URL according to a base URL
 *
 */
function OS_formatURL($_, $base) {
  $_ = str_replace(' ', '%20', $_);
  $_ = preg_replace('/#.*$/', '', filter_var($_, FILTER_SANITIZE_URL));
  $_ = str_replace('%20', ' ', $_);
  $dirbase = preg_replace('/(?<!:\/)\/[^\/]*$/', '', $base).'/';
  $pdb = parse_url($dirbase);
  $port = (!empty($pdb['port'])) ? ':'.$pdb['port'] : '';

  if (substr($_, 0, 3) == '../') {
    $p = preg_replace('/\/[^\/]*\/$/', '/', $pdb['path']);
    $_ = $pdb['scheme'].'://'.$pdb['host'].$port.$p.substr($_, 3);
  }
  if (substr($_, 0, 2) == './') {
    $_ = $dirbase.substr($_, 2);
  } else if (substr($_, 0, 2) == '//') {
    $_ = $pdb['scheme'].':'.$_;
  } else if (substr($_, 0, 1) == '/') {
    $_ = $pdb['scheme'].'://'.$pdb['host'].$port.$_;
  } else if (substr($_, 0, 1) == '?') {
    $_ = preg_replace('/\?.*$/', '', $base).$_;
  } else if (!preg_match('/^https?:\/\//', $_)) $_ = $dirbase.$_;

  $_ = preg_replace(array('/\/[^\/]*\/\.\.\//', '/\/\.\//'), '/', $_);
  if ($_ == $pdb['scheme'].'://'.$pdb['host'] ||
      $_ == $pdb['scheme'].'://'.$pdb['host'].$port) $_ .= '/';

  return trim($_);
}


/**
 * Filter a URL by the crawling rules provided by the user
 * - Sets an $_RDATA['sp_filter'] array key + value and returns the
 *   REASON why the URL was rejected, NOT a 'filtered' URL
 *
 */
function OS_filterURL($_, $base) {
  global $_RDATA;

  if (!preg_match('/^https?:\/\//', $_))
    $_ = OS_formatURL($_, $base);

  if (!empty($_RDATA['sp_filter'][$_]))
    return $_RDATA['sp_filter'][$_];

  $_RDATA['sp_filter'][$_] = '';

  // Accepted hostnames
  $plink = parse_url($_);
  if (!in_array($plink['host'], $_RDATA['sp_hostnames']))
    return $_RDATA['sp_filter'][$_] = 'disallowed-host';

  // Require URL matches
  if (count($_RDATA['sp_require_url'])) {
    $foundRequired = false;
    foreach ($_RDATA['sp_require_url'] as $requireURL) {
      if ($requireURL[0] == '*') {
        if (preg_match('/'.preg_quote(substr($requireURL, 1), '/').'/', $_))
          $foundRequired = true;
      } else if (strpos($_, $requireURL) !== false)
        $foundRequired = true;
    }
    if (!$foundRequired)
      return $_RDATA['sp_filter'][$_] = 'require-url';
  }

  // Ignore URL matches
  foreach ($_RDATA['sp_ignore_url'] as $ignoreURL) {
    if ($ignoreURL[0] == '*') {
      if (preg_match('/'.preg_quote(substr($ignoreURL, 1), '/').'/', $_))
        return $_RDATA['sp_filter'][$_] = 'ignore-url';
    } else if (strpos($_, $ignoreURL) !== false)
     return $_RDATA['sp_filter'][$_] = 'ignore-url';
  }

  // Ignore extensions
  if (preg_match('/\.('.$_RDATA['sp_ignore_ext_regexp'].')$/i', $_))
    return $_RDATA['sp_filter'][$_] = 'ignore-extension';

  // robots.txt rules
  if (!empty($_RDATA['sp_robots'][$plink['host']]))
    foreach ($_RDATA['sp_robots'][$plink['host']] as $disallowURL)
      if (strpos($_, $disallowURL) === 0)
        return $_RDATA['sp_filter'][$_] = 'robots-txt';

  return $_RDATA['sp_filter'][$_];
}


/**
 * Fetch a URL using cURL, return an array of useful information
 *
 */
function OS_fetchURL($url, $referer = '') {
  global $_cURL, $_RDATA;

  $_RDATA['sp_robots_header'] = 0;
  $_RDATA['sp_self_reference'] = 0;

  curl_setopt($_cURL, CURLOPT_URL, str_replace(' ', '%20', $url));
  curl_setopt($_cURL, CURLOPT_REFERER, $referer);

  $_ = array(
    'url' => parse_url($url),
    'body' => curl_exec($_cURL),
    'base' => $url,
    'info' => curl_getinfo($_cURL),
    'error' => curl_error($_cURL),
    'errno' => curl_errno($_cURL),
    'links' => array(),
    'title' => '',
    'content' => '',
    'keywords' => '',
    'weighted' => '',
    'description' => ''
  );

  $_['info']['url'] = $url;
  $_['info']['noindex'] = '';
  $_['info']['nofollow'] = false;

  // Process any cURL errors
  switch ($_['errno']) {
    case 0: // Success
    case 42: // Aborted by callback
      if ($_['info']['http_code'] >= 400) {
        $_['errno'] = 22;
        $_['error'] = $_['info']['http_code'].' error';
        $_['info']['noindex'] = '400';

      } else if ($_['info']['redirect_url']) {
        $_['errno'] = 300;
        $_['error'] = 'Redirected by HTTP header to: '.$_['info']['redirect_url'];
        $_['info']['noindex'] = 'redirect-location';

      } else if ($_RDATA['sp_robots_header']) {
        $_['errno'] = 777;
        $_['error'] = 'Blocked by \'X-Robots-Tag\' HTTP header';
        $_['info']['noindex'] = 'robots-http';

      } else if ($_RDATA['sp_self_reference']) {
        $_['errno'] = 888;
        $_['error'] = 'Refused to index myself';
        $_['info']['noindex'] = 'self-reference';

      } else if ($_['errno'] == 42) {
        $_['errno'] = 999;
        $_['error'] = 'Max filesize exceeded';
        $_['info']['noindex'] = 'too-large';
      }
      break;

    case 28: // Timeout
      $_['error'] = 'Timed out waiting for data';
      $_['info']['noindex'] = 'timeout';
      break;

    case 55: // Network send error
    case 56: // Network receive error
      $_['error'] = 'Network error retrieving data';
      $_['info']['noindex'] = 'network-error';
      break;

    case 6: // Could not resolve host
    case 7: // Could not connect to host
      $_['error'] = 'Couldn\'t connect to host: '.$_['url']['host'];
      $_['info']['noindex'] = 'couldnt-connect';
      break;

    default: // Uncaught cURL error
      OS_crawlLog('Uncaught cURL error:'.$url, 2);
      OS_crawlLog($_['errno'], 1);
      OS_crawlLog($_['error'], 1);
      OS_crawlLog(print_r($_['info'], true), 1);
      throw new Exception('Uncaught cURL error');

  }

  return $_;
}


/**
 * Shutdown function to provide cleanup before exit
 *
 */
function OS_crawlCleanUp() {
  global $_DDATA, $_ODATA, $_RDATA, $_cURL, $_MAIL;

  // If the crawl has already been canceled, don't bother
  if (!$_ODATA['sp_crawling']) return;

  $error = error_get_last();
  if (!is_null($error) && $error['type'] == E_ERROR) {
    OS_crawlLog($error['message'], 2);
    OS_crawlLog('File: \''.$error['file'].'\' at line number: '.$error['line'], 0);
    $_RDATA['sp_complete'] = false;
  }

  // Save or display cookies?
  $cookies = curl_getinfo($_cURL, CURLINFO_COOKIELIST);
  // var_dump($cookies);
  curl_close($_cURL);

  OS_setValue('sp_time_end', time());
  OS_setValue('sp_time_last', $_ODATA['sp_time_end'] - $_ODATA['sp_time_start']);
  OS_setValue('sp_data_transferred', $_RDATA['sp_data_transferred']);

  // If crawl completed successfully
  if ($_RDATA['sp_complete']) {
    OS_crawlLog('Cleaning up database tables...', 1);

    // Add a natural sort order value to each entry
    natcasesort($_RDATA['sp_store']);
    $_RDATA['sp_store'] = array_values($_RDATA['sp_store']);
    $url_sort = $_DDATA['pdo']->prepare(
      'UPDATE `'.$_DDATA['tbprefix'].'crawltemp`
         SET `url_sort`=:url_sort WHERE `url`=:url;'
    );
    foreach ($_RDATA['sp_store'] as $key => $stored_url) {
      $url_sort->execute(array(
        'url_sort' => $key,
        'url' => $stored_url
      ));
      $err = $url_sort->errorInfo();
      if ($err[0] != '00000') {
        OS_crawlLog('Error sorting the search database', 1);
        OS_crawlLog($err[2], 0);
        break;
      }
    }

    // Truncate the existing search database
    $truncate = $_DDATA['pdo']->query(
      'TRUNCATE `'.$_DDATA['tbprefix'].'crawldata`;'
    );
    $err = $truncate->errorInfo();
    if ($err[0] != '00000') {
      OS_crawlLog('Could not truncate the search database', 1);
      OS_crawlLog($err[2], 0);

      // Last chance to bail out before we make actual changes
      $_RDATA['sp_complete'] = false;
    }
  }

  // If crawl completed successfully AND we truncated the old table
  if ($_RDATA['sp_complete']) {

    // Select all rows from the temp table into the existing search table
    $insert = $_DDATA['pdo']->query(
      'INSERT INTO `'.$_DDATA['tbprefix'].'crawldata`
        SELECT * FROM `'.$_DDATA['tbprefix'].'crawltemp`;'
    );
    $err = $insert->errorInfo();
    if ($err[0] == '00000') {
      $tableinfo = $_DDATA['pdo']->query(
        'SHOW TABLE STATUS LIKE \''.$_DDATA['tbprefix'].'crawldata\';'
      );
      $err = $tableinfo->errorInfo();
      if ($err[0] == '00000') {
        $tableinfo = $tableinfo->fetchAll();
        OS_setValue('sp_data_stored', $tableinfo[0]['Data_length']);
      } else OS_crawlLog('Could not read crawl table status', 1);

      // Purge the search result cache
      if ($_ODATA['s_limit_cache']) {
        $purge = $_DDATA['pdo']->query(
          'UPDATE `'.$_DDATA['tbprefix'].'query` SET `cache`=\'\';'
        );
        $err = $purge->errorInfo();
        if ($err[0] != '00000')
          OS_crawlLog('Could not purge search result cache', 1);
      }

      // Optimize the query log table
      $optimize = $_DDATA['pdo']->query(
        'OPTIMIZE TABLE `'.$_DDATA['tbprefix'].'query`;'
      );

      OS_setValue('sp_links_crawled', count($_RDATA['sp_links']));
      OS_setValue('sp_pages_stored', count($_RDATA['sp_store']));
      OS_setValue('sp_time_end_success', $_ODATA['sp_time_end']);

      OS_crawlLog('***** Crawl completed in '.$_ODATA['sp_time_last'].'s *****', 1);
      OS_crawlLog('Total data transferred: '.OS_readSize($_RDATA['sp_data_transferred']), 1);
      OS_crawlLog('Average transfer speed: '.OS_readSize(round($_RDATA['sp_data_transferred'] / $_ODATA['sp_time_last'])).'/s', 1);
      if ($_RDATA['sp_sleep'])
        OS_crawlLog('Time spent sleeping: '.(round($_RDATA['sp_sleep'] / 10) / 100).'s', 1);
      OS_crawlLog('Time taken by cURL: '.(round($_RDATA['sp_time_curl'] * 100) / 100).'s', 1);
      OS_crawlLog($_ODATA['sp_links_crawled'].' page'.(($_ODATA['sp_links_crawled'] == 1) ? '' : 's').' crawled', 1);
      OS_crawlLog($_ODATA['sp_pages_stored'].' page'.(($_ODATA['sp_pages_stored'] == 1) ? '' : 's').' stored', 1);

      if ($_RDATA['sp_status']['New'])
        OS_crawlLog($_RDATA['sp_status']['New'].' new '.(($_RDATA['sp_status']['New'] == 1) ? 'page' : 'pages').' found', 0);
      if ($_RDATA['sp_status']['Updated'])
        OS_crawlLog($_RDATA['sp_status']['Updated'].' '.(($_RDATA['sp_status']['Updated'] == 1) ? 'page' : 'pages').' updated', 0);
      if ($_RDATA['sp_status']['Blocked'])
        OS_crawlLog($_RDATA['sp_status']['Blocked'].' '.(($_RDATA['sp_status']['Blocked'] == 1) ? 'page' : 'pages').' blocked', 0);
      if ($_RDATA['sp_status']['Not Found'])
        OS_crawlLog($_RDATA['sp_status']['Not Found'].' '.(($_RDATA['sp_status']['Not Found'] == 1) ? 'page' : 'pages').' not found', 0);
      if ($_RDATA['sp_status']['Orphan'])
        OS_crawlLog($_RDATA['sp_status']['Orphan'].' orphaned '.(($_RDATA['sp_status']['Orphan'] == 1) ? 'page' : 'pages'), 0);

      if ($_ODATA['sp_autodelete'])
        OS_crawlLog('Orphaned pages were auto-deleted', 1);

      // Send success email to the admin(s)
      if ($_MAIL && count($_MAIL->getAllRecipientAddresses()) && $_ODATA['sp_email_success']) {
        $_MAIL->Subject = 'Orcinus Site Search Crawler: Crawl succeeded';
        $_MAIL->Body = implode("   \r\n", preg_grep('/^[\[\*]/', explode("\n", file_get_contents($_ODATA['sp_log']))));
        if (!$_MAIL->Send()) OS_crawlLog('Could not send notification email', 2);
      }

      $cliMessage = 'Crawl completed successfully';
      $jsonMessage = json_encode(array(
        'status' => 'Success',
        'message' => $cliMessage
      ), JSON_INVALID_UTF8_IGNORE);

    // We truncated the search table but FAILED to populate it!
    // This is a serious error that disables searching until the
    // crawler is run again!
    } else {
      OS_crawlLog('Could not populate the search table', 2);
      OS_crawlLog($err[2], 0);

      OS_crawlLog('***** Crawl failed; runtime '.$_ODATA['sp_time_last'].'s *****', 1);
      OS_crawlLog('Search table was cleared, but could not be repopulated!', 1);
      OS_crawlLog('The crawler MUST be run again to fix this issue!', 1);

      // Send failure email to the admin(s)
      if ($_MAIL && count($_MAIL->getAllRecipientAddresses()) && $_ODATA['sp_email_failure']) {
        $_MAIL->Subject = 'Orcinus Site Search Crawler: Catastrophic failure!';
        $_MAIL->Body = implode("   \r\n", preg_grep('/^[\[\*\w\d]/', explode("\n", file_get_contents($_ODATA['sp_log']))));
        if (!$_MAIL->Send()) OS_crawlLog('Could not send notification email', 2);
      }

      $cliMessage = 'Could not populate search table; search table is currently empty!';
      $jsonMessage = json_encode(array(
        'status' => 'Error',
        'message' => $cliMessage
      ), JSON_INVALID_UTF8_IGNORE);
    }

  // Else the crawl failed
  } else {
    OS_crawlLog('***** Crawl failed; runtime '.$_ODATA['sp_time_last'].'s *****', 1);
    OS_crawlLog('Search table was NOT updated', 1);

    if ($_ODATA['sp_sitemap_file'])
      OS_crawlLog('Sitemap was NOT updated', 1);

    // Send failure email to the admin(s)
    if ($_MAIL && count($_MAIL->getAllRecipientAddresses()) && $_ODATA['sp_email_failure'] && !$_ODATA['sp_cancel']) {
      $_MAIL->Subject = 'Orcinus Site Search Crawler: Crawl failed';
      $_MAIL->Body = implode("   \r\n", preg_grep('/^[\[\*\w\d]/', explode("\n", file_get_contents($_ODATA['sp_log']))));
      if (!$_MAIL->Send()) OS_crawlLog('Could not send notification email', 2);
    }

    $cliMessage = 'Crawl failed; see the log for details';
    $jsonMessage = json_encode(array(
      'status' => 'Error',
      'message' => $cliMessage
    ), JSON_INVALID_UTF8_IGNORE);
  }

  // Delete the temp search table
  $drop = $_DDATA['pdo']->query(
    'DROP TABLE IF EXISTS `'.$_DDATA['tbprefix'].'crawltemp`;'
  );
  $err = $drop->errorInfo();
  if ($err[0] != '00000') {
    OS_crawlLog('Could not delete the temporary search table', 1);
    OS_crawlLog($err[2], 0);
  }

  // Store the log file to the config database
  OS_setValue('sp_log', file_get_contents($_ODATA['sp_log']));
  fclose($_RDATA['sp_log']);

  // Unset the crawling flag
  OS_setValue('sp_crawling', 0);

  if ($_SERVER['REQUEST_METHOD'] != 'CLI') {
    if (!$_RDATA['debug'])
      header('Content-type: application/json; charset='.strtolower($_ODATA['s_charset']));
    die($jsonMessage);
  } else die($cliMessage."\n");
}





// ***** Accept incoming commands by REQUEST_METHOD
switch ($_SERVER['REQUEST_METHOD']) {

  /* ***** Handle POST Requests ************************************ */
  case 'POST':

    // JSON POST request
    // These are usually sent by javascript fetch()
    if (strpos(trim($_SERVER['CONTENT_TYPE']), 'application/json') === 0) {
      $postBody = file_get_contents('php://input');
      $_POST = json_decode($postBody, false);

      $response = array();

      if (empty($_POST->action)) $_POST->action = '';
      switch ($_POST->action) {
        case 'crawl':
          if (!empty($_POST->sp_key) &&
              $_ODATA['sp_key'] &&
              $_POST->sp_key == $_ODATA['sp_key']) {
            if ($_ODATA['sp_crawling']) {
              $response = array(
                'status' => 'Error',
                'message' => 'Crawler is already running; current progress: '.$_ODATA['sp_progress']
              );
            }

            // Go crawl!
            OS_setValue('sp_key', '');

          } else {
            $response = array(
              'status' => 'Error',
              'message' => 'Incorrect key to initiate crawler'
            );
          }
          break;

        case 'progress':
          $lines = array();

          if (!empty($_POST->log)) {
            if ($_ODATA['sp_crawling']) {
              if (strpos($_ODATA['sp_log'], "\n") === false && file_exists($_ODATA['sp_log']))
                $lines = file($_ODATA['sp_log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            } else $lines = explode("\n", $_ODATA['sp_log']);

            if (empty($_POST->grep)) $_POST->grep = '';
            switch ($_POST->grep) {
              case 'all': break;
              case 'errors': $lines = preg_grep('/^[\[\*]/', $lines); break;
              default: $lines = preg_grep('/^[\[\*\w\d]/', $lines);
            }
          }

          if ($_ODATA['sp_crawling']) $lines = array_slice($lines, -15);

          $response = array(
            'status' => ($_ODATA['sp_crawling']) ? 'Crawling' : 'Complete',
            'progress' => $_ODATA['sp_progress'],
            'time_crawl' => time() - $_ODATA['sp_time_start'],
            'timeout_crawl' => $_ODATA['sp_timeout_crawl'],
            'tail' => trim(implode("\n", $lines))
          );
          break;

        case 'cancel':
          if ($_ODATA['sp_crawling']) {

            // IF the crawler 'time_start' is more than 'timeout_crawl'
            // seconds ago, or the 'force' token is set, the crawler is
            // probably stuck. Unstick it.
            if (empty($_POST->force)) $_POST->force = '';
            if ($_POST->force || time() - $_ODATA['sp_time_start'] > $_ODATA['sp_timeout_crawl']) {
              OS_setValue('sp_crawling', 0);

              if (empty($_POST->reason))
                $_POST->reason = 'The crawler halted unexpectedly';

              if (strpos($_ODATA['sp_log'], "\n") === false && file_exists($_ODATA['sp_log'])) {
                $log = file_get_contents($_ODATA['sp_log']);
                OS_setValue('sp_log', $log."\n".'[ERROR] '.$_POST->reason);
              } else OS_setValue('sp_log', '[ERROR] '.$_POST->reason);
              OS_setValue('sp_time_end', time());
              OS_setValue('sp_time_last', time() - $_ODATA['sp_time_start']);
              OS_setValue('sp_data_transferred', 0);
              OS_setValue('sp_data_stored', 0);

              // Send failure email to the admin(s)
              if ($_MAIL && count($_MAIL->getAllRecipientAddresses()) && $_ODATA['sp_email_failure']) {
                $_MAIL->Subject = 'Orcinus Site Search Crawler: Crawler halted unexpectedly';
                $_MAIL->Body = implode("   \r\n", preg_grep('/^[\[\*\w\d]/', explode("\n", $_ODATA['sp_log'])));
                if (!$_MAIL->Send()) OS_setValue('sp_log', $_ODATA['sp_log']."\n".'[ERROR] Could not send notification email');
              }
            }

            OS_setValue('sp_cancel', 1);
            $response = array(
              'status' => 'Success',
              'message' => 'Cancel flag was set',
              'crawl_time' => time() - $_ODATA['sp_time_start']
            );

          } else {
            $response = array(
              'status' => 'Error',
              'message' => 'Crawler is not currently running'
            );
          }
          break;

        default:
          $response = array(
            'status' => 'Error',
            'message' => 'Unrecognized command'
          );

      }

      // If we have a response to give, display it and exit
      if ($response) {
        header('Content-type: application/json; charset='.strtolower($_ODATA['s_charset']));
        die(json_encode($response, JSON_INVALID_UTF8_IGNORE));
      }

    // Don't do anything for normal POST request
    // These are usually sent by <form> HTML elements
    } else {
      header('Content-type: text/plain; charset='.strtolower($_ODATA['s_charset']));
      die($_ODATA['sp_useragent']);
    }
    break;

  // Allow CLI requests through
  case '':
    if (!empty($_SERVER['argv'][0]) && $_SERVER['argv'][0] == $_SERVER['PHP_SELF']) {
      $_SERVER['REQUEST_METHOD'] = 'CLI';
      if (!empty($_SERVER['argv'][1]) && preg_match('/^-log=([012])$/', $_SERVER['argv'][1], $match)) {
        $_RDATA['sp_log_clilevel'] = (int)$match[1];
      } else $_RDATA['sp_log_clilevel'] = 2;
    } else die($_ODATA['sp_useragent']);
    break;

  // Don't do anything for GET requests, unless in debug mode
  case 'GET':
    header('Content-type: text/plain; charset='.strtolower($_ODATA['s_charset']));
    if (!$_RDATA['debug']) die($_ODATA['sp_useragent']);
    break;

  // Exit for all other request types
  default:
    header('Content-type: text/plain; charset='.strtolower($_ODATA['s_charset']));
    die($_ODATA['sp_useragent']);

}


/* ***** Begin Crawl Execution ************************************* */
register_shutdown_function('OS_crawlCleanUp');
ignore_user_abort(true);
@set_time_limit($_ODATA['sp_timeout_crawl'] * 1.1);
libxml_use_internal_errors(true);
if (function_exists('apache_setenv'))
  apache_setenv('no-gzip', '1');

OS_setValue('sp_crawling', 1);
OS_setValue('sp_cancel', 0);
OS_setValue('sp_time_start', time());
OS_setValue('sp_links_crawled', 0);
OS_setValue('sp_pages_stored', 0);
OS_setValue('sp_data_stored', 0);
OS_setValue('sp_data_transferred', 0);
OS_setValue('sp_time_last', 0);


$_RDATA['sp_log'] = tmpfile();
OS_setValue('sp_log', stream_get_meta_data($_RDATA['sp_log'])['uri']);
OS_crawlLog('***** Crawl started: '.date('r').' *****', 1);


// ***** Prepare runtime data
$_RDATA['sp_starting'] = array_filter(array_map('trim', explode("\n", $_ODATA['sp_starting'])));
$_RDATA['sp_hostnames'] = array();
$_RDATA['sp_ignore_url'] = array_filter(array_map('trim', explode("\n", $_ODATA['sp_ignore_url'])));
$_RDATA['sp_ignore_css'] = array_filter(explode(' ', $_ODATA['sp_ignore_css']));
$_RDATA['s_weight_css'] = array_filter(explode(' ', $_ODATA['s_weight_css']));
$_RDATA['sp_require_url'] = array_filter(array_map('trim', explode("\n", $_ODATA['sp_require_url'])));
$_RDATA['sp_ignore_ext_regexp'] = implode('|', array_map('preg_quote', array_filter(explode(' ', $_ODATA['sp_ignore_ext']))));
$_RDATA['sp_robots_header'] = 0;
$_RDATA['sp_complete'] = false;
$_RDATA['sp_links'] = array();
$_RDATA['sp_store'] = array();
$_RDATA['sp_sitemap'] = array();
$_RDATA['sp_robots'] = array();
$_RDATA['sp_status'] = array('Orphan' => 0, 'Blocked' => 0, 'Not Found' => 0, 'Updated' => 0, 'New' => 0);
$_RDATA['sp_filter'] = array();
$_RDATA['sp_prev_dls'] = 0;
$_RDATA['sp_data_transferred'] = 0;
$_RDATA['sp_time_curl'] = 0;
$_RDATA['sp_sleep'] = 0;
$_RDATA['sp_sha1'] = array();
$_RDATA['sp_entity'] = array(
  "\n" => array(10, 11, 12, 13, 133, 8232, 8233),
  ' ' => array(9, 160, 5760, 8192, 8193, 8194, 8195, 8196, 8197, 8198, 8199, 8200, 8201, 8202, 8204, 8239, 8287, 12288),
  '' => array(173, 8205, 8288, 65279),
  '-' => array(1418, 1470, 5120, 6150, 8208, 8209, 8210, 8211, 8212, 8213, 11799, 11802, 11834, 11835, 11840, 12316, 12336, 12448, 65073, 65074, 65112, 65123, 65293, 69293)
);
$_RDATA['sp_utf_replace'] = array();
foreach ($_RDATA['sp_entity'] as $key => $value)
  foreach ($value as $code)
    $_RDATA['sp_utf_replace'][mb_chr($code)] = $key;



// ***** Load PDF parser
if (!class_exists('\Smalot\PdfParser\Parser'))
  if (file_exists(__DIR__.'/pdfparser/alt_autoload.php-dist'))
    include __DIR__.'/pdfparser/alt_autoload.php-dist';
if (class_exists('\Smalot\PdfParser\Parser')) {
  $config = new \Smalot\PdfParser\Config();
  $config->setRetainImageContent(false);
  $config->setDecodeMemoryLimit(16777216);
  $_PDF = new \Smalot\PdfParser\Parser([], $config);
} else {
  OS_crawlLog('Could not include \'PDFParser\'; PDFs will not be indexed', 1);
  $_PDF = false;
}


// ***** Check for PHPMailer
if (!$_MAIL) {
  OS_crawlLog('Could not include \'PHPMailer\'; Crawler cannot send mail', 1);
} else if (!count($_MAIL->getAllRecipientAddresses()))
  OS_crawlLog('No admin emails specified; Crawler will not send mail', 1);


// ***** Initialize the cURL connection
$_cURL = OS_getConnection();
if ($_cURL) {

  // Customize this cURL connection
  if ($_ODATA['sp_cookies'])
    curl_setopt($_cURL, CURLOPT_COOKIEFILE, '');
  curl_setopt($_cURL, CURLOPT_HEADERFUNCTION, function($_cURL, $line) {
    global $_RDATA;

    if (preg_match('/^X-Robots-Tag:\s*(noindex|none)/i', $line))
      $_RDATA['sp_robots_header'] = 1;

    if (trim($line) == $_RDATA['x_generated_by'])
      $_RDATA['sp_self_reference'] = 1;

    return strlen($line);
  });
  curl_setopt($_cURL, CURLOPT_NOPROGRESS, false);
  curl_setopt($_cURL, CURLOPT_PROGRESSFUNCTION,
    function($_cURL, $dls, $dl, $uls, $ul) {
      global $_ODATA, $_RDATA;

      if ($_RDATA['sp_robots_header']) return 1;
      if ($_RDATA['sp_self_reference']) return 1;

      // Prevent comparing this value until a Content-length header has
      // been received by the cURL connection
      if ($dls != $_RDATA['sp_prev_dls']) {
        $_RDATA['sp_prev_dls'] = $dls;
        if ($dls > $_ODATA['sp_limit_filesize'] * 1024) return 1;
      }
      if ($dl > $_ODATA['sp_limit_filesize'] * 1024) return 1;

      $i = curl_getinfo($_cURL);
      if ($i['redirect_url']) return 1;
      if ($i['http_code'] && $i['http_code'] >= 400) return 1;

      return $_RDATA['sp_robots_header'];
    }
  );

} else OS_crawlLog('cURL functions are not enabled; cannot perform crawl', 2);


// ***** Pre-fill queue with starting URL(s) at depth 0, blank referer
$_RDATA['sp_queue'] = array();
foreach ($_RDATA['sp_starting'] as $starting) {
  $starting = OS_formatURL($starting, $_ODATA['admin_install_domain'].'/');
  $_RDATA['sp_queue'][] = array($starting, 0, '');

  // Add starting URLs to required URLs so the crawler cannot travel
  // into parent directories
  $_RDATA['sp_require_url'][] = preg_replace('/\/[^\/]*$/', '/', $starting);

  $host = parse_url($starting)['host'];
  if (!in_array($host, $_RDATA['sp_hostnames']))
    $_RDATA['sp_hostnames'][] = $host;
}

// ***** List of previously crawled links from the database
$_RDATA['sp_exist'] = array();
$_RDATA['sp_lastmod'] = array();
$crawldata = $_DDATA['pdo']->query(
  'SELECT `url`, `content_checksum`, `last_modified`
     FROM `'.$_DDATA['tbprefix'].'crawldata`'
);
$err = $crawldata->errorInfo();
if ($err[0] == '00000') {
  foreach ($crawldata as $value) {
    $_RDATA['sp_exist'][$value['content_checksum']] = $value['url'];
    $_RDATA['sp_lastmod'][$value['url']] = $value['last_modified'];
  }
} else OS_crawlLog('Error getting list of previous URLs from crawldata table', 2);


// Drop previous temp table if it exists
$drop = $_DDATA['pdo']->query(
  'DROP TABLE IF EXISTS `'.$_DDATA['tbprefix'].'crawltemp`;'
);

// Create a temp MySQL storage table using schema of the existing table
$create = $_DDATA['pdo']->query(
  'CREATE TABLE `'.$_DDATA['tbprefix'].'crawltemp`
     LIKE `'.$_DDATA['tbprefix'].'crawldata`;'
);


// Prepare SQL statements
$selectData = $_DDATA['pdo']->prepare(
  'SELECT `url`, `category`, `links`, `content_checksum`, `last_modified`,
          `flag_updated`, `flag_unlisted`, `priority`
  FROM `'.$_DDATA['tbprefix'].'crawldata` WHERE `url`=:url;'
);
$updateURL = $_DDATA['pdo']->prepare(
  'UPDATE `'.$_DDATA['tbprefix'].'crawltemp` SET
    `url`=:url WHERE `content_checksum`=:content_checksum;'
);
$insertTemp = $_DDATA['pdo']->prepare(
  'INSERT INTO `'.$_DDATA['tbprefix'].'crawltemp` SET
    `url`=:url,
    `url_base`=:url_base,
    `url_sort`=0,
    `title`=:title,
    `description`=:description,
    `keywords`=:keywords,
    `category`=:category,
    `weighted`=:weighted,
    `links`=:links,
    `content`=:content,
    `content_mime`=:content_mime,
    `content_charset`=:content_charset,
    `content_checksum`=:content_checksum,
    `status`=:status,
    `status_noindex`=:status_noindex,
    `flag_unlisted`=:flag_unlisted,
    `flag_updated`=:flag_updated,
    `last_modified`=:last_modified,
    `priority`=:priority
  ;'
);
$insertNotModified = $_DDATA['pdo']->prepare(
  'INSERT INTO `'.$_DDATA['tbprefix'].'crawltemp`
     SELECT * FROM `'.$_DDATA['tbprefix'].'crawldata` WHERE `url`=:url;'
);
$updateNotModified = $_DDATA['pdo']->prepare(
  'UPDATE `'.$_DDATA['tbprefix'].'crawltemp`
     SET `flag_updated`=0, `status`=:status WHERE `url`=:url;'
);


// ***** Begin crawling URLs from the queue
while ($_cURL && count($_RDATA['sp_queue'])) {

  // Check if we have run out of execution time
  if ($_ODATA['sp_time_start'] + $_ODATA['sp_timeout_crawl'] <= time()) {
    OS_crawlLog('Maximum script runtime ('.$_ODATA['sp_timeout_crawl'].'s) reached', 2);
    break;
  }

  // Check if user has canceled the crawl
  if (OS_getValue('sp_cancel')) {
    OS_crawlLog('Crawl canceled manually by user', 2);
    break;
  }

  // Check if we have exceeded the maximum number of crawled links
  if (count($_RDATA['sp_links']) > $_ODATA['sp_limit_crawl']) {
    OS_crawlLog('Maximum number of crawled pages exceeded', 2);
    break;
  }

  // Retrieve next link to crawl from the queue
  list($url, $depth, $referer) = array_shift($_RDATA['sp_queue']);
  $_RDATA['sp_links'][] = $url;

  // Check if URL is beyond the depth limit
  if ($depth > $_ODATA['sp_limit_depth']) {
    OS_crawlLog('Maximum link depth ('.$_ODATA['sp_limit_depth'].') exceeded; URL at depth '.$depth.' was not stored: '.$url, 2);
    continue;
  }

  // Check robots.txt for newly encountered hostnames
  $purl = parse_url($url);
  $port = (!empty($purl['port'])) ? ':'.$purl['port'] : '';
  if (!isset($_RDATA['sp_robots'][$purl['host']])) {
    $_RDATA['sp_robots'][$purl['host']] = array();
    OS_crawlLog('Fetching robots.txt for domain: '.$purl['host'], 1);

    curl_setopt($_cURL, CURLOPT_TIMECONDITION, CURL_TIMECOND_NONE);
    $robotstxt = OS_fetchURL($purl['scheme'].'://'.$purl['host'].$port.'/robots.txt', '');

    if (!$robotstxt['errno']) {
      $robots = array();
      $robot = '';
      $robolines = explode("\n", $robotstxt['content']);
      foreach ($robolines as $line) {
        if (preg_match('/^user-agent\s*:\s*(.*)\s*$/i', $line, $r)) {
          if (empty($robots[$robot = $r[1]]))
            $robots[$robot] = array('disallow' => array(), 'allow' => array());
        } else if (preg_match('/((dis)?allow)\s*:\s*(.*)\s*$/i', $line, $r))
          $robots[$robot][strtolower($r[1])][] = OS_formatURL($r[3], $url);
      }
      foreach ($robots as $agent => $rules) {
        if (preg_match('/^orc(a|inus)(-?php)?-?crawler$/i', $agent) || $agent == '*') {
          foreach ($rules['disallow'] as $disrule)
            if (!in_array($disrule, $_RDATA['sp_robots'][$purl['host']]))
              $_RDATA['sp_robots'][$purl['host']][] = $disrule;
          foreach ($rules['allow'] as $rule) {
            $key = array_search($rule, $_RDATA['sp_robots'][$purl['host']]);
            if ($key !== false) unset($_RDATA['sp_robots'][$purl['host']][$key]);
          }
        }
      }
    }
  }

  if ($_RDATA['debug'])
    OS_crawlLog('Memory used: '.OS_readSize(memory_get_usage(true)), 1);

  OS_crawlLog('Crawling: '.$url.' (Depth: '.$depth.')', 1);
  OS_setValue('sp_progress', count($_RDATA['sp_links']).'/'.(count($_RDATA['sp_links']) + count($_RDATA['sp_queue'])));

  // Set the correct If-Modified-Since request header
  if ($_ODATA['sp_ifmodifiedsince'] && isset($_RDATA['sp_lastmod'][$url])) {
    curl_setopt($_cURL, CURLOPT_TIMEVALUE, $_RDATA['sp_lastmod'][$url]);
    curl_setopt($_cURL, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE);
  } else curl_setopt($_cURL, CURLOPT_TIMECONDITION, CURL_TIMECOND_NONE);

  // Fetch the URL
  $data = OS_fetchURL($url, $referer);

  // Record cURL timing and data info for this fetch
  $_RDATA['sp_data_transferred'] += $data['info']['size_download'];
  $_RDATA['sp_time_curl'] += $data['info']['total_time'];


  // If there were cURL errors while fetching this URL
  if ($data['errno']) {


  // Else if the page hasn't been modified since the last crawl
  } else if ($data['info']['http_code'] == 304) {
    $data['info']['noindex'] = 'not-modified-304';


  // Else if we received any content at all
  } else if (trim($data['body'])) {

    // Get a 20-byte binary hash of the raw content
    $data['info']['sha1'] = sha1($data['body'], true);

    // If this content does not duplicate previously stored content
    if (empty($_RDATA['sp_sha1'][$data['info']['sha1']])) {

      // Add the content hash to the tally
      $_RDATA['sp_sha1'][$data['info']['sha1']] = $url;

      // If this is a new page, or an existing page but the content
      // hash has changed
      if (!isset($_RDATA['sp_exist'][$data['info']['sha1']]) ||
          $_RDATA['sp_exist'][$data['info']['sha1']] != $url) {

        // Detect MIME-type using extension?
        if (empty($data['info']['content_type']))
          $data['info']['content_type'] = 'text/plain';

        // Parse MIME-type
        $data['info']['mime_type'] = '';
        if (preg_match('/\w+\/[\w.+-]+/', $data['info']['content_type'], $m))
          $data['info']['mime_type'] = $m[0];

        // Parse Character Encoding
        $data['info']['charset'] = '';
        if (preg_match('/charset=([\w\d.:-]+)/i', $data['info']['content_type'], $m))
          $data['info']['charset'] = $m[1];
        if (!$data['info']['charset'])
          $data['info']['charset'] = 'ISO-8859-1';

        // GZ-Unzip the content if necessary
        while (strpos($data['body'], "\x1f\x8b") === 0)
          $data['body'] = gzinflate(substr($data['body'], 10));

        // Title defaults to filename
        $data['title'] = basename($data['info']['url']);

        // Determine how to parse the content by MIME-type
        switch ($data['info']['mime_type']) {

          /* ***** PLAIN TEXT ************************************** */
          case 'text/plain':
            $data['content'] = $data['body'];

            OS_cleanTextUTF8($data['content'], $data['info']['charset']);
            break;


          /* ***** XML DOCUMENT ************************************ */
          case 'text/xml':
          case 'application/xml':
            $data['body'] = preg_replace('/<br(\s?\/)?>/', ' ', $data['body']);

            $document = new DOMDocument();
            if ($document->loadXML($data['body'], LIBXML_PARSEHUGE | LIBXML_BIGLINES | LIBXML_COMPACT)) {

              // Remove <script> elements
              $scripts = $document->getElementsByTagName('script');
              foreach ($scripts as $script)
                $script->parentNode->removeChild($script);

              // Remove <!-- comments -->
              $xpath = new DOMXpath($document);
              $comments = $xpath->query('//comment()');
              foreach ($comments as $comment)
                $comment->parentNode->removeChild($comment);

              // Check XML document charset
              if (strtolower($data['info']['charset']) != strtolower($document->xmlEncoding)) {
                OS_crawlLog('Charset in Content-type header ('.(($data['info']['charset']) ? $data['info']['charset'] : '<none>').') differs from document charset ('.(($document->xmlEncoding) ? $document->xmlEncoding : '<none>').') at: '.$data['info']['url'], 1);
                $data['info']['charset'] = $document->xmlEncoding;
              }

              $data['content'] = $document->textContent;

            // Could not parse XML; try to store content anyway
            } else {
              $data['error'] = 'Invalid XML - could not parse content; storing as-is';
              $data['info']['nofollow'] = true;

              // Remove <script> elements and <!-- comments -->
              $data['content'] = preg_replace(array('/<!--.*?-->/s', '/<script.*?\/script>/is'), '', $data['body']);
              $data['content'] = strip_tags($data['content']);
            }

            OS_cleanTextUTF8($data['content'], $data['info']['charset'], ENT_XML1);
            break;


          /* ***** HTML DOCUMENT *********************************** */
          case 'text/html':
          case 'application/xhtml+xml':
            $data['body'] = preg_replace('/<br(\s?\/)?>/', ' ', $data['body']);

            $document = new DOMDocument();
            if ($document->loadHTML($data['body'], LIBXML_PARSEHUGE | LIBXML_BIGLINES | LIBXML_COMPACT | LIBXML_NOCDATA)) {

              // Remove <script> elements
              $scripts = $document->getElementsByTagName('script');
              foreach ($scripts as $script)
                $script->parentNode->removeChild($script);

              // Remove <!-- comments -->
              $xpath = new DOMXpath($document);
              $comments = $xpath->query('//comment()');
              foreach ($comments as $comment)
                $comment->parentNode->removeChild($comment);

              // ***** Process <head> elements
              $head = $document->getElementsByTagName('head');
              if (!empty($head[0])) {

                $base = $head[0]->getElementsByTagName('base');
                if (!empty($base[0]))
                  for ($x = 0; $x < count($base[0]->attributes); $x++)
                    if (strtolower($base[0]->attributes[$x]->name) == 'href')
                      if (!empty($base[0]->attributes[$x]->value))
                        $data['base'] = filter_var($base[0]->attributes[$x]->value, FILTER_SANITIZE_URL);

                $metas = $head[0]->getElementsByTagName('meta');
                foreach ($metas as $meta) {
                  for ($x = 0; $x < count($meta->attributes); $x++) {
                    if (strtolower($meta->attributes[$x]->name) == 'charset') {
                      if (strtolower($data['info']['charset']) != strtolower($meta->attributes[$x]->value)) {
                        OS_crawlLog('Charset in Content-type header ('.(($data['info']['charset']) ? $data['info']['charset'] : '<none>').') differs from document charset ('.(($meta->attributes[$x]->value) ? $meta->attributes[$x]->value : '<none>').') at: '.$data['info']['url'], 1);
                        $data['info']['charset'] = $meta->attributes[$x]->value;
                      }

                    } else if (strtolower($meta->attributes[$x]->name) == 'http-equiv') {
                      switch (strtolower($meta->attributes[$x]->value)) {
                        case 'refresh':
                          for ($y = 0; $y < count($meta->attributes); $y++) {
                            if (strtolower($meta->attributes[$y]->name) == 'content') {
                              if (preg_match('/(\d+)\s?;\s?url\s?=\s?([\'"])(.+?)\2?\s?$/i', $meta->attributes[$y]->value, $m)) {
                                if ((int)$m[1] <= $_ODATA['sp_timeout_url']) {
                                  $data['errno'] = 300;
                                  $data['error'] = 'Redirected by <meta> element to: '.$m[3];
                                  $data['info']['redirect_url'] = $m[3];
                                  $data['info']['noindex'] = 'redirect-meta';
                                  $data['info']['nofollow'] = true;
                                  break 4;
                                } else $data['links'][] = $m[3];
                              }
                            }
                          }
                          break;

                        case 'content-type':
                          for ($y = 0; $y < count($meta->attributes); $y++) {
                            if (strtolower($meta->attributes[$y]->name) == 'content' && preg_match('/charset=([\w\d.:-]+)/i', $meta->attributes[$y]->value, $m)) {
                              if (strtolower($data['info']['charset']) != strtolower($m[1])) {
                                OS_crawlLog('Charset in Content-type header ('.(($data['info']['charset']) ? $data['info']['charset'] : '<none>').') differs from document charset ('.(($m[1]) ? $m[1] : '<none>').') at: '.$data['info']['url'], 1);
                                $data['info']['charset'] = $m[1];
                              }
                            }
                          }

                      }

                    } else if (strtolower($meta->attributes[$x]->name) == 'name') {
                      switch (strtolower($meta->attributes[$x]->value)) {
                        case 'keywords':
                          for ($y = 0; $y < count($meta->attributes); $y++)
                            if (strtolower($meta->attributes[$y]->name) == 'content')
                              $data['keywords'] = $meta->attributes[$y]->value;
                          break;

                        case 'description':
                          for ($y = 0; $y < count($meta->attributes); $y++)
                            if (strtolower($meta->attributes[$y]->name) == 'content')
                              $data['description'] = $meta->attributes[$y]->value;
                          break;

                        case 'robots':
                        case 'orcacrawler':
                        case 'orcaphpcrawler':
                        case 'orca-crawler':
                        case 'orcaphp-crawler':
                        case 'orca-phpcrawler':
                        case 'orca-php-crawler':
                        case 'orcinuscrawler':
                        case 'orcinus-crawler':
                          for ($y = 0; $y < count($meta->attributes); $y++) {
                            if (strtolower($meta->attributes[$y]->name) == 'content') {
                              $content = explode(',', $meta->attributes[$y]->value);
                              foreach ($content as $con) {
                                switch (trim(strtolower($con))) {
                                  case 'nofollow':
                                    $data['info']['nofollow'] = true;
                                    break;

                                  case 'noindex':
                                    $data['error'] = 'Not indexed due to robots <meta> element';
                                    $data['info']['noindex'] = 'robots-meta';

                                }
                              }
                            }
                          }

                      }
                    }
                  }
                }

                $title = $head[0]->getElementsByTagName('title');
                $data['title'] = $title[0]->textContent;

                $links = $head[0]->getElementsByTagName('link');
                foreach ($links as $link) {
                  for ($x = 0; $x < count($link->attributes); $x++) {
                    if (strtolower($link->attributes[$x]->name) == 'rel') {
                      for ($y = 0; $y < count($link->attributes); $y++) {
                        if (strtolower($link->attributes[$y]->name) == 'href') {
                          $linkurl = filter_var($link->attributes[$y]->value, FILTER_SANITIZE_URL);

                          switch (strtolower($link->attributes[$x]->value)) {
                            case 'canonical':
                              if (OS_formatURL($linkurl, $data['base']) != $data['info']['url']) {
                                $data['info']['noindex'] = 'non-canonical';
                                $data['info']['canonical'] = $linkurl;
                              }

                            case 'alternate':
                            case 'author':
                            case 'help':
                            case 'license':
                            case 'me':
                            case 'next':
                            case 'prev':
                            case 'search':
                            case 'alternate':
                              $data['links'][] = $linkurl;

                          }
                          break;
                        }
                      }
                    }
                  }
                }
              }


              // ***** Process <body> elements
              $body = $document->getElementsByTagName('body');
              if (!empty($body[0])) {

                // Replace <img> tags with their alt text
                $imgs = $body[0]->getElementsByTagName('img');
                foreach ($imgs as $img) {
                  for ($x = 0; $x < count($img->attributes); $x++) {
                    if (strtolower($img->attributes[$x]->name) == 'alt') {
                      $img->parentNode->replaceChild(
                        $document->createTextNode(' '.$img->attributes[$x]->value.' '),
                        $img
                      );
                      break;
                    }
                  }
                }

                $as = $body[0]->getElementsByTagName('a');
                foreach ($as as $a) {
                  for ($x = 0; $x < count($a->attributes); $x++) {
                    if (strtolower($a->attributes[$x]->name) == 'href') {
                      for ($y = 0; $y < count($a->attributes); $y++)
                        if (strtolower($a->attributes[$y]->name) == 'rel' && strtolower($a->attributes[$y]->value) == 'nofollow') continue 3;
                      $data['links'][] = $a->attributes[$x]->value;
                    }
                  }
                }

                $areas = $body[0]->getElementsByTagName('area');
                foreach ($areas as $area) {
                  for ($x = 0; $x < count($area->attributes); $x++) {
                    if (strtolower($area->attributes[$x]->name) == 'href') {
                      for ($y = 0; $y < count($area->attributes); $y++)
                        if (strtolower($area->attributes[$y]->name) == 'rel' && strtolower($area->attributes[$y]->value) == 'nofollow') continue 3;
                      $data['links'][] = $area->attributes[$x]->value;
                    }
                  }
                }

                $frames = $body[0]->getElementsByTagName('frame');
                foreach ($frames as $frame)
                  for ($x = 0; $x < count($frame->attributes); $x++)
                    if (strtolower($frame->attributes[$x]->name) == 'src')
                      $data['links'][] = $frame->attributes[$x]->value;

                $iframes = $body[0]->getElementsByTagName('iframe');
                foreach ($iframes as $iframe)
                  for ($x = 0; $x < count($iframe->attributes); $x++)
                    if (strtolower($iframe->attributes[$x]->name) == 'src')
                      $data['links'][] = $iframe->attributes[$x]->value;

              }


              $data['links'] = array_map(function($l) {
                if (preg_match('/^(tel|telnet|mailto|ftp|sftp|ssh|gopher|news|ldap|urn|onion|magnet):/i', $l)) return '';
                return preg_replace('/#.*$/', '', $l);
              }, $data['links']);
              $data['links'] = array_filter(array_unique($data['links']));

              // Remove tags
              foreach ($_RDATA['sp_ignore_css'] as $ignoreCSS) {
                switch ($ignoreCSS[0]) {
                  case '#': // Remove by ID
                    $id = $document->getElementById(substr($ignoreCSS, 1));
                    if (!is_null($id)) $id->parentNode->removeChild($id);
                    break;

                  case '.': // Remove by class
                    foreach ($xpath->evaluate('//*[contains(concat(" ", normalize-space(@class), " "), " '.substr($ignoreCSS, 1).' ")]') as $cls)
                      $cls->parentNode->removeChild($cls);
                    break;

                  default: // Remove by tag name
                    $tags = $document->getElementsByTagName($ignoreCSS);
                    foreach ($tags as $tag)
                      $tag->parentNode->removeChild($tag);

                }
              }

              // Weighted elements
              foreach ($_RDATA['s_weight_css'] as $weightCSS) {
                switch ($weightCSS[0]) {
                  case '#': // Get content by ID
                    $id = $document->getElementById(substr($weightCSS, 1));
                    if (!is_null($id)) $data['weighted'] .= $id->textContent.' ';
                    break;

                  case '.': // Get content by class
                    foreach ($xpath->evaluate('//*[contains(concat(" ", normalize-space(@class), " "), " '.substr($weightCSS, 1).' ")]') as $cls)
                      $data['weighted'] .= $cls->textContent.' ';
                    break;

                  default: // Get content by tag name
                    $tags = $document->getElementsByTagName($weightCSS);
                    foreach ($tags as $tag)
                      $data['weighted'] .= $tag->textContent.' ';

                }
              }

              $data['content'] = $document->textContent;

            // Could not parse HTML; try to store content anyway
            } else {
              $data['error'] = 'Invalid HTML - could not parse content; storing as-is';
              $data['info']['nofollow'] = true;

              // Remove <script> elements and <!-- comments -->
              $data['content'] = preg_replace(array('/<!--.*?-->/s', '/<script.*?\/script>/is'), '', $data['body']);
              $data['content'] = strip_tags($data['content']);
            }

            // Not sure I need to do this, but hey... I could, so...
            if ($data['info']['mime_type'] == 'application/xhtml+xml') {
              $ent = ENT_XHTML;
            } else if (!empty($document->doctype->publicId)) {
              $publicId = strtoupper($document->doctype->publicId);
              if (strpos($publicId, 'DTD XHTML') !== false) {
                $ent = ENT_XHTML;
              } else if (strpos($publicId, 'DTD HTML') !== false) {
                $ent = ENT_HTML401;
              } else $ent = ENT_XML1;
            } else $ent = ENT_HTML5;

            OS_cleanTextUTF8($data['title'], $data['info']['charset'], $ent);
            OS_cleanTextUTF8($data['keywords'], $data['info']['charset'], $ent);
            OS_cleanTextUTF8($data['description'], $data['info']['charset'], $ent);
            OS_cleanTextUTF8($data['weighted'], $data['info']['charset'], $ent);
            OS_cleanTextUTF8($data['content'], $data['info']['charset'], $ent);
            break;


          /* ***** PDF ********************************************* */
          case 'application/pdf':
            if ($_PDF) {
              try {
                $pdf = $_PDF->parseContent($data['body']);

                $meta = $pdf->getDetails();
                if (!empty($meta['Title']) && trim($meta['Title']))
                  $data['title'] = $meta['Title'];
                if (!empty($meta['Subject']) && trim($meta['Subject']))
                  $data['description'] = $meta['Subject'];
                if (!empty($meta['Keywords']) && trim($meta['Keywords']))
                  $data['keywords'] = $meta['Keywords'];
                $data['content'] = $pdf->getText();

                // remove escaped whitespace
                $data['title'] = str_replace(array("\\\n\r", "\\\n"), '', $data['title']);
                $data['description'] = str_replace(array("\\\n\r", "\\\n"), '', $data['description']);
                $data['keywords'] = str_replace(array("\\\n\r", "\\\n"), '', $data['keywords']);
                $data['content'] = str_replace(array("\\\n\r", "\\\n"), '', $data['content']);

                $data['info']['charset'] = mb_detect_encoding($data['content']);
                if (!$data['info']['charset']) $data['info']['charset'] = 'ISO-8859-1';
                OS_cleanTextUTF8($data['content'], $data['info']['charset']);

                if ($data['content']) {

                  // Discard the PDF text if it contains Unicode control
                  // characters; some of these might be simple PDF ligatures
                  // but PDFParser doesn't support them; any content that
                  // contains these is usually mostly gobbledegook
                  if (strpos($data['content'], "\u{3}") === false &&
                      strpos($data['content'], "\u{2}") === false &&
                      strpos($data['content'], "\u{1}") === false) {

                    OS_cleanTextUTF8($data['title'], $data['info']['charset']);
                    OS_cleanTextUTF8($data['keywords'], $data['info']['charset']);
                    OS_cleanTextUTF8($data['description'], $data['info']['charset']);

                  } else {
                    $data['errno'] = 703;
                    $data['error'] = 'Failed to decode PDF text';
                    $data['content'] = '';
                    $data['info']['noindex'] = 'couldnt-decode-pdf';
                  }

                } else {
                  $data['errno'] = 702;
                  $data['error'] = 'PDF is empty of extractable text';
                  $data['info']['noindex'] = 'empty-pdf';
                }

              } catch (Exception $e) {
                $data['errno'] = 701;
                $data['error'] = 'PDF is secured/encrypted; text extraction failed';
                $data['content'] = '';
                $data['info']['noindex'] = 'secured-pdf';
              }

            } else $data['info']['noindex'] = 'missing-pdfparser';
            break;


          /* ***** Unknown MIME-type ******************************* */
          default:
            $data['error'] = 'Not indexed due to unknown MIME type ('.$data['info']['mime_type'].')';
            $data['info']['noindex'] = 'unknown-mime';

        }

      // Else content is identical to the old entry so don't parse
      } else {
        $data['info']['noindex'] = 'not-modified-sha1';
      }

    // Else content is a duplicate of a previously stored page
    } else {

      // Update the stored URL to the shortest version
      if (strlen($url) < strlen($_RDATA['sp_sha1'][$data['info']['sha1']])) {
        $updateURL->execute(array(
          'url' => $url,
          'content_checksum' => $data['info']['sha1']
        ));
      }
      $data['info']['noindex'] = 'duplicate';
    }

  // Else the 'body' of the response was empty
  } else {
    $data['error'] = 'Server returned no content';
    $data['info']['noindex'] = 'empty';
  }



  // Decide whether or not to 'index' / store this page
  switch ($data['info']['noindex']) {

    // ***** There is no 'noindex' reason, so store the page
    case '':
    case 'not-modified-304':
    case 'not-modified-sha1':

      $data['info']['status'] = 'OK';
      if ($referer == '<orphan>') {
        $data['info']['status'] = 'Orphan';
        $_RDATA['sp_status']['Orphan']++;
      }

      // ***** If we got new or updated content for this URL
      if (!$data['info']['noindex']) {

        // If this URL exists (or existed) in the live table...
        if (in_array($url, $_RDATA['sp_exist']) || $referer == '<orphan>') {
          $_RDATA['sp_status']['Updated']++;

          $selectData->execute(array('url' => $url));
          $err = $selectData->errorInfo();
          if ($err[0] != '00000') {
            OS_crawlLog('Database select error: '.$url, 2);
            OS_crawlLog($err[2], 0);
            break 2;
          }
          $row = $selectData->fetchAll()[0];

        // Else provide default values for a new URL
        } else {
          $_RDATA['sp_status']['New']++;

          $row = array(
            'category' => $_ODATA['sp_category_default'],
            'flag_unlisted' => 0,
            'priority' => 0.5
          );
        }

        if ($data['info']['filetime'] <= 0)
          $data['info']['filetime'] = time();

        $port = (!empty($data['url']['port'])) ? ':'.$data['url']['port'] : '';
        $insertTemp->execute(array(
          'url' => $url,
          'url_base' => $data['url']['scheme'].'://'.$data['url']['host'].$port,
          'title' => $data['title'],
          'description' => $data['description'],
          'keywords' => $data['keywords'],
          'category' => $row['category'],
          'weighted' => $data['weighted'],
          'links' => json_encode($data['links'], JSON_INVALID_UTF8_IGNORE),
          'content' => $data['content'],
          'content_mime' => $data['info']['mime_type'],
          'content_charset' => $data['info']['charset'],
          'content_checksum' => $data['info']['sha1'],
          'status' => $data['info']['status'],
          'status_noindex' => $data['info']['noindex'],
          'flag_unlisted' => $row['flag_unlisted'],
          'flag_updated' => 1,
          'last_modified' => $data['info']['filetime'],
          'priority' => $row['priority']
        ));
        if (!$insertTemp->rowCount()) {
          OS_crawlLog('Database primary insert error: '.$url, 2);
          $err = $insertTemp->errorInfo();
          if ($err[0] != '00000') OS_crawlLog($err[2], 0);
        } else $_RDATA['sp_store'][] = $url;


      // ***** URL hasn't been modified since the last successful crawl
      } else { 
        OS_crawlLog('Page hasn\'t been modified since the last successful crawl', 0);

        // Preset the 'last_modified' time and 'priority' until we can
        // find out the actual values from the previous database record
        $data['info']['filetime'] = time();
        $row = array('priority' => 0.5);

        // Get previous entry from existing search database
        $insertNotModified->execute(array('url' => $url));
        if ($insertNotModified->rowCount()) {

          // Mark as 'stored'
          $_RDATA['sp_store'][] = $url;

          // Try to unset 'flag_updated' and update 'status'
          $updateNotModified->execute(array(
            'url' => $url,
            'status' => $data['info']['status']
          ));
          $err = $updateNotModified->errorInfo();
          if ($err[0] != '00000') {
            OS_crawlLog('Database unset \'flag_updated\', set \'status\' update error: '.$url, 2);
            OS_crawlLog($err[2], 0);
          }

          // Get 'priority' & 'last_modified' values for the sitemap
          // Load the previously saved link list to add to the queue
          $selectData->execute(array('url' => $url));
          $err = $selectData->errorInfo();
          if ($err[0] == '00000') {
            $row = $selectData->fetchAll()[0];
            $data['links'] = json_decode($row['links'], true);
            $data['info']['filetime'] = $row['last_modified'];

          } else {
            OS_crawlLog('Database existing table row read error: '.$url, 2);
          }

        // Could not insert previously stored row into temp table
        } else {
          OS_crawlLog('Database \'not-modified\' insert error: '.$url, 2);
          $err = $insertNotModified->errorInfo();
          if ($err[0] != '00000') OS_crawlLog($err[2], 0);
        }
      }

      // Store data for use in the sitemap
      if ($_ODATA['sp_sitemap_file'] &&
          $data['url']['host'] == $_ODATA['sp_sitemap_hostname']) {
        $delta = time() - $data['info']['filetime'];
        $cf = 'always';
        if ($delta > 2700 && $delta <= 64800) $cf = 'hourly';
        if ($delta > 64800 && $delta <= 432000) $cf = 'daily';
        if ($delta > 432000 && $delta <= 2160000) $cf = 'weekly';
        if ($delta > 2160000 && $delta <= 21600000) $cf = 'monthly';
        if ($delta > 21600000 && $delta <= 62400000) $cf = 'yearly';
        if ($delta > 62400000) $cf = 'never';

        $_RDATA['sp_sitemap'][] = array(
          'loc' => str_replace(' ', '%20', htmlentities($url)),
          'lastmod' => date('Y-m-d', $data['info']['filetime']),
          'changefreq' => $cf,
          'priority' => $row['priority']
        );
      }
      break;


    // ***** Otherwise, log the reason why this page was not stored
    case 'duplicate':
      OS_crawlLog('Content is a duplicate of already indexed page: '.$_RDATA['sp_sha1'][$data['info']['sha1']].' (Referrer was: '.$referer.')', 2);
      break;

    case 'timeout':
    case 'network-error':
    case 'couldnt-connect':
      OS_crawlLog($data['error'].': '.$url, 2);
      if ($referer == '<orphan>') $_RDATA['sp_status']['Blocked']++;
      break;

    case 'empty':
    case 'too-large':
    case 'robots-meta':
    case 'robots-http':
    case 'unknown-mime':
    case 'self-reference':
    case 'empty-pdf':
    case 'secured-pdf':
    case 'couldnt-decode-pdf':
      OS_crawlLog($data['error'], 1);
      if ($referer == '<orphan>') $_RDATA['sp_status']['Blocked']++;
      break;

    case '400':
      OS_crawlLog($data['error'].': '.$url.' (Referrer was: '.$referer.')', 2);
      if ($referer == '<orphan>') $_RDATA['sp_status']['Not Found']++;
      break;

    case 'redirect-meta':
    case 'redirect-location':
      OS_crawlLog($data['error'].': '.$url.' (Referrer was: '.$referer.')', 2);
      OS_crawlLog('Page was removed in favour of redirected URL', 0);
      $data['links'][] = $data['info']['redirect_url'];
      break;

    case 'non-canonical':
      OS_crawlLog('Not indexed due to canonical <link> element: '.$data['info']['canonical'], 1);
      OS_crawlLog('Referrer was: '.$referer, 0);
      break;

    default:
      OS_crawlLog('Not indexed due to noindex rule \''.$data['info']['noindex'].'\': '.$url.' (Referrer was: '.$referer.')', 2);
      if ($referer == '<orphan>') $_RDATA['sp_status']['Blocked']++;
      break;

  }

  // Check if we have stored the maximum allowed number of pages
  if (count($_RDATA['sp_store']) >= $_ODATA['sp_limit_store']) {
    OS_crawlLog('Maximum number of crawled pages reached ('.$_ODATA['sp_limit_store'].')', 1);
    $_RDATA['sp_complete'] = true;
    break;
  }

  // If we fetched more links from the content above, parse and add
  // them to the queue
  if (!$data['info']['nofollow']) {
    foreach ($data['links'] as $link) {

      $link = OS_formatURL($link, $data['base']);

      // ***** If this link hasn't been crawled yet
      if (!in_array($link, $_RDATA['sp_links'])) {

        // ... and if link hasn't been queued yet
        foreach ($_RDATA['sp_queue'] as $queue)
          if ($link == $queue[0]) continue 2;

        // ... and if link passes our user filters
        if ($nx = OS_filterURL($link, $data['base'])) {
          OS_crawlLog('Link ignored due to noindex rule \''.$nx.'\': '.$link, 0);
          continue;
        }

        // ... then add the link to the queue
        $_RDATA['sp_queue'][] = array($link, $depth + 1, $url);
      }
    }
  }

  // If we've completed the queue, check for orphans
  if (!count($_RDATA['sp_queue'])) {

    // Diff the previous URL list with the links we've already scanned
    $_RDATA['sp_exist'] = array_diff($_RDATA['sp_exist'], $_RDATA['sp_links']);

    // If we have leftover links, and we aren't autodeleting them
    if (count($_RDATA['sp_exist']) && !$_ODATA['sp_autodelete']) {
      OS_crawlLog('Adding '.count($_RDATA['sp_exist']).' orphan(s) to queue...', 1);

      foreach ($_RDATA['sp_exist'] as $key => $link) {

        // If orphan URL passes our user filters
        if ($nx = OS_filterURL($link, $data['base'])) {
          OS_crawlLog('Orphan URL ignored due to noindex rule \''.$nx.'\': '.$link, 0);
          $_RDATA['sp_status']['Blocked']++;
          continue;
        }

        // ... then add the orphan to the queue
        $_RDATA['sp_queue'][] = array($link, 0, '<orphan>');
      }

    // Else if we stored some pages, we're done
    } else if (count($_RDATA['sp_store'])) {
      $_RDATA['sp_complete'] = true;

    // No pages were stored
    } else OS_crawlLog('No pages could be indexed; check your starting URL(s)', 2);
  }

  gc_collect_cycles();

  usleep($_ODATA['sp_sleep'] * 1000);
  $_RDATA['sp_sleep'] += $_ODATA['sp_sleep'];
}

// ***** Write sitemap
if ($_RDATA['sp_complete'] && $_ODATA['sp_sitemap_file']) {
  if ($_RDATA['sp_sitemap_file'] != 'does not exist') {
    if ($_RDATA['sp_sitemap_file'] != 'not writable') {
      $sm = array('<?xml version="1.0" encoding="UTF-8"?>');
      $sm[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
      foreach ($_RDATA['sp_sitemap'] as $sitemap) {
        $sm[] = '  <url>';
        foreach ($sitemap as $key => $value) {
          if ($key == 'priority' && $value == 0.5) continue;
          $sm[] = '    <'.$key.'>'.$value.'</'.$key.'>';
        }
        $sm[] = '  </url>';
      }
      $sm[] = '</urlset>';

      if (preg_match('/\.xml\.gz$/', $_RDATA['sp_sitemap_file'])) {
        if (function_exists('gzopen')) {
          $smf = gzopen($_RDATA['sp_sitemap_file'], 'w');
          gzwrite($smf, implode("\n", $sm));
          gzclose($smf);
          OS_crawlLog('Sitemap written successfully: '.$_ODATA['sp_sitemap_file'], 1);

        } else OS_crawlLog('Could not write sitemap; PHP gzip functions are not enabled', 2);

      } else if (preg_match('/\.xml$/', $_RDATA['sp_sitemap_file'])) {
        $smf = fopen($_RDATA['sp_sitemap_file'], 'w');
        fwrite($smf, implode("\n", $sm));
        fclose($smf);
        OS_crawlLog('Sitemap written successfully: '.$_ODATA['sp_sitemap_file'], 1);

      } else OS_crawlLog('Sitemap filename ('.$_ODATA['sp_sitemap_file'].') must have extension \'.xml\' or \'.xml.gz\'', 2);

    } else OS_crawlLog('Sitemap file \''.$_ODATA['sp_sitemap_file'].'\' is not writable', 2);

  } else OS_crawlLog('Sitemap file \''.$_ODATA['sp_sitemap_file'].'\' does not exist', 2);
} ?>