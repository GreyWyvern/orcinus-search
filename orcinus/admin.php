<?php /* ***** Orcinus Site Search - Administration UI ************* */


session_start();
if (empty($_SESSION['error'])) $_SESSION['error'] = array();
if (empty($_SESSION['message'])) $_SESSION['message'] = array();

require __DIR__.'/config.php';


/**
 * Display a 'time since' HTML/Javascript counter
 *
 */
function OS_countUp($time, $id = '') {
  $since = time() - $time;
  $periods = array(
    array('d', 'day', 'days'),
    array('h', 'hour', 'hours'),
    array('m', 'minute', 'minutes'),
    array('s', 'second', 'seconds')
  );
  $days = floor($since / 86400); $since %= 86400;
  $hours = floor($since / 3600); $since %= 3600;
  $minutes = floor($since / 60);
  $seconds = $since % 60; ?> 
  <span class="countup_timer" data-start="<?php echo $time; ?>" title="<?php echo date('r', $time); ?>"<?php
    if (!empty($id)) echo ' id="'.htmlspecialchars($id).'"'; ?>>
    <span data-period="days"<?php
      if (!$days) echo ' class="d-none"'; ?>>
      <var><?php echo $days; ?></var>
      <?php echo ($days == 1) ? $periods[0][1] : $periods[0][2]; ?>,
    </span>
    <span data-period="hours"<?php
      if (!$days && !$hours) echo ' class="d-none"'; ?>>
      <var><?php echo $hours; ?></var>
      <?php echo ($hours == 1) ? $periods[1][1] : $periods[1][2]; ?>,
    </span>
    <span data-period="minutes"<?php
      if (!$days && !$hours && !$minutes) echo ' class="d-none"'; ?>>
      <var><?php echo $minutes; ?></var>
      <?php echo ($minutes == 1) ? $periods[2][1] : $periods[2][2]; ?>,
    </span>
    <span data-period="seconds">
      <var><?php echo $seconds; ?></var>
      <?php echo ($seconds == 1) ? $periods[3][1] : $periods[3][2]; ?> ago
    </span>
  </span><?php
}


// ***** Load Maxmind GeoIP2
if (!class_exists('GeoIp2\Database\Reader'))
  if (file_exists(__DIR__.'/geoip2/geoip2.phar'))
    include __DIR__.'/geoip2/geoip2.phar';
if (class_exists('GeoIp2\Database\Reader')) {
  if (file_exists(__DIR__.'/geoip2/GeoLite2-Country.mmdb'))
    $_GEOIP2 = new GeoIp2\Database\Reader(__DIR__.'/geoip2/GeoLite2-Country.mmdb');
} else $_GEOIP2 = false;


// ***** Database derived runtime data
$tableinfo = $_DDATA['pdo']->query(
  'SHOW TABLE STATUS LIKE \''.$_DDATA['tbprefix'].'%\';'
);
$err = $tableinfo->errorInfo();
if ($err[0] == '00000') {
  $tableinfo = $tableinfo->fetchAll();
  foreach ($tableinfo as $table) {
    switch ($table['Name']) {
      case $_DDATA['tbprefix'].'config':
        $_RDATA['s_config_info'] = $table;
        break; 
      case $_DDATA['tbprefix'].'crawldata':
        $_RDATA['s_crawldata_info'] = $table;
        break; 
      case $_DDATA['tbprefix'].'query':
        $_RDATA['s_query_info'] = $table;

    }
  }
} else $_SESSION['error'][] = 'Could not read search database status.';

// Search Database Charsets
$charsets = $_DDATA['pdo']->query(
  'SELECT `content_charset`, COUNT(*) as `num`
    FROM `'.$_DDATA['tbprefix'].'crawldata`
      GROUP BY `content_charset` ORDER BY `num` DESC;'
);
$err = $charsets->errorInfo();
if ($err[0] == '00000') {
  $charsets = $charsets->fetchAll();
  foreach ($charsets as $row) {
    if (!$row['content_charset']) $row['content_charset'] = '<none>';
    $_RDATA['s_crawldata_info']['Charsets'][$row['content_charset']] = $row['num'];
  }
} else $_SESSION['error'][] = 'Could not read charset counts from search database.';


// ***** Other runtime data
$_RDATA['admin_pagination_options'] = array(25, 50, 100, 250, 500, 1000);
if (!in_array($_ODATA['admin_index_pagination'], $_RDATA['admin_pagination_options']))
  OS_setValue('admin_index_pagination', 100);

$_RDATA['admin_pages'] = array(
  'crawler' => 'Crawler',
  'index' => 'Page Index',
  'search' => 'Search'
);
if ($_ODATA['s_limit_query_log'])
  $_RDATA['admin_pages']['queries'] = 'Query Log';

$_RDATA['index_status_list'] = array(
  '<none>', 'OK', 'Orphan', 'Updated', 'Unlisted'
);


// ***** Set session defaults
if (empty($_SESSION['admin_page']) || empty($_RDATA['admin_pages'][$_SESSION['admin_page']]))
  $_SESSION['admin_page'] = 'crawler';

if (!isset($_SESSION['index_page'])) $_SESSION['index_page'] = 1;
if (empty($_SESSION['index_filter_category'])) $_SESSION['index_filter_category'] = '<none>';
if (empty($_SESSION['index_filter_status'])) $_SESSION['index_filter_status'] = '<none>';
if (empty($_SESSION['index_filter_text'])) $_SESSION['index_filter_text'] = '';
if (empty($_SESSION['admin_username'])) $_SESSION['admin_username'] = '';

if (!$_SESSION['admin_username']) {

  // If we are logging in
  if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!empty($_POST['os_submit']) && $_POST['os_submit'] == 'os_admin_login') {
      if (empty($_POST['os_admin_username'])) $_POST['os_admin_username'] = '';
      if (empty($_POST['os_admin_password'])) $_POST['os_admin_password'] = '';

      if ($_POST['os_admin_username'] == $_RDATA['admin_username'] &&
          $_POST['os_admin_password'] == $_RDATA['admin_password']) {
        $_SESSION['admin_username'] = $_RDATA['admin_username'];
        $_SESSION['admin_page'] = 'crawler';

        header('Location: '.$_SERVER['REQUEST_URI']);
        exit();

      } else $_SESSION['error'][] = 'Invalid username or password.';
    }
  }

} else {

  /* ***** Handle POST Requests ************************************** */
  if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // JSON POST request
    // These are usually sent by javascript fetch()
    if ($_SERVER['CONTENT_TYPE'] == 'application/json') {
      $postBody = file_get_contents('php://input');
      $_POST = json_decode($postBody, false);

      $response = array();

      if (empty($_POST->action)) $_POST->action = '';
      switch ($_POST->action) {

        // Set the key for initiating the crawler
        case 'setkey':
          if (!$_ODATA['sp_crawling']) {
            $md5 = md5(hrtime(true));
            OS_setValue('sp_key', $md5);
            OS_setValue('sp_log', '');
            OS_setValue('sp_progress', '0/1');
            $response = array(
              'status' => 'Success',
              'message' => 'Key set to initiate crawler',
              'sp_key' => $md5
            );

          } else {
            $response = array(
              'status' => 'Error',
              'message' => 'Crawler is already running; current progress: '.$_ODATA['sp_progress']
            );
          }
          break;

        // Download a text file of the most recent crawl or query log
        case 'download':
          if (empty($_POST->content)) $_POST->content = '';
          switch ($_POST->content) {
            case 'crawl_log':
              if (!$_ODATA['sp_crawling']) {
                if ($_ODATA['sp_time_end']) {
                  $lines = explode("\n", $_ODATA['sp_log']);

                  if (empty($_POST->grep)) $_POST->grep = '';
                  switch ($_POST->grep) {
                    case 'all': break;
                    case 'errors': $lines = preg_grep('/^[\[\*]/', $lines); break;
                    default: $lines = preg_grep('/^[\[\*\w\d]/', $lines);
                  }

                  if ($_POST->grep) $_POST->grep = '-'.$_POST->grep;

                  header('Content-type: text/plain; charset='.strtolower($_ODATA['s_charset']));
                  header('Content-disposition: attachment; filename="'.
                    'crawl-log'.$_POST->grep.'_'.date('Y-m-d', $_ODATA['sp_time_end']).'.txt"');

                  // UTF-8 byte order mark
                  if (strtolower($_ODATA['s_charset']) == 'utf-8')
                    echo "\xEF\xBB\xBF";

                  die(implode("\n", $lines));

                } else {
                  $response = array(
                    'status' => 'Error',
                    'message' => 'Crawler has not run yet; no log to download'
                  );
                }
              } else {
                $response = array(
                  'status' => 'Error',
                  'message' => 'Currently crawling; try again later'
                );
              }
              break;

            case 'query_log':
              $querylog = $_DDATA['pdo']->query(
                'SELECT `query`, `results`, `stamp`, INET_NTOA(`ip`) AS `ipaddr`
                   FROM `'.$_DDATA['tbprefix'].'query` ORDER BY `stamp` DESC;'
              );
              $err = $querylog->errorInfo();
              if ($err[0] == '00000') {

                $querylog = $querylog->fetchAll();
                if (count($querylog)) {

                  header('Content-type: text/csv; charset='.strtolower($_ODATA['s_charset']));
                  header('Content-disposition: attachment; filename="'.
                    'query-log_'.date('Y-m-d').'.csv"');

                  $output = fopen('php://output', 'w');

                  // UTF-8 byte order mark
                  if (strtolower($_ODATA['s_charset']) == 'utf-8')
                    fwrite($output, "\xEF\xBB\xBF");

                  $headings = array('Query', 'Results', 'Time Stamp', 'IP');
                  if ($_GEOIP2) $headings[] = 'Country';

                  fputcsv($output, $headings);
                  foreach ($querylog as $line) {
                    $line['stamp'] = date('c', $line['stamp']);

                    if ($_GEOIP2) {
                      try {
                        $geo = $_GEOIP2->country($line['ipaddr']);
                      } catch(Exception $e) { $geo = false; }
                      $line['country'] = ($geo) ? $geo->raw['country']['names']['en'] : '';
                    }

                    fputcsv($output, $line);
                  }
                  fclose($output);
                  die();

                } else {
                  $response = array(
                    'status' => 'Error',
                    'message' => 'The query log is empty; nothing to download'
                  );
                }
              } else {
                $response = array(
                  'status' => 'Error',
                  'message' => 'Could not read the query log database'
                );
              }
              break;

            default:
              $response = array(
                'status' => 'Error',
                'message' => 'Invalid content selected to download'
              );
          }
          break;


        // Not used?
        case 'fetch':
          if (empty($_POST->value)) $_POST->value = '';
          if (!empty($_ODATA[$_POST->value])) {
            $response = array(
              'status' => 'Success',
              'message' => trim($_ODATA[$_POST->value])
            );

          } else {
            $response = array(
              'status' => 'Error',
              'message' => 'Invalid value selected to fetch'
            );
          }

      }

      header('Content-type: application/json; charset='.strtolower($_ODATA['s_charset']));
      die(json_encode($response, JSON_INVALID_UTF8_IGNORE));


    // Normal POST request
    } else if (!empty($_POST['os_submit'])) {

      switch ($_POST['os_submit']) {

        // ***** Crawler >> Settings
        case 'os_sp_crawl_config':
          if (isset($_POST['os_sp_starting'])) {
            $_POST['os_sp_starting'] = str_replace("\r\n", "\n", trim($_POST['os_sp_starting']));
            $_POST['os_sp_starting'] = preg_replace('/\n+/', "\n", $_POST['os_sp_starting']);
            $_POST['os_sp_starting'] = substr($_POST['os_sp_starting'], 0, 4095);
            $_POST['os_sp_starting'] = explode("\n", $_POST['os_sp_starting']);
            foreach ($_POST['os_sp_starting'] as $key => $starting) {
              $starting = preg_replace(
                '/#.*$/',
                '',
                filter_var(
                  str_replace(' ', '%20', $starting),
                  FILTER_SANITIZE_URL
                )
              );
              $_POST['os_sp_starting'][$key] = str_replace('%20', ' ', $starting);
            }
            $_POST['os_sp_starting'] = array_filter($_POST['os_sp_starting'], function($a) {
              return preg_match('/^(([^:\/?#]+):)(\/\/([^\/?#]+))([^?#]*)(\?([^#]*))?(#(.*))?/', $a);
            });
            if (!count($_POST['os_sp_starting'])) {
              $_POST['os_sp_starting'][] = $_ODATA['admin_install_domain'].'/';
              $_SESSION['error'][] = 'Cannot have an empty or invalid Starting URLs field.';
            }
            OS_setValue('sp_starting', implode("\n", $_POST['os_sp_starting']));
          }

          if (isset($_POST['os_sp_useragent'])) {
            $_POST['os_sp_useragent'] = filter_var($_POST['os_sp_useragent'], FILTER_SANITIZE_SPECIAL_CHARS);
            OS_setValue('sp_useragent', substr($_POST['os_sp_useragent'], 0, 255));
          }

          if (isset($_POST['os_sp_cookies']) && $_POST['os_sp_cookies'] == '1') {
            $_POST['os_sp_cookies'] = 1;
          } else $_POST['os_sp_cookies'] = 0;
          OS_setValue('sp_cookies', $_POST['os_sp_cookies']);

          if (isset($_POST['os_sp_ifmodifiedsince']) && $_POST['os_sp_ifmodifiedsince'] == '1') {
            $_POST['os_sp_ifmodifiedsince'] = 1;
          } else $_POST['os_sp_ifmodifiedsince'] = 0;
          OS_setValue('sp_ifmodifiedsince', $_POST['os_sp_ifmodifiedsince']);

          if (isset($_POST['os_sp_autodelete']) && $_POST['os_sp_autodelete'] == '1') {
            $_POST['os_sp_autodelete'] = 1;
          } else $_POST['os_sp_autodelete'] = 0;
          OS_setValue('sp_autodelete', $_POST['os_sp_autodelete']);

          if (isset($_POST['os_sp_timeout_url'])) {
            $_POST['os_sp_timeout_url'] = max(1, min(65535, (int)$_POST['os_sp_timeout_url']));
            OS_setValue('sp_timeout_url', (int)$_POST['os_sp_timeout_url']);
          }

          if (isset($_POST['os_sp_timeout_crawl'])) {
            $_POST['os_sp_timeout_crawl'] = max(1, min(65535, (int)$_POST['os_sp_timeout_crawl']));
            OS_setValue('sp_timeout_crawl', (int)$_POST['os_sp_timeout_crawl']);
          }

          if (isset($_POST['os_sp_sleep'])) {
            $_POST['os_sp_sleep'] = max(0, min(65535, (int)$_POST['os_sp_sleep']));
            OS_setValue('sp_sleep', (int)$_POST['os_sp_sleep']);
          }

          if (isset($_POST['os_sp_limit_crawl'])) {
            $_POST['os_sp_limit_crawl'] = max(1, min(65535, (int)$_POST['os_sp_limit_crawl']));
            OS_setValue('sp_limit_crawl', (int)$_POST['os_sp_limit_crawl']);
          }

          if (isset($_POST['os_sp_limit_store'])) {
            $_POST['os_sp_limit_store'] = max(1, min(65535, (int)$_POST['os_sp_limit_store']));
            OS_setValue('sp_limit_store', $_POST['os_sp_limit_store']);
          }

          if (isset($_POST['os_sp_limit_depth'])) {
            $_POST['os_sp_limit_depth'] = max(1, min(255, (int)$_POST['os_sp_limit_depth']));
            OS_setValue('sp_limit_depth', (int)$_POST['os_sp_limit_depth']);
          }

          if (isset($_POST['os_sp_limit_filesize'])) {
            $_POST['os_sp_limit_filesize'] = max(1, min(65535, (int)$_POST['os_sp_limit_filesize']));
            OS_setValue('sp_limit_filesize', (int)$_POST['os_sp_limit_filesize']);
          }

          if (isset($_POST['os_sp_require_url'])) {
            $_POST['os_sp_require_url'] = str_replace("\r\n", "\n", trim($_POST['os_sp_require_url']));
            $_POST['os_sp_require_url'] = preg_replace('/\n+/', "\n", $_POST['os_sp_require_url']);
            $_POST['os_sp_require_url'] = substr($_POST['os_sp_require_url'], 0, 4095);
            $_POST['os_sp_require_url'] = explode("\n", $_POST['os_sp_require_url']);
            foreach ($_POST['os_sp_require_url'] as $key => $require)
              $_POST['os_sp_require_url'][$key] = filter_var($require, FILTER_SANITIZE_URL);
            OS_setValue('sp_require_url', implode("\n", $_POST['os_sp_require_url']));
          }

          if (isset($_POST['os_sp_ignore_url'])) {
            $_POST['os_sp_ignore_url'] = str_replace("\r\n", "\n", trim($_POST['os_sp_ignore_url']));
            $_POST['os_sp_ignore_url'] = preg_replace('/\n+/', "\n", $_POST['os_sp_ignore_url']);
            $_POST['os_sp_ignore_url'] = substr($_POST['os_sp_ignore_url'], 0, 4095);
            $_POST['os_sp_ignore_url'] = explode("\n", $_POST['os_sp_ignore_url']);
            foreach ($_POST['os_sp_ignore_url'] as $key => $require)
              $_POST['os_sp_ignore_url'][$key] = filter_var($require, FILTER_SANITIZE_URL);
            OS_setValue('sp_ignore_url', implode("\n", $_POST['os_sp_ignore_url']));
          }

          if (isset($_POST['os_sp_ignore_ext'])) {
            $_POST['os_sp_ignore_ext'] = preg_replace(
              array('/[^\w\d\. _-]/', '/ {2,}/'),
              array('', ' '),
              trim($_POST['os_sp_ignore_ext'])
            );
            OS_setValue('sp_ignore_ext', substr($_POST['os_sp_ignore_ext'], 0, 4095));
          }

          if (isset($_POST['os_sp_category_default'])) {
            $_POST['os_sp_category_default'] = preg_replace(array('/\s/', '/ {2,}/'), ' ', trim($_POST['os_sp_category_default']));
            $_POST['os_sp_category_default'] = preg_replace('/[^\w \d-]/', '', $_POST['os_sp_category_default']);
            if ($_POST['os_sp_category_default']) {
              OS_setValue('sp_category_default', substr($_POST['os_sp_category_default'], 0, 30));
            } else $_SESSION['error'][] = 'Category names may only contain letters, numbers, spaces or dashes.';
          } else $_SESSION['error'][] = 'Please supply a category name.';

          if (isset($_POST['os_sp_ignore_css'])) {
            $_POST['os_sp_ignore_css'] = preg_replace(
              array('/[^\w\d\. #_:-]/', '/ {2,}/'),
              array('', ' '),
              trim($_POST['os_sp_ignore_css'])
            );
            OS_setValue('sp_ignore_css', substr($_POST['os_sp_ignore_css'], 0, 4095));
          }

          if (isset($_POST['os_sp_title_strip'])) {
            $_POST['os_sp_title_strip'] = str_replace("\r\n", "\n", trim($_POST['os_sp_title_strip']));
            $_POST['os_sp_title_strip'] = preg_replace('/\n+/', "\n", $_POST['os_sp_title_strip']);
            $_POST['os_sp_title_strip'] = substr($_POST['os_sp_title_strip'], 0, 4095);
            $_POST['os_sp_title_strip'] = explode("\n", $_POST['os_sp_title_strip']);
            foreach ($_POST['os_sp_title_strip'] as $key => $require)
              $_POST['os_sp_title_strip'][$key] = filter_var($require, FILTER_SANITIZE_SPECIAL_CHARS);
            OS_setValue('sp_title_strip', implode("\n", $_POST['os_sp_title_strip']));
          }

          $_SESSION['message'][] = 'Crawl settings have been saved.';
          break;


        // ***** Crawler >> Administration
        case 'os_admin_config':
          if (isset($_POST['os_sp_interval'])) {
            $_POST['os_sp_interval'] = max(0, min(255, (int)$_POST['os_sp_interval']));
            OS_setValue('sp_interval', (int)$_POST['os_sp_interval']);
          }

          if (isset($_POST['os_sp_interval_start'])) {
            if (preg_match('/\d\d:\d\d(:\d\d)?/', $_POST['os_sp_interval_start'])) {
              OS_setValue('sp_interval_start', $_POST['os_sp_interval_start']);
            } else $_SESSION['error'][] = 'Unexpected start time format.';
          }

          if (isset($_POST['os_sp_interval_stop'])) {
            if (preg_match('/\d\d:\d\d(:\d\d)?/', $_POST['os_sp_interval_stop'])) {
              OS_setValue('sp_interval_stop', $_POST['os_sp_interval_stop']);
            } else $_SESSION['error'][] = 'Unexpected stop time format.';
          }

          if (isset($_POST['os_sp_timezone']))
            if (in_array($_POST['os_sp_timezone'], timezone_identifiers_list()))
              OS_setValue('sp_timezone', $_POST['os_sp_timezone']);

          if (isset($_POST['os_sp_email_success']) && $_POST['os_sp_email_success'] == '1') {
            $_POST['os_sp_email_success'] = 1;
          } else $_POST['os_sp_email_success'] = 0;
          OS_setValue('sp_email_success', $_POST['os_sp_email_success']);

          if (isset($_POST['os_sp_email_failure']) && $_POST['os_sp_email_failure'] == '1') {
            $_POST['os_sp_email_failure'] = 1;
          } else $_POST['os_sp_email_failure'] = 0;
          OS_setValue('sp_email_failure', $_POST['os_sp_email_failure']);

          if (isset($_POST['os_admin_email'])) {
            if ($_MAIL) {
              $_POST['os_admin_email'] = str_replace("\r\n", "\n", $_POST['os_admin_email']);
              $_POST['os_admin_email'] = preg_replace('/\n+/', "\n", $_POST['os_admin_email']);
              $_POST['os_admin_email'] = substr($_POST['os_admin_email'], 0, 4095);
              $_POST['os_admin_email'] = explode("\n", $_POST['os_admin_email']);
              foreach ($_POST['os_admin_email'] as $key => $admin_email) {
                $email = $_MAIL->parseAddresses($admin_email);
                if (count($email)) {
                  if ($email[0]['name']) {
                    $_POST['os_admin_email'][$key] = $email[0]['name'].' <'.$email[0]['address'].'>';
                  } else $_POST['os_admin_email'][$key] = $email[0]['address'];
                } else {
                  $_SESSION['error'][] = 'Invalid To: email address \''.$admin_email.'\'.';
                  unset($_POST['os_admin_email'][$key]);
                }
              }
              OS_setValue('admin_email', implode("\n", array_values($_POST['os_admin_email'])));
            } else $_SESSION['error'][] = 'PHPMailer needs to be installed to parse new email addresses.';
          }

          $_SESSION['message'][] = 'Crawl administration settings have been saved.';
          break;

        // ***** Crawler >> Sitemap
        case 'os_sp_sitemap_config':
          if (isset($_POST['os_sp_sitemap_file'])) {
            $_POST['os_sp_sitemap_file'] = substr($_POST['os_sp_sitemap_file'], 0, 255);
            $_POST['os_sp_sitemap_file'] = filter_var($_POST['os_sp_sitemap_file'], FILTER_SANITIZE_URL);
            if ($_POST['os_sp_sitemap_file']) {
              if (preg_match('/\.xml(\.gz)?$/', $_POST['os_sp_sitemap_file'])) {
                OS_setValue('sp_sitemap_file', $_POST['os_sp_sitemap_file']);
              } else $_SESSION['error'][] = 'Sitemap filename must end witn .xml or .xml.gz';
            } else OS_setValue('sp_sitemap_file', '');
          }

          if (isset($_POST['os_sp_sitemap_hostname'])) {
            $_POST['os_sp_sitemap_hostname'] = filter_var($_POST['os_sp_sitemap_hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
            if ($_POST['os_sp_sitemap_hostname']) {
              OS_setValue('sp_sitemap_hostname', $_POST['os_sp_sitemap_hostname']);
            } else $_SESSION['error'][] = 'Invalid sitemap hostname.';
          }

          $_SESSION['message'][] = 'Sitemap settings have been saved.';
          break;


        // ***** Page Index >> With Selected...
        case 'os_index_with_selected':
          if (empty($_POST['os_index_pages'])) $_POST['os_index_pages'] = array();
          if (is_array($_POST['os_index_pages'])) {

            $checksums_good = true;
            foreach ($_POST['os_index_pages'] as $key => $content_checksum) {
              $content_checksum = base64_decode($content_checksum);
              if ($content_checksum && strlen($content_checksum) == 20) {
                $_POST['os_index_pages'][$key] = $content_checksum;
              } else $checksums_good = false;
            }

            if ($checksums_good) {
              if (empty($_POST['os_index_select_action'])) $_POST['os_index_select_action'] = '';
              switch ($_POST['os_index_select_action']) {
                case 'delete':
                  $delete = $_DDATA['pdo']->prepare(
                    'DELETE FROM `'.$_DDATA['tbprefix'].'crawldata` WHERE `content_checksum`=:content_checksum;'
                  );

                  foreach ($_POST['os_index_pages'] as $content_checksum) {
                    $delete->execute(array('content_checksum' => $content_checksum));
                    $err = $delete->errorInfo();
                    if ($err[0] != '00000') {
                      $_SESSION['error'][] = 'Database error on attempt to delete: '.$err[2];
                      break;
                    }
                  }
                  break;

                case 'category':
                  if (!empty($_POST['os_apply_new_category'])) {
                    $_POST['os_apply_new_category'] = preg_replace(array('/\s/', '/ {2,}/'), ' ', trim($_POST['os_apply_new_category']));
                    $_POST['os_apply_new_category'] = preg_replace('/[^\w \d-]/', '', $_POST['os_apply_new_category']);
                    $_POST['os_apply_new_category'] = substr($_POST['os_apply_new_category'], 0, 30);

                    if ($_POST['os_apply_new_category']) {
                      $update = $_DDATA['pdo']->prepare(
                        'UPDATE `'.$_DDATA['tbprefix'].'crawldata` SET `category`=:category WHERE `content_checksum`=:content_checksum;'
                      );

                      foreach ($_POST['os_index_pages'] as $content_checksum) {
                        $update->execute(array(
                          'category' => $_POST['os_apply_new_category'],
                          'content_checksum' => $content_checksum
                        ));
                        $err = $update->errorInfo();
                        if ($err[0] != '00000') {
                          $_SESSION['error'][] = 'Database error on attempt to update category: '.$err[2];
                          break;
                        }
                      }

                      $_SESSION['index_filter_category'] = '<none>';
                    } else $_SESSION['error'][] = 'Category names may only contain letters, numbers, spaces or dashes.';
                  } else $_SESSION['error'][] = 'Please supply a category name.';
                  break;

                case 'priority':
                  if (!empty($_POST['os_apply_new_priority'])) {
                    $_POST['os_apply_new_priority'] = (float)$_POST['os_apply_new_priority'];
                    $_POST['os_apply_new_priority'] = max(0, min(1, $_POST['os_apply_new_priority']));
                    $_POST['os_apply_new_priority'] = round($_POST['os_apply_new_priority'], 5);

                    $update = $_DDATA['pdo']->prepare(
                      'UPDATE `'.$_DDATA['tbprefix'].'crawldata` SET `priority`=:priority WHERE `content_checksum`=:content_checksum;'
                    );

                    foreach ($_POST['os_index_pages'] as $content_checksum) {
                      $update->execute(array(
                        'priority' => $_POST['os_apply_new_priority'],
                        'content_checksum' => $content_checksum
                      ));
                      $err = $update->errorInfo();
                      if ($err[0] != '00000') {
                        $_SESSION['error'][] = 'Database error on attempt to update priority: '.$err[2];
                        break;
                      }
                    }
                  } else $_SESSION['error'][] = 'Please supply a priority value.';
                  break;

                case 'unlisted':
                  $update = $_DDATA['pdo']->prepare(
                    'UPDATE `'.$_DDATA['tbprefix'].'crawldata` SET `flag_unlisted`=!`flag_unlisted` WHERE `content_checksum`=:content_checksum;'
                  );

                  foreach ($_POST['os_index_pages'] as $content_checksum) {
                    $update->execute(array('content_checksum' => $content_checksum));
                    $err = $update->errorInfo();
                    if ($err[0] != '00000') {
                      $_SESSION['error'][] = 'Database error on attempt to toggle \'unlisted\' status: '.$err[2];
                      break;
                    }
                  }
                  break;

                default:
                  $_SESSION['error'][] = 'Unknown command.';

              }
            } else $_SESSION['error'][] = 'Bad page checksum(s) given by user.';
          } else $_SESSION['error'][] = 'Badly formed list of pages; could not perform an action.';
          break;


        // ***** Page Index >> Text Match filter
        case 'os_index_filter_text':
          if (empty($_POST['os_index_filter_text'])) $_POST['os_index_filter_text'] = '';
          $_POST['os_index_filter_text'] = filter_var($_POST['os_index_filter_text'], FILTER_SANITIZE_URL);
          $_SESSION['index_filter_text'] = $_POST['os_index_filter_text'];
          $_SESSION['index_page'] = 1;
          break;


        // ***** Search >> Search Settings
        case 'os_s_search_config':
          if (isset($_POST['os_s_limit_query'])) {
            $_POST['os_s_limit_query'] = max(1, min(255, (int)$_POST['os_s_limit_query']));
            OS_setValue('s_limit_query', (int)$_POST['os_s_limit_query']);
          }

          if (isset($_POST['os_s_limit_terms'])) {
            $_POST['os_s_limit_terms'] = max(1, min(255, (int)$_POST['os_s_limit_terms']));
            OS_setValue('s_limit_terms', (int)$_POST['os_s_limit_terms']);
          }

          if (isset($_POST['os_s_limit_term_length'])) {
            $_POST['os_s_limit_term_length'] = max(1, min(255, (int)$_POST['os_s_limit_term_length']));
            OS_setValue('s_limit_term_length', (int)$_POST['os_s_limit_term_length']);
          }

          if (!isset($_POST['os_s_weight_title'])) $_POST['os_s_weight_title'] = $_RDATA['s_weights']['title'];
          $_POST['os_s_weight_title'] = number_format(max(0, (float)$_POST['os_s_weight_title']), 1, '.', '');

          if (!isset($_POST['os_s_weight_body'])) $_POST['os_s_weight_body'] = $_RDATA['s_weights']['body'];
          $_POST['os_s_weight_body'] = number_format(max(0, (float)$_POST['os_s_weight_body']), 1, '.', '');

          if (!isset($_POST['os_s_weight_keywords'])) $_POST['os_s_weight_keywords'] = $_RDATA['s_weights']['keywords'];
          $_POST['os_s_weight_keywords'] = number_format(max(0, (float)$_POST['os_s_weight_keywords']), 1, '.', '');

          if (!isset($_POST['os_s_weight_description'])) $_POST['os_s_weight_description'] = $_RDATA['s_weights']['description'];
          $_POST['os_s_weight_description'] = number_format(max(0, (float)$_POST['os_s_weight_description']), 1, '.', '');

          if (!isset($_POST['os_s_weight_url'])) $_POST['os_s_weight_url'] = $_RDATA['s_weights']['url'];
          $_POST['os_s_weight_url'] = number_format(max(0, (float)$_POST['os_s_weight_url']), 1, '.', '');

          if (!isset($_POST['os_s_weight_multi'])) $_POST['os_s_weight_multi'] = $_RDATA['s_weights']['multi'];
          $_POST['os_s_weight_multi'] = number_format(max(0, (float)$_POST['os_s_weight_multi']), 1, '.', '');

          if (!isset($_POST['os_s_weight_important'])) $_POST['os_s_weight_important'] = $_RDATA['s_weights']['important'];
          $_POST['os_s_weight_important'] = number_format(max(0, (float)$_POST['os_s_weight_important']), 1, '.', '');

          if (!isset($_POST['os_s_weight_css_value'])) $_POST['os_s_weight_css_value'] = $_RDATA['s_weights']['css_value'];
          $_POST['os_s_weight_css_value'] = number_format(max(0, (float)$_POST['os_s_weight_css_value']), 1, '.', '');

          OS_setValue('s_weights', implode('%', array(
            $_POST['os_s_weight_title'],
            $_POST['os_s_weight_body'],
            $_POST['os_s_weight_keywords'],
            $_POST['os_s_weight_description'],
            $_POST['os_s_weight_css_value'],
            $_POST['os_s_weight_url'],
            $_POST['os_s_weight_multi'],
            $_POST['os_s_weight_important']
          )));

          if (isset($_POST['os_s_weight_css'])) {
            $_POST['os_s_weight_css'] = preg_replace(
              array('/[^\w\d\. #_:-]/', '/ {2,}/'),
              array('', ' '),
              trim($_POST['os_s_weight_css'])
            );
            OS_setValue('s_weight_css', substr($_POST['os_s_weight_css'], 0, 4095));
          }

          if (isset($_POST['os_s_charset'])) {
            $_POST['os_s_charset'] = substr($_POST['os_s_charset'], 0, 63);
            $_POST['os_s_charset'] = preg_replace('/[^\w\d\.:_-]/', '', $_POST['os_s_charset']);
            OS_setValue('s_charset', $_POST['os_s_charset']);
          }

          if (isset($_POST['os_s_limit_results'])) {
            $_POST['os_s_limit_results'] = max(1, min(255, (int)$_POST['os_s_limit_results']));
            OS_setValue('s_limit_results', (int)$_POST['os_s_limit_results']);
          }

          if (isset($_POST['os_s_results_pagination'])) {
            $_POST['os_s_results_pagination'] = max(1, min(255, (int)$_POST['os_s_results_pagination']));
            OS_setValue('s_results_pagination', (int)$_POST['os_s_results_pagination']);
          }

          if (isset($_POST['os_s_limit_matchtext'])) {
            $_POST['os_s_limit_matchtext'] = max(1, min(65535, (int)$_POST['os_s_limit_matchtext']));
            OS_setValue('s_limit_matchtext', $_POST['os_s_limit_matchtext']);
          }

          if (isset($_POST['os_s_show_orphans']) && $_POST['os_s_show_orphans'] == '1') {
            $_POST['os_s_show_orphans'] = 1;
          } else $_POST['os_s_show_orphans'] = 0;
          OS_setValue('s_show_orphans', $_POST['os_s_show_orphans']);

          if (isset($_POST['os_s_show_filetype_html']) && $_POST['os_s_show_filetype_html'] == '1') {
            $_POST['os_s_show_filetype_html'] = 1;
          } else $_POST['os_s_show_filetype_html'] = 0;
          OS_setValue('s_show_filetype_html', $_POST['os_s_show_filetype_html']);

          $_SESSION['message'][] = 'Search settings have been saved.';
          break;


        // ***** Search >> Search Template
        case 'os_s_search_template':
          if (isset($_POST['os_s_result_template'])) {
            $_POST['os_s_result_template'] = str_replace("\r", '', $_POST['os_s_result_template']);
            OS_setValue('s_result_template', substr($_POST['os_s_result_template'], 0, 65535));
            $_SESSION['message'][] = 'Search result template updated.';
          }
          break;


        // ***** Search >> Search Result Cache
        case 'os_s_cache_config':
          if (isset($_POST['os_s_limit_query_log'])) {
            $_POST['os_s_limit_query_log'] = max(0, min(255, (int)$_POST['os_s_limit_query_log']));
            OS_setValue('s_limit_query_log', $_POST['os_s_limit_query_log']);
          }

          if (isset($_POST['os_s_limit_cache'])) {
            $_POST['os_s_limit_cache'] = max(0, min(65535, (int)$_POST['os_s_limit_cache']));
            OS_setValue('s_limit_cache', $_POST['os_s_limit_cache']);
          }
          break;


        // ***** Search >> Search Result Purge
        case 'os_s_cache_purge':
          $purge = $_DDATA['pdo']->query(
            'UPDATE `'.$_DDATA['tbprefix'].'query` SET `cache`=\'\';'
          );
          $err = $purge->errorInfo();
          if ($err[0] == '00000') {
            $_RDATA['s_cache_size'] = 0;
            $_SESSION['message'][] = 'Search result cache has been purged.';
          } else $_SESSION['error'][] = 'Could not purge search result cache.';
          break;


        // ***** Search >> Offline Javascript
        case 'os_jw_config':

        // ***** Search >> Write Offline Javascript
        case 'os_jw_write':
          if (isset($_POST['os_jw_hostname'])) {
            $_POST['os_jw_hostname'] = filter_var($_POST['os_jw_hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
            if ($_POST['os_jw_hostname']) {
              OS_setValue('jw_hostname', $_POST['os_jw_hostname']);
            } else $_SESSION['error'][] = 'Invalid sitemap hostname.';
          }

          if (isset($_POST['os_jw_compression'])) {
            $_POST['os_jw_compression'] = max(0, min(100, (int)$_POST['os_jw_compression']));
            OS_setValue('jw_compression', (int)$_POST['os_jw_compression']);
          }

          if ($_POST['os_submit'] == 'os_jw_config') {
            $_SESSION['message'][] = 'Offline javascript search settings have been saved.';
            break;
          }

          // ***** Write to and download the Offline Javascript file
          $query_status = ($_ODATA['s_show_orphans']) ? '(`status`=\'OK\' || `status`=\'Orphan\')' : '`status`=\'OK\'';

          $select = $_DDATA['pdo']->query(
            'SELECT `url`, `title`, `description`, `keywords`, `category`,
                    `content_mime`, `weighted`, `content`, `priority`
              FROM `'.$_DDATA['tbprefix'].'crawldata`
                WHERE `flag_unlisted`<>1 AND '.$query_status.' AND
                      `url_base` LIKE \'%'.addslashes($_ODATA['jw_hostname']).'\';'
          );
          $err = $select->errorInfo();
          if ($err[0] == '00000') {
            $select = $select->fetchAll();

            // If compression value is less than 100 then get a word
            // list frequency report from all indexed pages
            if ($_ODATA['jw_compression'] < 100) {

              $words = array();
              foreach ($select as $key => $row) {
                $select[$key]['words'] = array_unique(explode(' ', $row['content']));

                foreach ($select[$key]['words'] as $index => $word) {
                  if (!$word) continue;
                  if (empty($words[$word])) {
                    $words[$word] = 1;
                  } else $words[$word]++;
                }
              }

              // Use the word frequency report to create a filter of
              // words that are more common than the compression
              // threshold
              $compressionFilter = array();
              Foreach ($words as $word => $count)
                if (($count / count($select)) * 100 >= $_ODATA['jw_compression'])
                  $compressionFilter[] = $word;
            }

            $repStr = '/^'.preg_quote($_ODATA['jw_hostname'], '/').'/';

            foreach ($select as $key => $row) {

              // Use the compression filter to remove all of the most
              // common words from the content of this page
              if ($_ODATA['jw_compression'] < 100) {
                $select[$key]['words'] = array_diff($row['words'], $compressionFilter);
                $select[$key]['words'] = implode(' ', $select[$key]['words']);
              } else $select[$key]['words'] = $row['content'];

              // Remove the common domain from all URLs
              $select[$key]['url'] = preg_replace($repStr, '', $row['url']);

              // Format non-.html filenames into .html ones
              if ($row['content_mime'] == 'text/html') {
                $rq = explode('?', $select[$key]['url'], 2);
                if ($rq[0] == '' || $rq[0][strlen($rq[0]) - 1] == '/')
                  $rq[0] .= 'index.html';

                if (!preg_match('/\.html?$/', $rq[0]))
                  $rq[0] .= '.html';

                $select[$key]['url'] = implode('?', $rq);
              }
            }

            // Start JS file output
            ob_start(); ?>
/* ********************************************************************
 * Orcinus Site Search <?php echo $_ODATA['version']; ?> - Offline Javascript Search File
 *  - Generated <?php echo date('r'); ?> 
 *  - Requires mustache.js
 *
 */

function os_preg_quote(str, delimiter) {
  return (str + '').replace(new RegExp(
    '[.\\\\+*?\\[\\^\\]$(){}=!<>|:\\' + (delimiter || '') + '-]', 'g'),
    '\\$&'
  );
}

// ***** Variable Migration
let os_rdata = {
  s_latin: <?php
    echo json_encode(
      $_RDATA['s_latin'],
      JSON_INVALID_UTF8_IGNORE
    );
  ?>,
  s_filetypes: <?php
    echo json_encode(
      $_RDATA['s_filetypes'],
      JSON_INVALID_UTF8_IGNORE
    );
  ?>,
  s_category_list: <?php
    echo json_encode(
      $_RDATA['s_category_list'],
      JSON_INVALID_UTF8_IGNORE
    );
  ?>,
  s_weights: <?php
    echo json_encode(
      $_RDATA['s_weights'],
      JSON_INVALID_UTF8_IGNORE
    );
  ?> 
};

Object.keys(os_rdata.s_weights).forEach(key => {
  os_rdata.s_weights[key] = parseFloat(os_rdata.s_weights[key]);
});

let os_odata = {
  version: '<?php echo $_ODATA['version']; ?>',
  jw_compression: <?php echo $_ODATA['jw_compression']; ?>,
  s_limit_query: <?php echo $_ODATA['s_limit_query']; ?>,
  s_limit_terms: <?php echo $_ODATA['s_limit_terms']; ?>,
  s_limit_term_length: <?php echo $_ODATA['s_limit_term_length']; ?>,
  s_limit_matchtext: <?php echo $_ODATA['s_limit_matchtext']; ?>,
  s_show_filetype_html: <?php echo $_ODATA['s_show_filetype_html']; ?>,
  s_results_pagination: <?php echo $_ODATA['s_results_pagination']; ?>,
  s_limit_results: <?php echo $_ODATA['s_limit_results']; ?>,
  s_result_template: <?php
    echo json_encode(
      preg_replace('/\s{2,}/', ' ', $_ODATA['s_result_template']),
      JSON_INVALID_UTF8_IGNORE
    );
  ?>
};

let os_sdata = {
  terms: [],
  formatted: [],
  results: [],
  pages: 1,
  time: (new Date()).getTime()
};

let os_request = {};
const os_params = new URLSearchParams(window.location.search);


// ***** Page Object Constructor
function os_page(content_mime, url, category, priority, title, description, keywords, weighted, content) {
  this.content_mime = content_mime;
  this.url = url;
  this.category = category;
  this.priority = parseFloat(priority);
  this.title = title;
  this.description = description;
  this.keywords = keywords;
  this.weighted = weighted;
  this.content = content;

  this.matchtext = [];

  this.relevance = 0;
  this.multi = -1;
  this.phrase = 0;
}

// ***** Search Database
let os_crawldata = [<?php
  foreach ($select as $row) { ?> 
new os_page('<?php
      echo addslashes($row['content_mime']); ?>', '<?php
      echo addslashes($row['url']); ?>', '<?php
      echo addslashes($row['category']); ?>', '<?php
      echo $row['priority']; ?>', '<?php
      echo addslashes($row['title']); ?>', '<?php
      echo addslashes($row['description']); ?>', '<?php
      echo addslashes($row['keywords']); ?>', '<?php
      echo addslashes($row['weighted']); ?>', '<?php
      echo addslashes($row['words']);
    ?>'),<?php
  } ?> 
];

// ***** Return list of all pages for typeahead
function os_return_all() {
  let fullList = [];
  for (let x = 0; x < os_crawldata.length; x++) {
    fullList.push({
      title: os_crawldata[x].title,
      url: os_crawldata[x].url
    });
  }
  return fullList;
}

// {{{{{ Create the Mustache template
let os_TEMPLATE = {
  version: os_odata.version,
  searchable: false,
  addError: function(text) {
    if (!this.errors) {
      this.errors = {};
      this.errors.error_list = [];
    }
    this.errors.error_list.push(text);
  }
};

// Check if there are rows in the search database
if (os_crawldata.length) {
  os_TEMPLATE.searchable = {};
  os_TEMPLATE.searchable.form_action = window.location.pathname;
  os_TEMPLATE.searchable.limit_query = os_odata.s_limit_query;
  os_TEMPLATE.searchable.limit_term_length = os_odata.s_limit_term_length;

  os_request.c = os_params.get('c');
  if (!os_request.c || !os_rdata.s_category_list[os_request.c])
    os_request.c = '<none>';

  if (os_rdata.s_category_list.length > 2) {
    os_TEMPLATE.searchable.categories = {};
    os_TEMPLATE.searchable.categories.category_list = [];
    Object.keys(os_rdata.s_category_list).forEach(category => {
      let cat = {};
      cat.name = (category == '<none>') ? 'All Categories' : category;
      cat.value = category;
      cat.selected = (os_request.c == category);
      os_TEMPLATE.searchable.categories.category_list.push(cat);
    });
  }

  os_request.q = os_params.get('q');
  if (!os_request.q) os_request.q = '';

  os_request.q = os_request.q.trim().replace(/\s/, ' ').replace(/ {2,}/, ' ');

  // If there is a text request
  if (os_request.q) {

    // If compression level is < 100, remove all quotation marks
    if (os_odata.jw_compression < 100)
      os_request.q = os_request.q.replace(/"/g, '');

    if (os_request.q.length > os_odata.s_limit_query) {
      os_request.q = os_request.q.substring(0, os_odata.s_limit_query);
      os_TEMPLATE.addError('Search query truncated to maximum ' + os_odata.s_limit_query + ' characters');
    }

    os_TEMPLATE.searchable.request_q = os_request.q;

    // Split request string on quotation marks (")
    let request = (' ' + os_request.q + ' ').split('"');
    for (let x = 0; x < request.length && os_sdata.terms.length < os_odata.s_limit_terms; x++) {

      // Every second + 1 group of terms just a list of terms
      if (!(x % 2)) {

        // Split this list of terms on spaces
        request[x] = request[x].split(' ');

        for (let y = 0, t; y < request[x].length; y++) {
          t = request[x][y];
          if (!t) continue

          // Leading + means important, a MUST match
          if (t[0] == '+') {

            // Just count it as a 'phrase' of one word, functionally equivalent
            os_sdata.terms.push(['phrase', t.substring(1), false]);

          // Leading - means negative, a MUST exclude
          } else if (t[0] == '-') {
            os_sdata.terms.push(['exclude', t.substring(1), false]);

          // Restrict to a specific filetype (not yet implemented)
          // Really, we'd only allow HTML, XML and PDF here, maybe JPG?
          } else if (t.toLowerCase().indexOf('filetype:') === 0) {
            t = t.substring(9).trim();
            if (t && os_rdata.s_filetypes[t.toUpperCase()])
              os_sdata.terms.push(['filetype', t, false]);

          // Else if the term is greater than the term length limit, add it
          } else if (t.length >= os_odata.s_limit_term_length)
            os_sdata.terms.push(['term', t, false]);
        }

      // Every second group of terms is a phrase, a MUST match
      } else os_sdata.terms.push(['phrase', request[x], false]);
    }


    // If we successfully procured some terms
    if (os_sdata.terms.length) {
      os_TEMPLATE.searchable.searched = {};
      if (os_request.c != '<none>') {
        os_TEMPLATE.searchable.searched.category = {};
        os_TEMPLATE.searchable.searched.category.request_c = os_request.c;
      }

      // Prepare PCRE match text for each phrase and term
      let filetypes = [];
      for (let x = 0; x < os_sdata.terms.length; x++) {
        switch (os_sdata.terms[x][0]) {
          case 'filetype':
            os_sdata.formatted.push(os_sdata.terms[x][0] + ':' + os_sdata.terms[x][1]);
            if (os_rdata.s_filetypes[os_sdata.terms[x][1].toUpperCase()])
              for (let z = 0; z < os_rdata.s_filetypes[os_sdata.terms[x][1].toUpperCase()].length; z++)
                filetypes.push(os_rdata.s_filetypes[os_sdata.terms[x][1].toUpperCase()][z]);
            break;

          case 'exclude':
            os_sdata.formatted.push('-' + os_sdata.terms[x][1]);
            break;

          case 'phrase':
            os_sdata.formatted.push('"' + os_sdata.terms[x][1] + '"');

          case 'term':
            if (os_sdata.terms[x][0] == 'term')
              os_sdata.formatted.push(os_sdata.terms[x][1]);

            os_sdata.terms[x][2] = os_preg_quote(os_sdata.terms[x][1].toLowerCase(), '/');
            Object.keys(os_rdata.s_latin).forEach(key => {
              for (let y = 0; y < os_rdata.s_latin[key].length; y++)
                os_sdata.terms[x][2] = os_sdata.terms[x][2].replace(os_rdata.s_latin[key][y], key);
              if (key.length > 1) {
                os_sdata.terms[x][2] = os_sdata.terms[x][2].replace(key, '(' + key + '|' + os_rdata.s_latin[key].join('|') + ')');
              } else os_sdata.terms[x][2] = os_sdata.terms[x][2].replace(key, '[' + key + os_rdata.s_latin[key].join('') + ']');
            });

            os_sdata.terms[x][2] = new RegExp('(' + os_sdata.terms[x][2] + ')', 'igu');

        }
      }


      // ***** There is never any cache, so do an actual search
      for (let y = os_crawldata.length - 1; y >= 0; y--) {
        if (filetypes.length) {
          for (let x = 0, allowMime = false; x < filetypes.length; x++)
            if (os_crawldata[y].content_mime == filetypes[x]) allowMime = true;
          if (!allowMime) {
            os_crawldata.splice(y, 1);
            continue;
          }
        }

        for (let x = 0; x < os_sdata.terms.length; x++) {
          addRelevance = 0;

          if (os_sdata.terms[x][0] == 'filetype') {

          } else if (os_sdata.terms[x][0] == 'exclude') {

            if (os_crawldata[y].title.match(os_sdata.terms[x][2]) ||
                os_crawldata[y].description.match(os_sdata.terms[x][2]) ||
                os_crawldata[y].keywords.match(os_sdata.terms[x][2]) ||
                os_crawldata[y].weighted.match(os_sdata.terms[x][2]) ||
                os_crawldata[y].content.match(os_sdata.terms[x][2]))
              os_crawldata.splice(y, 1);

          } else if (os_sdata.terms[x][0] == 'phrase' ||
                     os_sdata.terms[x][0] == 'term') {

            if (os_sdata.terms[x][0] == 'phrase')
              os_crawldata[y].phrase++;

            if (os_crawldata[y].title.match(os_sdata.terms[x][2]))
              addRelevance += os_rdata.s_weights.title;

            if (os_crawldata[y].description.match(os_sdata.terms[x][2]))
              addRelevance += os_rdata.s_weights.description;

            if (os_crawldata[y].keywords.match(os_sdata.terms[x][2]))
              addRelevance += os_rdata.s_weights.keywords;

            if (os_crawldata[y].weighted.match(os_sdata.terms[x][2]))
              addRelevance += os_rdata.s_weights.css_value;

            if (os_crawldata[y].content.match(os_sdata.terms[x][2]))
              addRelevance += os_rdata.s_weights.body;

            if (addRelevance) {
              os_crawldata[y].multi++;
            } else if (os_sdata.terms[x][0] == 'phrase')
              os_crawldata.splice(y, 1);

          }
        }

        if (addRelevance) {
          os_crawldata[y].relevance += addRelevance;

          // Calculate multipliers
          os_crawldata[y].relevance *= Math.pow(os_rdata.s_weights.multi, os_crawldata[y].multi);
          os_crawldata[y].relevance *= Math.pow(os_rdata.s_weights.important, os_crawldata[y].phrase);

          os_crawldata[y].relevance *= os_crawldata[y].priority;
        }
      }

      // Sort the list by relevance value
      os_crawldata.sort(function(a, b) {
        if (a.relevance == b.relevance) return 0;
        return (b.relevance > a.relevance) ? 1 : -1;
      });

      // Normalize results from 0 - 100 and delete results with
      // relevance values < 5% of the top result
      for (let x = os_crawldata.length - 1; x >= 0; x--) {
        if (os_crawldata[0].relevance * 0.05 <= os_crawldata[x].relevance) {
          os_crawldata[x].relevance /= os_crawldata[0].relevance * 0.01;
        } else os_crawldata.splice(x, 1);
      }

      // The final results list is the top slice of this data
      // limited by the 's_limit_results' value
      os_sdata.results = os_crawldata.slice(0, os_odata.s_limit_results);


      // Now loop through the remaining results to generate the
      // proper match text for each
      for (let x = 0; x < os_sdata.results.length; x++) {

        // Add the page description to use as a default match text
        if (os_sdata.results[x].description.trim()) {
          os_sdata.results[x].matchtext.push({
            rank: 0,
            text: os_sdata.results[x].description.substring(0, os_odata.s_limit_matchtext)
          });
        }

        // Loop through each term to capture matchtexts
        for (let y = 0; y < os_sdata.terms.length; y++) {
          switch (os_sdata.terms[y][0]) {
            case 'filetype': break;
            case 'exclude': break;

            case 'phrase':
            case 'term':

              // Split the content on the current term
              let splitter = os_sdata.results[x].content.split(os_sdata.terms[y][2]);

              // For each match, gather the appropriate amount of match
              // text from either side of it
              for (let z = 0, caret = 0; z < splitter.length; z++) {
                caret += splitter[z].length;
                if (splitter[z].match(os_sdata.terms[y][2]) || splitter.length == 1) {
                  let offset = 0;
                  if (splitter.length == 1) {
                    // Grab some random content if there were no
                    // matches in the content
                    let offset = Math.floor(Math.random() * os_sdata.results[x].content.length - os_odata.s_limit_matchtext);
                  } else offset = Math.floor(Math.max(0, caret - (splitter[z].length + os_odata.s_limit_matchtext) / 2));
                  let match = os_sdata.results[x].content.substring(offset, offset + os_odata.s_limit_matchtext).trim();

                  // Add appropriate ellipses
                  if (offset + ((splitter[z].length + os_odata.s_limit_matchtext) / 2) < os_sdata.results[x].content.length)
                    match += "\u2026";

                  if (offset) match = "\u2026" + match;

                  os_sdata.results[x].matchtext.push({
                    rank: 0,
                    text: match
                  });
                }
              }

          }
        }

        // For each found match text, add a point for every time a
        // term is found in the match text; triple points for phrase
        // matches
        for (let y = 0; y < os_sdata.results[x].matchtext.length; y++) {
          for (let z = 0; z < os_sdata.terms.length; z++) {
            switch (os_sdata.terms[z][0]) {
              case 'filetype': break;
              case 'exclude': break;

              case 'phrase':
              case 'term':
                let points = os_sdata.results[x].matchtext[y].text.matchAll(os_sdata.terms[z][2]).length; // / (z + 1);
                if (os_sdata.terms[z][0] == 'phrase') points *= 3;
                os_sdata.results[x].matchtext[y].rank += points;

            }
          }
        }

        // Sort the match texts by score
        os_sdata.results[x].matchtext.sort(function(a, b) {
          if (b.rank == a.rank) return 0;
          return (b.rank > a.rank) ? 1 : -1;
        });

        // Use the top-ranked match text as the official match text
        os_sdata.results[x].matchtext = os_sdata.results[x].matchtext[0].text;

        // Unset result values we no longer need so they don't
        // bloat memory unnecessarily
        os_sdata.results[x].content = null;
        os_sdata.results[x].keywords = null;
        os_sdata.results[x].weighted = null;
        os_sdata.results[x].multi = null;
        os_sdata.results[x].phrase = null;
      }


      // Limit os_request.page to within boundaries
      os_request.page = parseInt(os_params.get('page'));
      if (isNaN(os_request.page)) os_request.page = 1;
      os_request.page = Math.max(1, os_request.page);
      os_sdata.pages = Math.ceil(os_sdata.results.length / os_odata.s_results_pagination);
      os_request.page = Math.min(os_sdata.pages, os_request.page);


      // Get a slice of the results that corresponds to the current
      // search results pagination page we are on
      let resultsPage = os_sdata.results.slice(
        (os_request.page - 1) * os_odata.s_results_pagination,
        (os_request.page - 1) * os_odata.s_results_pagination + os_odata.s_results_pagination
      );

      if (resultsPage.length) {
        os_TEMPLATE.searchable.searched.results = {};
        os_TEMPLATE.searchable.searched.results.result_list = [];

        // Do a last once-over of the results
        for (let x = 0, result; x < resultsPage.length; x++) {
          result = {};

          // Don't display filetype of HTML pages
          result.filetype = '';
          Object.keys(os_rdata.s_filetypes).forEach(type => {
            for (let y = 0; y < os_rdata.s_filetypes[type].length; y++)
              if (resultsPage[x].content_mime == os_rdata.s_filetypes[type][y])
                result.filetype = type;
          });

          // Don't display filetype of HTML pages
          if (!os_odata.s_show_filetype_html)
            if (result.filetype == 'HTML')
              result.filetype = '';

          if (result.filetype)
            result.filetype = '[' + result.filetype + ']';

          // Don't display category if there's only one
          if (Object.keys(os_rdata.s_category_list).length > 2) {
            result.category = resultsPage[x].category;
          } else resultsPage[x].category = '';

          // Format relevance
          result.relevance = Math.round(resultsPage[x].relevance * 100) / 100;

          // Highlight the terms in the title, url and matchtext
          result.title = resultsPage[x].title;
          result.url = resultsPage[x].url;
          result.matchtext = resultsPage[x].matchtext;
          result.description = resultsPage[x].description;
          result.title_highlight = resultsPage[x].title;
          result.url_highlight = resultsPage[x].url;
          result.matchtext_highlight = resultsPage[x].matchtext;
          result.description_highlight = resultsPage[x].description;

          for (let z = 0; z < os_sdata.terms.length; z++) {
            switch (os_sdata.terms[z][0]) {
              case 'filetype': break;
              case 'exclude': break;

              case 'phrase':
              case 'term':
                result.title_highlight = result.title_highlight.replace(os_sdata.terms[z][2], '<strong>$1</strong>');
                result.url_highlight = result.url_highlight.replace(os_sdata.terms[z][2], '<strong>$1</strong>');
                result.matchtext_highlight = result.matchtext_highlight.replace(os_sdata.terms[z][2], '<strong>$1</strong>');
                result.description_highlight = result.description_highlight.replace(os_sdata.terms[z][2], '<strong>$1</strong>');

            }
          }

          os_TEMPLATE.searchable.searched.results.result_list.push(result);
        }

        // If there are more than just one page of results, prepare all
        // the pagination variables for the template
        if (os_sdata.pages > 1) {
          let pagination = {};
          pagination.page_gt1 = (os_request.page > 1);
          pagination.page_minus1 = os_request.page - 1;
          pagination.page_list = [];
          for (x = 1; x <= os_sdata.pages; x++) {
            let page = {};
            page.index = x;
            page.current = (x == os_request.page);
            pagination.page_list.push(page);
          }
          pagination.page_ltpages = (os_request.page < os_sdata.pages);
          pagination.page_plus1 = os_request.page + 1;
          os_TEMPLATE.searchable.searched.results.pagination = pagination;
        }

        // Final numerical and stopwatch time values
        os_TEMPLATE.searchable.searched.results.from = Math.min(os_sdata.results.length, (os_request.page - 1) * os_odata.s_results_pagination + 1);
        os_TEMPLATE.searchable.searched.results.to = Math.min(os_sdata.results.length, os_request.page * os_odata.s_results_pagination);
        os_TEMPLATE.searchable.searched.results.of = os_sdata.results.length;
        // os_TEMPLATE.searchable.searched.results.in = Math.round(((new Date()).getTime() - os_sdata.time) / 10) / 100;

      } // No results

    } // No valid terms

  } // No request data

} // No searchable pages in search database

document.write(mustache.render(
  os_odata.s_result_template,
  os_TEMPLATE
));<?php


// Use this for dodgy character check on javascript output
// [^\w\s()\[\]{};:.‖‘’‟„…/@©~®§⇔⇕⇒⇨⇩↪&\\^<>›×™*·,±_²°|≥!#$¢£+≤=•«%½»?"'-]


            $_JS = ob_get_contents();
            ob_end_clean();

            header('Content-type: text/javascript; charset='.strtolower($_ODATA['s_charset']));
            header('Content-disposition: attachment; filename="offline-search.js"');
            mb_convert_encoding($_JS, 'UTF-8', $_ODATA['s_charset']);
            die($_JS);

          } else $_SESSION['error'][] = 'Error reading from the search result database: '.$err[2];
          break;


        // ***** Unknown 'os_submit' command
        default:
          header('Content-type: text/plain; charset='.strtolower($_ODATA['s_charset']));
          var_dump($_POST);
          exit();

      }

      header('Location: '.$_SERVER['REQUEST_URI']);
      exit();


    // Normal POST request, but without 'os_submit'
    // These are usually triggered by a javascript form.submit()
    } else {

      // Set new Page Index pagination value
      if (!empty($_POST['os_index_hidden_pagination'])) {
        $_POST['os_index_hidden_pagination'] = (int)$_POST['os_index_hidden_pagination'];
        if (in_array($_POST['os_index_hidden_pagination'], $_RDATA['admin_pagination_options'])) {
          OS_setValue('admin_index_pagination', $_POST['os_index_hidden_pagination']);
          $_SESSION['index_page'] = 1;
        }

        header('Location: '.$_SERVER['REQUEST_URI']);
        exit();
      }

      // Select a Page Index Category filter
      if (!empty($_POST['os_index_new_filter_category'])) {
        if (!empty($_RDATA['s_category_list'][$_POST['os_index_new_filter_category']])) {
          $_SESSION['index_filter_category'] = $_POST['os_index_new_filter_category'];
          $_SESSION['index_page'] = 1;
        }

        header('Location: '.$_SERVER['REQUEST_URI']);
        exit();
      }

      // Select a Page Index Status filter
      if (!empty($_POST['os_index_new_filter_status'])) {
        if (in_array($_POST['os_index_new_filter_status'], $_RDATA['index_status_list'])) {
          $_SESSION['index_filter_status'] = $_POST['os_index_new_filter_status'];
          $_SESSION['index_page'] = 1;
        }

        header('Location: '.$_SERVER['REQUEST_URI']);
        exit();
      }

      // Unknown POST command
      header('Content-type: text/plain; charset='.strtolower($_ODATA['s_charset']));
      var_dump($_POST);
      exit();
    }


  // Select a new Administration UI page
  } else if (!empty($_GET['page'])) {
    if (!empty($_RDATA['admin_pages'][$_GET['page']]))
      $_SESSION['admin_page'] = $_GET['page'];

  // Select a new page within the Page Index list
  } else if (isset($_GET['ipage'])) {
    $_GET['ipage'] = (int)$_GET['ipage'];
    $_SESSION['index_page'] = $_GET['ipage'];

  // User has requested to log out
  } else if (isset($_GET['logout'])) {
    $_SESSION = array();
    $_SESSION['message'][] = 'You have been logged out.';

    header('Location: '.$_SERVER['REQUEST_URI']);
    exit();
  }



  // Perform pre-processing SQL actions that may trigger
  // $_SESSION errors
  switch ($_SESSION['admin_page']) {
    case 'crawler':
      // Get list of domains from the starting URLs
      $_RDATA['sp_starting'] = array_filter(array_map('trim', explode("\n", $_ODATA['sp_starting'])));
      $_RDATA['s_starting_domains'] = array();
      foreach ($_RDATA['sp_starting'] as $starting) {
        $starting = parse_url($starting);
        if (!empty($starting['host']))
          $_RDATA['s_starting_domains'][] = $starting['host'];
      }
      $_RDATA['s_starting_domains'] = array_unique($_RDATA['s_starting_domains']);
      if (count($_RDATA['s_starting_domains']) == 1)
        OS_setValue('sp_sitemap_hostname', $_RDATA['s_starting_domains'][0]);
      break;

    case 'index':
      $_RDATA['page_index_rows'] = false;
      $_RDATA['page_index_found_rows'] = false;

      if ($_RDATA['s_crawldata_info']['Rows']) {

        // ***** Select rows to populate the Page Index table
        $indexRows = $_DDATA['pdo']->prepare(
          'SELECT SQL_CALC_FOUND_ROWS
              `url`, `url_base`, `title`, `category`, `content_checksum`, `status`,
              `status_noindex`, `flag_unlisted`, `flag_updated`, `priority`
            FROM `'.$_DDATA['tbprefix'].'crawldata`
              WHERE (:text1=\'\' OR `url` LIKE :text2) AND
                    (:category1=\'\' OR `category`=:category2) AND
                    (:status1=\'\' OR `status`=:status2) AND
                    (:flag_unlisted1=\'any\' OR `flag_unlisted`=:flag_unlisted2) AND
                    (:flag_updated1=\'any\' OR `flag_updated`=:flag_updated2)
                ORDER BY `url_sort`
                  LIMIT :offset, :pagination;'
        );

        $text = ($_SESSION['index_filter_text']) ? trim($_SESSION['index_filter_text']) : '';

        $category = ($_SESSION['index_filter_category'] != '<none>') ? $_SESSION['index_filter_category'] : '';

        if ($_SESSION['index_filter_status'] == 'OK' ||
            $_SESSION['index_filter_status'] == 'Orphan') {
          $status = $_SESSION['index_filter_status'];
        } else $status = '';

        $unlisted = ($_SESSION['index_filter_status'] == 'Unlisted') ? 1 : 'any';
        $updated = ($_SESSION['index_filter_status'] == 'Updated') ? 1 : 'any';

        $_RDATA['page_index_offset'] = ($_SESSION['index_page'] - 1) * $_ODATA['admin_index_pagination'];

        $indexRows->execute(array(
          'text1' => '%'.$text.'%',
          'text2' => '%'.$text.'%',
          'text1' => '%'.$text.'%',
          'text2' => '%'.$text.'%',
          'category1' => $category,
          'category2' => $category,
          'status1' => $status,
          'status2' => $status,
          'flag_unlisted1' => $unlisted,
          'flag_unlisted2' => $unlisted,
          'flag_updated1' => $updated,
          'flag_updated2' => $updated,
          'offset' => $_RDATA['page_index_offset'],
          'pagination' => $_ODATA['admin_index_pagination']
        ));
        $err = $indexRows->errorInfo();
        if ($err[0] == '00000') {
          $_RDATA['page_index_rows'] = $indexRows->fetchAll();

          $foundRows = $_DDATA['pdo']->query('SELECT FOUND_ROWS();');
          $err = $foundRows->errorInfo();
          if ($err[0] == '00000') {
            $foundRows = $foundRows->fetchAll(PDO::FETCH_NUM);
            if (count($foundRows)) {
              $_RDATA['page_index_found_rows'] = $foundRows[0][0];

              $_RDATA['index_pages'] = ceil($_RDATA['page_index_found_rows'] / $_ODATA['admin_index_pagination']);

              // If the requested page is outside page limit
              if ($_SESSION['index_page'] != 1 && ($_SESSION['index_page'] > $_RDATA['index_pages'] || $_SESSION['index_page'] < 1)) {
                $_SESSION['index_page'] = max(1, min($_RDATA['index_pages'], (int)$_SESSION['index_page']));

                // Redirect to a page within the limits
                header('Location: '.$_SERVER['REQUEST_URI'].'?ipage='.$_SESSION['index_page']);
                exit();
              }

            } else $_SESSION['error'][] = 'Database did not return a search table row count.';
          } else $_SESSION['error'][] = 'Database error reading search table row count: '.$err[2];
        } else $_SESSION['error'][] = 'Database error reading search table: '.$err[2];
      } else $_SESSION['message'][] = 'The search database is currently empty.';
      break;

    case 'search':
      // Average hits per hour: First find the oldest `stamp` in the
      // database, then base all averages on the difference between that
      // time and now; also get average number of results
      $_RDATA['s_hours_since_oldest_hit'] = 0;
      $_RDATA['s_hits_per_hour'] = 0;
      $_RDATA['q_average_results'] = 0;
      $hits = $_DDATA['pdo']->query(
        'SELECT MIN(`stamp`) AS `oldest`, COUNT(*) AS `hits`, AVG(`results`) AS `average`
          FROM `'.$_DDATA['tbprefix'].'query`;'
      );
      $err = $hits->errorInfo();
      if ($err[0] == '00000') {
        $hits = $hits->fetchAll();
        if (count($hits) && !is_null($hits[0]['oldest']) && !is_null($hits[0]['hits'])) {
          $_RDATA['s_hours_since_oldest_hit'] = (time() - $hits[0]['oldest']) / 3600;
          $_RDATA['s_hits_per_hour'] = $hits[0]['hits'] / $_RDATA['s_hours_since_oldest_hit'];
          $_RDATA['q_average_results'] = $hits[0]['average'];
        }
      } else $_SESSION['error'][] = 'Could not read hit counts from query log.';

      // Median number of results
      $_RDATA['q_median_results'] = 0;
      $median = $_DDATA['pdo']->query(
        'SELECT `results` FROM `'.$_DDATA['tbprefix'].'query` ORDER BY `results`;'
      );
      $err = $median->errorInfo();
      if ($err[0] == '00000') {
        $median = $median->fetchAll();
        if (count($median)) {
          $index = floor(count($median) / 2);
          if (count($median) & 1) {
            $_RDATA['q_median_results'] = $median[$index]['results'];
          } else {
            $_RDATA['q_median_results'] = ($median[$index - 1]['results'] + $median[$index]['results']) / 2;
          }
        }
      } else $_SESSION['error'][] = 'Could not read result counts from query log.';
      break;

    case 'queries':
      $_RDATA['query_log_rows'] = false;
      $queries = $_DDATA['pdo']->query(
        'SELECT *, INET_NTOA(`ip`) AS `ipaddr`
           FROM `'.$_DDATA['tbprefix'].'query` AS `t`
             INNER JOIN (
               SELECT `query`, COUNT(`query`) AS `hits`,
                      REGEXP_REPLACE(`query`, \'^[[:punct:]]+\', \'\') AS `alpha`,
                      MAX(`stamp`) AS `last_hit`, AVG(`results`) AS `avg_results`
                 FROM `'.$_DDATA['tbprefix'].'query`
                   GROUP BY `query`
             ) AS `s` ON `s`.`query`=`t`.`query` AND `s`.`last_hit`=`t`.`stamp`
             ORDER BY `s`.`alpha` ASC;'
      );
      $err = $queries->errorInfo();
      if ($err[0] == '00000') {
        $_RDATA['query_log_rows'] = $queries->fetchAll();

        if (count($_RDATA['query_log_rows'])) {
          $x = 0;

          // Add the `alpha` sort order as an index
          foreach ($_RDATA['query_log_rows'] as $key => $query)
            $_RDATA['query_log_rows'][$key]['rownum'] = $x++;

          // On first load, sort list by # of hits
          usort($_RDATA['query_log_rows'], function($a, $b) {
            return $b['hits'] - $a['hits'];
          });

        } else $_SESSION['message'][] = 'The query log is currently empty.';
      } else $_SESSION['error'][] = 'Database error reading query log table: '.$err[2];

  }
} // Not logged in


?><!DOCTYPE html>
<html>
<head>
  <meta charset="<?php echo htmlspecialchars($_ODATA['s_charset']); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/admin.css">

  <title>Orcinus Site Search <?php echo $_ODATA['version']; ?> - Administration</title>
</head>
<body class="pt-5">
  <nav class="navbar fixed-top navbar-expand-md bg-body-secondary">
    <div class="container-fluid">
      <span class="navbar-brand flex-grow-1 flex-md-grow-0 mb-1">Orcinus</span><?php
      if ($_SESSION['admin_username']) { ?> 
        <div class="flex-grow-0 order-md-last">
          <button type="button" class="btn btn-primary" id="os_crawl_navbar" data-bs-toggle="modal" data-bs-target="#crawlerModal" data-bs-crawl="run"<?php
            if (!file_exists('./crawler.php')) echo ' disabled="disabled"'; ?>><?php
            echo ($_ODATA['sp_crawling']) ? 'Crawling...' : 'Crawler';
          ?></button>
          <a href="?logout" class="d-none d-md-inline-block ps-2" title="Log Out">
            <img src="img/logout.svg" alt="Log Out" class="align-middle svg-icon">
          </a>
        </div>
        <button class="navbar-toggler ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
          <ul class="navbar-nav me-auto mb-2 mb-md-0"><?php
            foreach ($_RDATA['admin_pages'] as $page => $name) { ?> 
              <li class="nav-item"><?php
                if ($page == $_SESSION['admin_page']) { ?> 
                  <a class="nav-link active" aria-current="page" href="?page=<?php echo $page; ?>"><?php echo $name; ?></a><?php
                } else { ?> 
                  <a class="nav-link" href="?page=<?php echo $page; ?>"><?php echo $name; ?></a><?php
                } ?> 
              </li><?php
            } ?> 
            <li class="nav-item d-md-none">
              <a class="nav-link" href="?logout">Log Out</a>
            </li>
          </ul>
        </div><?php
      } ?> 
    </div>
  </nav>

  <h1 class="d-none bg-black text-white p-2">
    Orcinus Site Search <?php echo $_ODATA['version']; ?>
  </h1>

  <div class="container-fluid pt-4 pb-3">

    <div class="row justify-content-center"><?php
      while ($error = array_shift($_SESSION['error'])) { ?> 
        <div class="col-10 col-sm-8 col-md-7">
          <div class="alert alert-danger alert-dismissible fade show mx-auto" role="alert"><?php
            echo htmlspecialchars($error); ?> 
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        </div><?php
      }
      while ($message = array_shift($_SESSION['message'])) { ?> 
        <div class="col-10 col-sm-8 col-md-7">
          <div class="alert alert-info alert-dismissible fade show mx-auto" role="alert"><?php
            echo htmlspecialchars($message); ?> 
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        </div><?php
      } ?> 
    </div><?php


    /* ***** Logged in - Show administration UI ******************** */
    if ($_SESSION['admin_username']) {
      switch ($_SESSION['admin_page']) {


        /* ************************************************************
         * Crawler Management ************************************** */
        case 'crawler': ?> 
          <section class="row justify-content-center">
            <header class="col-sm-10 col-md-8 col-lg-12 col-xl-10 col-xxl-8 mb-2">
              <h2>Crawler Management</h2>
            </header>

            <div class="col-sm-10 col-md-8 col-lg-5 col-xl-4 col-xxl-3 order-lg-2">
              <div class="shadow rounded-3 mb-3 overflow-visible">
                <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Crawl Information</h3>
                <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                  <ul class="list-group"><?php
                    if ($_ODATA['sp_time_end']) { ?> 
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Most Recent Crawl</strong>
                          <span class="flex-grow-1 text-end">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#crawlerModal" data-bs-crawl="log">
                              View Log
                            </button>
                          </span>
                        </label>
                        <div><?php
                          OS_countUp(($_ODATA['sp_time_end']) ? $_ODATA['sp_time_end'] : time(), 'os_countup_time_end');
                        ?></div><?php
                        if ($_ODATA['sp_time_end'] != $_ODATA['sp_time_end_success']) { ?> 
                          <p class="data-text text-danger">
                            <strong>Warning:</strong> The previous crawl did not complete successfully.
                            Please check the crawl log for more details.
                          </p><?php
                        } ?> 
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Crawl Time</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_time_last"><?php
                            echo $_ODATA['sp_time_last'];
                          ?> <abbr title="seconds">s</abbr></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Data Transferred</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_data_transferred"><?php
                            echo OS_readSize($_ODATA['sp_data_transferred'], true);
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Data Stored</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_data_stored"><?php
                            if ($_ODATA['sp_data_transferred']) { ?> 
                              <small data-bs-toggle="tooltip" data-bs-placement="bottom" title="Efficiency percentage of data stored vs. data downloaded"><?php
                                echo '('.round(($_ODATA['sp_data_stored'] / $_ODATA['sp_data_transferred']) * 100, 1).'%)';
                              ?></small> <?php
                            }
                            echo OS_readSize($_ODATA['sp_data_stored'], true);
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Links Crawled</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_links_crawled"><?php
                            echo $_ODATA['sp_links_crawled'];
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Pages Stored</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_pages_stored"><?php
                            if ($_ODATA['sp_links_crawled']) { ?> 
                              <small data-bs-toggle="tooltip" data-bs-placement="bottom" title="Efficiency percentage of pages stored vs. links crawled"><?php
                                echo '('.round(($_ODATA['sp_pages_stored'] / $_ODATA['sp_links_crawled']) * 100, 1).'%)';
                              ?></small> <?php
                            }
                            echo $_ODATA['sp_pages_stored'];
                          ?></var>
                        </label>
                      </li><?php
                    } else { ?> 
                      <li class="list-group-item">
                        <p class="mb-0">
                          Crawler has not yet been run. Choose your settings and run your first crawl by
                          using the button in the top menu bar.
                        </p>
                      </li><?php
                    } ?> 
                  </ul>
                </div>
              </div>

              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Crawl Administration</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <h4>Crawl Scheduling</h4>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Crawl Again After:</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_sp_interval" value="<?php echo $_ODATA['sp_interval']; ?>" min="0" max="255" step="1" class="form-control d-inline-block me-1"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="To disable automatic crawling, set this value to zero (0)."> <abbr title="hours">h</abbr>
                          </span>
                        </label>
                        <label class="d-flex flex-column lh-lg w-100 mb-2">
                          <strong class="pe-2">Only Crawl Between:</strong>
                          <span class="flex-grow-1 text-center text-nowrap">
                            <input type="time" name="os_sp_interval_start" value="<?php echo $_ODATA['sp_interval_start']; ?>"
                              class="form-control d-inline-block align-middle w-auto" aria-labelledby="os_sp_interval_time_text">
                            and
                            <input type="time" name="os_sp_interval_stop" value="<?php echo $_ODATA['sp_interval_stop']; ?>"
                              class="form-control d-inline-block align-middle w-auto" aria-labelledby="os_sp_interval_time_text">
                          </span>
                        </label>
                        <p id="os_sp_interval_time_text" class="form-text">
                          Automatic crawls are triggered by people visiting your search page. To allow
                          crawls at any time, set these both to the same time.
                        </p>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Timezone:</strong>
                          <span class="flex-grow-1 text-end">
                            <select name="os_sp_timezone" class="form-select d-inline-block"><?php
                              $tzList = timezone_identifiers_list();
                              foreach ($tzList as $tz) { ?> 
                                <option value="<?php echo $tz; ?>"<?php
                                  if ($_ODATA['sp_timezone'] == $tz) echo ' selected="selected"';
                                ?>><?php echo $tz; ?></option><?php
                              } ?> 
                            </select>
                          </span>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <h4>Send Email on...</h4>
                        <div class="row">
                          <div class="col">
                            <div class="form-check">
                              <input type="checkbox" name="os_sp_email_success" id="os_sp_email_success" value="1" class="form-check-input"<?php
                                if ($_ODATA['sp_email_success']) echo ' checked="checked"'; ?>>
                              <label for="os_sp_email_success" class="form-check-label">Crawl Success</label>
                            </div>
                          </div>
                          <div class="col">
                            <div class="form-check">
                              <input type="checkbox" name="os_sp_email_failure" id="os_sp_email_failure" value="1" class="form-check-input"<?php
                                if ($_ODATA['sp_email_failure']) echo ' checked="checked"'; ?>>
                              <label for="os_sp_email_failure" class="form-check-label">Crawl Failure</label>
                            </div>
                          </div>
                        </div>
                        <label class="d-flex flex-column lh-lg w-100 mb-2">
                          <strong class="text-nowrap pe-2">To Email Addresses:</strong>
                          <textarea rows="3" cols="30" name="os_admin_email" wrap="off" class="form-control" aria-labelledby="os_admin_email_text"<?php
                            if ($_MAIL) { ?>
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="One entry per line. Can be in the format 'me@example.com' or 'Name <me@example.com>'."<?php
                            } else echo ' disabled="disabled"' ;?>><?php echo htmlspecialchars($_ODATA['admin_email']);
                          ?></textarea>
                        </label><?php
                        if (!$_MAIL) { ?> 
                          <p id="os_admin_email_text" class="form-text text-danger">
                            <strong>Warning:</strong> PHPMailer could not be found or loaded. The
                            application will not be able to send mail until it is installed correctly.
                          </p><?php
                        } ?> 
                      </li>
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_admin_config" class="btn btn-primary">Save Changes</button>
                    </div>
                  </div>
                </fieldset>
              </form>

              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Sitemap Settings</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <label class="d-sm-flex lh-lg w-100">
                          <strong class="pe-2">Sitemap File:</strong>
                          <div class="flex-grow-1 text-end text-nowrap">
                            <input type="text" name="os_sp_sitemap_file" value="<?php echo htmlspecialchars($_ODATA['sp_sitemap_file']); ?>" pattern=".*\.xml(\.gz)?" class="form-control d-inline-block w-auto" aria-labelledby="os_sp_sitemap_file_text"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="To disable storing a sitemap, just leave this field blank.">
                          </div>
                        </label><?php
                        if ($_RDATA['sp_sitemap_file'] == 'does not exist') { ?> 
                          <p id="os_sp_sitemap_file_text" class="form-text text-danger mb-0">
                            <strong>Warning:</strong> Target sitemap file doesn't exist. Please create it.
                          </p><?php
                        } else if ($_RDATA['sp_sitemap_file'] == 'not writable') { ?> 
                          <p id="os_sp_sitemap_file_text" class="form-text text-danger mb-0">
                            <strong>Warning:</strong> Target sitemap file is not writable. Please adjust permissions.
                          </p><?php
                        } ?> 
                      </li><?php
                      if (count($_RDATA['s_starting_domains']) > 1) { ?> 
                        <li class="list-group-item">
                          <label class="d-flex lh-lg w-100">
                            <strong class="pe-2">Domain:</strong>
                            <span class="flex-grow-1 text-end">
                              <select name="os_sp_sitemap_hostname" class="form-select d-inline-block"><?php
                                foreach ($_RDATA['s_starting_domains'] as $domain) { ?> 
                                  <option value="<?php echo $domain; ?>"<?php
                                    if ($_ODATA['sp_sitemap_hostname'] == $domain) echo ' selected="selected"'; ?>><?php
                                    echo $domain;
                                  ?></option><?php
                                } ?> 
                              </select>
                            </span>
                          </label>
                        </li><?php
                      } ?> 
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_sp_sitemap_config" class="btn btn-primary">Save Changes</button>
                    </div>
                  </div>
                </fieldset>
              </form>
            </div>

            <div class="col-sm-10 col-md-8 col-lg-7 col-xl-6 col-xxl-5 order-lg-1">
              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Crawl Settings</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <label class="d-md-flex lh-lg w-100">
                          <strong class="text-nowrap pe-2">Starting URLs:</strong>
                          <textarea rows="3" cols="30" name="os_sp_starting" wrap="off" class="form-control" required="required"
                            data-bs-toggle="tooltip" data-bs-placement="bottom" title="List of URLs for the crawler to start with. One URL per line."><?php
                            echo htmlspecialchars($_ODATA['sp_starting']);
                          ?></textarea>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-md-flex lh-lg w-100">
                          <strong class="text-nowrap pe-2">User Agent:</strong>
                          <span class="flex-grow-1 text-end">
                            <input type="text" name="os_sp_useragent" value="<?php echo $_ODATA['sp_useragent']; ?>" class="form-control"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="The crawler will send this User Agent string when fetching files.">
                          </span>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <div class="row">
                          <div class="col-3">
                            <strong>Options:</strong>
                          </div>
                          <div class="col-9">
                            <ul class="list-unstyled">
                              <li class="form-check mb-1">
                                <input type="checkbox" name="os_sp_cookies" id="os_sp_cookies" value="1" class="form-check-input"<?php
                                  if ($_ODATA['sp_cookies']) echo ' checked="checked"'; ?>>
                                <label for="os_sp_cookies" class="form-check-label">Accept Cookies</label>
                              </li>
                              <li class="form-check mb-1">
                                <input type="checkbox" name="os_sp_ifmodifiedsince" id="os_sp_ifmodifiedsince" value="1" class="form-check-input"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="The If-Modified-Since header allows a server to send an empty response if a file hasn't been updated, increasing crawl speed and saving bandwidth."<?php
                                  if ($_ODATA['sp_ifmodifiedsince']) echo ' checked="checked"'; ?>>
                                <label for="os_sp_ifmodifiedsince" class="form-check-label">Send <em>If-Modified-Since</em> Header</label>
                              </li>
                              <li class="form-check mb-1">
                                <input type="checkbox" name="os_sp_autodelete" id="os_sp_autodelete" value="1" class="form-check-input"<?php
                                  if ($_ODATA['sp_autodelete']) echo ' checked="checked"'; ?>>
                                <label for="os_sp_autodelete" class="form-check-label">Auto-delete Orphans</label>
                              </li>
                            </ul>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item">
                        <h4>Timeouts &amp; Delay</h4>
                        <div class="row">
                          <div class="col-md-6">
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">URL Timeout:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_timeout_url" value="<?php echo $_ODATA['sp_timeout_url']; ?>" min="0" max="255" step="1" class="form-control d-inline-block me-1"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Maximum time to wait for a response from a single URL."> <abbr title="seconds">s</abbr>
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Crawl Timeout:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_timeout_crawl" value="<?php echo $_ODATA['sp_timeout_crawl']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block me-1"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Maximum total execution time for an entire crawl."> <abbr title="seconds">s</abbr>
                              </span>
                            </label>
                          </div>
                          <div class="col-md-6">
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Request Delay:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_sleep" value="<?php echo $_ODATA['sp_sleep']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block me-1"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Wait this much time between making requests; may help keep from overloading a slow server. Be sure to adjust the Crawl Timeout upwards to account for this."> <abbr title="milliseconds">ms</abbr>
                              </span>
                            </label>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item">
                        <h4>Maximum Limits</h4>
                        <div class="row">
                          <div class="col-md-6">
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Crawled Links:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_limit_crawl" value="<?php echo $_ODATA['sp_limit_crawl']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Maximum number of links to request and crawl. After reaching this limit, the crawler will quit.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Stored Pages:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_limit_store" value="<?php echo $_ODATA['sp_limit_store']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Maximum number of pages to store in the search database. After reaching this limit, the crawler will quit.">
                              </span>
                            </label>
                          </div>
                          <div class="col-md-6">
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Link Depth:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_limit_depth" value="<?php echo $_ODATA['sp_limit_depth']; ?>" min="0" max="255" step="1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Maximum number of links to crawl away from your starting URLs.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">File Size:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_limit_filesize" value="<?php echo $_ODATA['sp_limit_filesize']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block me-1"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Files larger than this size will not be downloaded, stored or scanned for links."> <abbr title="kibibytes">kiB</abbr>
                              </span>
                            </label>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item">
                        <h4>Link Filters</h4>
                        <div class="row">
                          <label class="col-md-6 d-flex flex-column lh-lg mb-2">
                            <strong class="flex-grow-1">Require URL Match:</strong>
                            <textarea rows="5" cols="30" name="os_sp_require_url" wrap="off" class="form-control"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Links MUST contain at least one of these matches in order to be crawled. One entry per line. Lines beginning with an asterisk (*) are treated as simple regular expressions."><?php
                              echo htmlspecialchars($_ODATA['sp_require_url']);
                            ?></textarea>
                          </label>
                          <label class="col-md-6 d-flex flex-column lh-lg mb-2">
                            <strong class="flex-grow-1">Ignore URL Match:</strong>
                            <textarea rows="5" cols="30" name="os_sp_ignore_url" wrap="off" class="form-control"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Links containing at least one of these matches will be discarded before crawling. One entry per line. Lines beginning with an asterisk (*) are treated as simple regular expressions."><?php
                              echo htmlspecialchars($_ODATA['sp_ignore_url']);
                            ?></textarea>
                          </label>
                        </div>
                        <label class="lh-lg w-100 mb-2">
                          <strong>Ignore File Extensions:</strong>
                          <textarea rows="4" cols="60" name="os_sp_ignore_ext" class="form-control"
                            data-bs-toggle="tooltip" data-bs-placement="bottom" title="Links ending with any of these file extensions will be discarded before crawling. Add extensions separated by spaces."><?php
                            echo htmlspecialchars($_ODATA['sp_ignore_ext']);
                          ?></textarea>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <h4 aria-labelledby="os_sp_category_text">Categories</h4>
                        <p id="os_sp_category_text" class="form-text">
                          Usually you'll want all your indexed pages in just one category. In some cases
                          however, you may want to offer users an additional way to restrict results by
                          putting groups of pages into multiple categories. You can set page categories
                          from the <em>Page Index</em>.
                        </p>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Default Category:</strong>
                          <span class="flex-grow-1 text-end">
                            <input type="text" name="os_sp_category_default" value="<?php echo $_ODATA['sp_category_default']; ?>" maxlength="30" class="form-control d-inline-block w-auto mw-10em"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Default Category value to apply to newly found pages.">
                          </span>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <h4>Content Filters</h4>
                        <div class="row">
                          <label class="col-md-6 d-flex flex-column lh-lg mb-2">
                            <strong class="flex-grow-1">Ignore HTML Content by CSS Selector:</strong>
                            <textarea rows="5" cols="30" name="os_sp_ignore_css" class="form-control pb-4"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Text content contained in elements matching these CSS selectors will not be stored. Simple CSS selectors only (#id, .class or element). Separated by spaces. Links from these elements will still be scanned."><?php
                              echo htmlspecialchars($_ODATA['sp_ignore_css']);
                            ?></textarea>
                          </label>
                          <label class="col-md-6 d-flex flex-column lh-lg mb-2">
                            <strong class="flex-grow-1">Remove Text from Titles:</strong>
                            <textarea rows="5" cols="30" name="os_sp_title_strip" wrap="off" class="form-control"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Strings of text to remove from page titles. For example, if you add ' - My Brand' to the end of all your page titles, you can remove it here so it needn't appear in search results. One entry per line. Lines beginning with an asterisk (*) are treated as simple regular expressions."><?php
                              echo htmlspecialchars($_ODATA['sp_title_strip']);
                            ?></textarea>
                          </label>
                        </div>
                      </li>
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_sp_crawl_config" class="btn btn-primary">Save Changes</button>
                    </div>
                  </div>
                </fieldset>
              </form>
            </div>
          </section><?php
          break;


        /* ************************************************************
         * Page Index ********************************************** */
        case 'index': ?> 
          <section class="row justify-content-center">
            <header class="col-xl-10 col-xxl-8 mb-2">
              <h2>Page Index</h2>
            </header><?php

            // If there are *any* rows in the database
            if ($_RDATA['s_crawldata_info']['Rows']) {

              // If we successfully queried the search table for results
              if ($_RDATA['page_index_found_rows'] !== false && is_array($_RDATA['page_index_rows'])) { ?> 
                <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post" class="col-xl-10 col-xxl-8">
                  <fieldset><?php
                    ob_start();
                    if ($_RDATA['page_index_found_rows'] > $_ODATA['admin_index_pagination']) { ?> 
                      <nav aria-label="Index table page navigation" class="w-100">
                        <ul class="pagination justify-content-center mb-2"><?php
                          if ($_SESSION['index_page'] > 1) { ?> 
                            <li class="page-item"><a class="page-link" href="?ipage=<?php echo $_SESSION['index_page'] - 1; ?>">Previous</a></li><?php
                          } else { ?> 
                            <li class="page-item disabled"><span class="page-link">Previous</span></li><?php
                          }
                          if ($_RDATA['index_pages'] < 6) {
                            for ($x = 1; $x <= $_RDATA['index_pages']; $x++) {
                              if ($x != $_SESSION['index_page']) { ?>
                                <li class="page-item"><a class="page-link" href="?ipage=<?php echo $x; ?>"><?php echo $x; ?></a></li><?php
                              } else { ?> 
                                <li class="page-item disabled"><span class="page-link"><strong><?php echo $x; ?></strong></span></li><?php
                              }
                            }
                          } else { ?> 
                            <li class="page-item d-table">
                              <label class="d-table-cell align-middle h-100 text-nowrap ps-3 pe-3 h-100 border border-1">
                                <span class="align-middle">Jump to page:</span>
                                <select name="os_index_pagination_page_select" class="form-select form-select-sm d-inline-block w-auto"><?php
                                  for ($x = 1; $x <= $_RDATA['index_pages']; $x++) { ?> 
                                    <option value="<?php echo $x; ?>"<?php 
                                      if ($x == $_SESSION['index_page']) echo ' selected="selected"';
                                      ?>><?php echo $x; ?></option><?php
                                  }
                                ?></select>
                              </label>
                            </li><?php
                          }
                          if ($_SESSION['index_page'] < $_RDATA['index_pages']) { ?> 
                            <li class="page-item"><a class="page-link" href="?ipage=<?php echo $_SESSION['index_page'] + 1; ?>">Next</a></li><?php
                          } else { ?> 
                            <li class="page-item disabled"><span class="page-link">Next</span></li><?php
                          } ?> 
                        </ul>
                      </nav><?php
                    }
                    $_RDATA['index_pagination_block'] = ob_get_contents();
                    ob_end_flush(); ?> 

                    <input type="hidden" name="os_index_hidden_pagination" value="">
                    <input type="hidden" name="os_apply_new_category" value="">
                    <input type="hidden" name="os_apply_new_priority" value="">
                    <input type="hidden" name="os_index_new_filter_category" value="">
                    <input type="hidden" name="os_index_new_filter_status" value="">
                    <div class="rounded-3 border border-1 border-secondary-subtle shadow border-bottom-0 mb-3 overflow-hidden">
                      <table class="table table-striped w-100 mb-0" id="os_index_table">
                        <thead>
                          <tr class="bg-black text-white">
                            <th colspan="6">
                              <div class="row">
                                <div class="col-md-6 d-flex mb-2 mb-md-0">
                                  <h3 class="mb-0 pe-2">Filters:</h3>
                                  <label class="input-group pe-1">
                                    <input type="text" name="os_index_filter_text" value="<?php
                                      echo htmlspecialchars($_SESSION['index_filter_text']);
                                    ?>" placeholder="URL text match" class="form-control z-1"><button
                                      type="button" name="os_index_filter_text_clear" title="Clear"
                                      class="btn btn-light ps-1 pe-1 z-2">&#x2A2F;</button>
                                  </label>
                                  <button type="submit" name="os_submit" value="os_index_filter_text" class="btn btn-primary">Go</button>
                                </div>
                                <div class="col-md-6 text-center text-md-end"><?php
                                  if (count($_RDATA['s_category_list']) > 2) { ?> 
                                    <label>
                                      <select name="os_index_filter_by_category" title="Filter by Category" class="form-select"><?php
                                        foreach ($_RDATA['s_category_list'] as $category => $count) { ?> 
                                          <option value="<?php echo htmlspecialchars($category); ?>"<?php
                                            if ($_SESSION['index_filter_category'] == $category) echo ' selected="selected"'; ?>><?php
                                            if ($category == '<none>') $category = 'All Categories';
                                            echo htmlspecialchars($category); ?></option><?php
                                        } ?> 
                                      </select>
                                    </label><?php
                                  } ?> 
                                  <label>
                                    <select name="os_index_filter_by_status" title="Filter by Status" class="form-select"><?php
                                      foreach ($_RDATA['index_status_list'] as $status) { ?> 
                                        <option value="<?php echo htmlspecialchars($status); ?>"<?php
                                          if ($_SESSION['index_filter_status'] == $status) echo ' selected="selected"';
                                          ?>><?php echo htmlspecialchars(($status == '<none>') ? 'Any status' : $status); ?></option><?php
                                      } ?> 
                                    </select>
                                  </label>
                                </div>
                              </div>
                            </th>
                          </tr>
                          <tr>
                            <th class="pe-0"></th>
                            <th class="fs-5" scope="col">URL</th>
                            <td class="text-center">
                              <span class="d-none d-sm-inline">Showing pages </span><?php
                              echo min($_RDATA['page_index_offset'] + 1, $_RDATA['page_index_found_rows']);
                              ?> &ndash; <?php
                              echo $_RDATA['page_index_offset'] + count($_RDATA['page_index_rows']);
                              ?> of <?php
                              echo $_RDATA['page_index_found_rows']; ?> 
                            </td><?php
                            if (count($_RDATA['s_category_list']) > 2) { ?> 
                              <th scope="col" class="d-none d-md-table-cell fs-5">Category</th><?php
                            } ?> 
                            <th scope="col" class="fs-5">
                              <span class="d-none d-sm-inline">Status</span>
                            </th>
                            <th scope="col" class="d-none d-md-table-cell fs-5"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Increasing the priority value makes a page more likely to appear at the top of search results, while decreasing it does the opposite. Ranges from 0.0 &ndash; 1.0; default: 0.5">
                              <span>Priority</span>
                            </th>
                          </tr>
                          <tr><?php
                            ob_start(); ?> 
                            <td class="align-middle pe-0">
                              <input type="checkbox" name="os_index_check_all" title="Select / unselect all" class="form-check-input">
                            </td>
                            <td colspan="2" class="text-nowrap">
                              <select name="os_index_select_action" class="form-select d-inline-block w-auto align-middle">
                                <option value="" selected="selected">With selected...</option>
                                <option value="delete">Delete</option>
                                <option value="unlisted">Toggle Unlisted</option>
                                <option value="category">Set Category</option>
                                <option value="priority">Set Priority</option>
                              </select>
                              <button type="submit" name="os_submit" value="os_index_with_selected" class="btn btn-primary">Go</button>
                            </td>
                            <td colspan="3" class="text-center text-nowrap">
                              <span class="d-none d-md-inline">Per page:</span>
                              <select name="os_index_select_pagination" title="Show listings per page" class="form-select d-inline-block w-auto align-middle"><?php
                                foreach ($_RDATA['admin_pagination_options'] as $opt) { ?> 
                                  <option value="<?php echo $opt; ?>"<?php
                                    if ($_ODATA['admin_index_pagination'] == $opt) echo ' selected="selected"';
                                    ?>><?php echo $opt; ?></option><?php
                                } ?> 
                              </select>
                            </td><?php
                            $_RDATA['index_action_row'] = ob_get_contents();
                            ob_end_flush(); ?> 
                          </tr>
                        </thead>
                        <tfoot class="table-group-divider">
                          <tr><?php echo $_RDATA['index_action_row']; ?></tr>
                        </tfoot>
                        <tbody class="table-group-divider"><?php
                          if (count($_RDATA['s_crawldata_domains']) == 1)
                            $repStr = '/^'.preg_quote(key($_RDATA['s_crawldata_domains']), '/').'/';

                          foreach ($_RDATA['page_index_rows'] as $key => $row) { ?> 
                            <tr class="lh-sm">
                              <td class="align-middle pe-0">
                                <input type="checkbox" data-index="<?php echo $key; ?>" name="os_index_pages[]" value="<?php echo base64_encode($row['content_checksum']); ?>" class="form-check-input mt-1">
                              </td>
                              <td colspan="2" class="text-nowrap">
                                <div class="d-inline-block align-middle mw-90">
                                  <div class="w-100 d-table table-fixed">
                                    <div class="w-100 d-table-cell overflow-hidden text-ellipsis">
                                      <a href="<?php echo htmlspecialchars($row['url']); ?>" title="<?php
                                        echo htmlspecialchars($row['url']); ?>" target="_blank" class="align-middle<?php
                                        if ($row['flag_updated']) echo ' fw-bold'; ?>"><?php
                                        if (count($_RDATA['s_crawldata_domains']) == 1) {
                                          echo htmlspecialchars(preg_replace($repStr, '', $row['url']));
                                        } else echo htmlspecialchars($row['url']);
                                      ?></a><?php
                                      if ($row['flag_updated']) { ?> 
                                        <img src="img/new.svg" alt="Updated" class="svg-icon"
                                          data-bs-toggle="tooltip" data-bs-placement="top" title="Page is new or content was updated during the most recent crawl."><?php
                                      } ?> 
                                    </div>
                                  </div>
                                </div>
                              </td><?php
                              if (count($_RDATA['s_category_list']) > 2) { ?> 
                                <td class="d-none d-md-table-cell text-center"><?php
                                  echo htmlspecialchars($row['category']);
                                ?></td><?php
                              } ?> 
                              <td class="text-center text-nowrap">
                                <span><?php echo htmlspecialchars($row['status']); ?></span><?php
                                if ($row['flag_unlisted']) { ?> 
                                  <img src="img/hidden.svg" alt="Unlisted" class="align-middle svg-icon mb-1"
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Unlisted: This page will be crawled for content and links as normal, but will not show up in any search results."><?php
                                }
                              ?></td>
                              <td class="d-none d-md-table-cell text-center"><?php
                                echo htmlspecialchars($row['priority']);
                              ?></td>
                            </tr><?php
                          } ?> 
                        </tbody>
                      </table>
                    </div><?php

                    echo $_RDATA['index_pagination_block']; ?> 
                  </fieldset>
                </form><?php
              }
            } ?> 
          </section><?php
          break;


        /* ************************************************************
         * Search Management *************************************** */
        case 'search': ?> 
          <section class="row justify-content-center">
            <header class="col-sm-10 col-md-8 col-lg-12 col-xl-10 col-xxl-8 mb-2">
              <h2>Search Management</h2>
            </header>

            <div class="col-sm-10 col-md-8 col-lg-5 col-xl-4 col-xxl-3 order-lg-2"><?php
              if ($_ODATA['s_limit_query_log']) { ?> 
                <div class="shadow rounded-3 mb-3 overflow-visible">
                  <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Search Information</h3>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group"><?php
                      if ($_RDATA['s_hits_per_hour']) {
                        if ($_RDATA['s_hours_since_oldest_hit'] >= 1) { ?> 
                          <li class="list-group-item">
                            <label class="d-flex w-100">
                              <strong class="pe-2">Searches per Hour</strong>
                              <var class="text-end flex-grow-1 text-nowrap"><?php
                                echo round($_RDATA['s_hits_per_hour'], 1);
                              ?></var>
                            </label>
                          </li><?php
                        }
                        if ($_RDATA['s_hours_since_oldest_hit'] >= 24) { ?> 
                          <li class="list-group-item">
                            <label class="d-flex w-100">
                              <strong class="pe-2">Searches per Day</strong>
                              <var class="text-end flex-grow-1 text-nowrap"><?php
                                echo round($_RDATA['s_hits_per_hour'] * 24, 1);
                              ?></var>
                            </label>
                          </li><?php
                        }
                        if ($_RDATA['s_hours_since_oldest_hit'] >= 168) { ?> 
                          <li class="list-group-item">
                            <label class="d-flex w-100">
                              <strong class="pe-2">Searches per Week</strong>
                              <var class="text-end flex-grow-1 text-nowrap"><?php
                                echo round($_RDATA['s_hits_per_hour'] * 24 * 7, 1);
                              ?></var>
                            </label>
                          </li><?php
                        } ?> 
                        <li class="list-group-item">
                          <label class="d-flex w-100">
                            <strong class="pe-2">Average Search Results per Query</strong>
                            <var class="text-end flex-grow-1 text-nowrap"><?php
                              echo round($_RDATA['q_average_results'], 1);
                            ?></var>
                          </label>
                        </li>
                        <li class="list-group-item">
                          <label class="d-flex w-100">
                            <strong class="pe-2">Median Search Results per Query</strong>
                            <var class="text-end flex-grow-1 text-nowrap"><?php
                              echo $_RDATA['q_median_results'];
                            ?></var>
                          </label>
                        </li><?php
                      } else { ?> 
                        <li class="list-group-item">
                          <p class="mb-0">
                            No searches logged yet. To see search statistics here, start
                            using your search engine. Tell your friends!
                          </p>
                        </li><?php
                      } ?> 
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Pages in Database</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo $_RDATA['s_pages_stored'];
                          ?></var>
                        </label>
                      </li><?php
                      if ($_RDATA['s_pages_stored']) { ?> 
                        <li class="list-group-item">
                          <label class="d-flex w-100">
                            <strong class="pe-2">Searchable Pages
                              <img src="img/help.svg" alt="Information" class="align-middle svg-icon mb-1"
                                data-bs-toggle="tooltip" data-bs-placement="top" title="Searchable pages are the pages in your index that are not Unlisted<?php echo (!$_ODATA['s_show_orphans']) ? ' nor Orphaned' : ''; ?>.">
                            </strong>
                            <var class="text-end flex-grow-1 text-nowrap"><?php
                              echo $_RDATA['s_searchable_pages'];
                            ?></var>
                          </label>
                        </li>
                        <li class="list-group-item">
                          <label class="d-flex w-100">
                            <strong class="pe-2">Page Encodings</strong>
                            <ol class="list-group list-group-flush flex-grow-1" id="os_crawl_info_charsets"><?php
                              foreach ($_RDATA['s_crawldata_info']['Charsets'] as $encoding => $value) { ?> 
                                <li class="list-group-item text-end p-0 border-0">
                                  <strong><?php echo htmlspecialchars($encoding); ?>:</strong>
                                  <var title="<?php echo $value; ?> pages"><?php
                                    echo round(($value / $_RDATA['s_crawldata_info']['Rows']) * 100, 1);
                                  ?>%</var>
                                </li><?php
                              } ?> 
                            </ol>
                          </label>
                        </li><?php
                      } ?> 
                    </ul>
                  </div>
                </div><?php
              } ?> 

              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Query Log &amp; Cache</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Store Query Log for:</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_limit_query_log" value="<?php echo $_ODATA['s_limit_query_log']; ?>" min="0" max="255" step="1" class="form-control d-inline-block ms-1 me-1" aria-labelledby="os_s_limit_query_log_text"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="To disable the query log, set this value to zero (0). Disabling the query log will also disable search result caching."> days
                          </span>
                        </label>
                        <p id="os_s_limit_query_log_text" class="form-text">
                          The query log is a rolling log of searches on which the statistics above are
                          based. Longer query log periods will give more accurate statistics, but also
                          require more database space. (max: 255 days)
                        </p>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Current Query Log Size</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo OS_readSize($_RDATA['s_query_info']['Data_length'] - $_RDATA['s_cache_size']);
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Currently Cached Searches</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo $_RDATA['s_cached_searches'];
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Current Cache Size
                            <img src="img/help.svg" alt="Information" class="align-middle svg-icon mb-1"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="The Search Result Cache is cleared after each successful crawl, or you can purge the cache manually below.">
                          </strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            if (!function_exists('gzcompress')) { ?> 
                              <img src="img/warning.svg" alt="Notice" class="align-middle svg-icon mb-1 me-1"
                                data-bs-toggle="tooltip" data-bs-placement="top" title="PHP's GZip functions are not enabled. This means your Search Result Cache won't be able to store as many results. You may want to consider increasing the Search Result Cache limit to compensate for this."><?php
                            }
                            echo OS_readSize($_RDATA['s_cache_size'], true);
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Limit Cache Size:</strong>
                          <span class="flex-grow-1 text-end text-nowrap"><?php
                            if (!$_ODATA['s_limit_query_log']) { ?> 
                              <img src="img/warning.svg" alt="Warning" class="align-middle svg-icon"
                                data-bs-toggle="tooltip" data-bs-placement="bottom" title="The query log and search result cache have been disabled. To use the search result cache, enable the query log."><?php
                            } ?> 
                            <input type="number" name="os_s_limit_cache" value="<?php echo $_ODATA['s_limit_cache']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block ms-1 me-1"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="The search result cache will store the results of common searches to potentially ease the load on your database and server. To disable caching, change this value to zero (0)."<?php
                              if (!$_ODATA['s_limit_query_log']) echo ' disabled="disabled"'; ?>> <abbr title="kibibytes">kiB</abbr>
                          </span>
                        </label>
                      </li>
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_s_cache_config" class="btn btn-primary">Save Changes</button>
                      <button type="submit" name="os_submit" value="os_s_cache_purge" class="btn btn-primary"<?php
                        if (!$_RDATA['s_cache_size']) echo ' disabled="disabled"'; ?>>Purge Cache</button>
                    </div>
                  </div>
                </fieldset>
              </form>

              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Offline Search Javascript</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2"><?php
                      if (count($_RDATA['s_crawldata_domains']) > 1) { ?> 
                        <li class="list-group-item">
                          <label class="d-flex lh-lg w-100">
                            <strong class="pe-2">Domain:</strong>
                            <span class="text-end flex-grow-1 text-nowrap">
                              <select name="os_jw_hostname" class="form-select d-inline-block"><?php
                                foreach ($_RDATA['s_crawldata_domains'] as $domain => $count) { ?> 
                                  <option value="<?php echo $domain; ?>"<?php
                                    if ($_ODATA['jw_hostname'] == $domain) echo ' selected="selected"'; ?>><?php
                                    echo $domain, ' (', $count, ')';
                                  ?></option><?php
                                } ?> 
                              </select>
                            </span>
                          </label>
                        </li><?php
                      } ?> 
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Compression Level:</strong>
                          <var class="text-end flex-grow-1 text-nowrap">
                            <input type="number" name="os_jw_compression" value="<?php echo $_ODATA['jw_compression']; ?>" min="0" max="100" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Apply text content compression to the output. 100 is no compression, while 0 is maximum compression.">
                          </var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Search Database Size</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo OS_readSize($_RDATA['s_crawldata_info']['Data_length'], true);
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">PHP <code>memory_limit</code></strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php

                            // Decipher the PHP memory_limit
                            $memory_limit = ini_get('memory_limit');
                            if ($memory_limit != -1) {
                              preg_match(
                                '/(\d+)([KMG]?)/i',
                                trim((string)$memory_limit),
                                $match
                              );

                              if (count($match)) {
                                switch (strtoupper($match[2])) {
                                  case 'G':
                                    $memory_limit = OS_readSize((int)$match[1] * 1073741824, true);
                                    break;

                                  case 'M':
                                    $memory_limit = OS_readSize((int)$match[1] * 1048576, true);
                                    break;

                                  case 'K':
                                    $memory_limit = OS_readSize((int)$match[1] * 1024, true);
                                    break;

                                  default:
                                    $memory_limit = OS_readSize((int)$match[1], true);

                                }
                              } else $memory_limit = 'Unknown';
                            } else $memory_limit = 'No limit';
                            echo $memory_limit;
                          ?></var>
                        </label>
                      </li>
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_jw_config" class="btn btn-primary">Save Changes</button>
                      <button type="submit" name="os_submit" value="os_jw_write" class="btn btn-primary" title="Download Javascript File"<?php
                        if (!$_RDATA['s_crawldata_info']['Rows']) {
                          echo ' disabled="disabled"';
                        } ?>>Download</button>
                    </div>
                  </div>
                </fieldset>
              </form>
            </div>

            <div class="col-sm-10 col-md-8 col-lg-7 col-xl-6 col-xxl-5 order-lg-1">
              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Search Settings</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <h4>Query Limits</h4>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Maximum Query Length:</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_limit_query" value="<?php echo $_ODATA['s_limit_query']; ?>" min="0" max="255" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search queries will be limited to this length before any processing. Max: 255">
                          </span>
                        </label>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Maximum Number of Terms:</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_limit_terms" value="<?php echo $_ODATA['s_limit_terms']; ?>" min="0" max="255" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms beyond this limit in a single query will be ignored.">
                          </span>
                        </label>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Minimum Term Length:</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_limit_term_length" value="<?php echo $_ODATA['s_limit_term_length']; ?>" min="0" max="255" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Individual search terms with fewer than this number of characters will be ignored, unless enclosed by &quot;quotes&quot; or marked as '+important'.">
                          </span>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <h4>Match Weighting</h4>
                        <div class="row">
                          <div class="col-sm-6">
                            <h5 class="text-center">
                              Additive
                              <img src="img/help.svg" alt="Information" class="align-middle svg-icon mb-1"
                                data-bs-toggle="tooltip" data-bs-placement="top" title="Additive values are ADDED to the relevance score for a search result.">
                            </h5>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Page Title:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_title" value="<?php echo $_RDATA['s_weights']['title']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page title.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Body Text:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_body" value="<?php echo $_RDATA['s_weights']['body']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page body text.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Keywords:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_keywords" value="<?php echo $_RDATA['s_weights']['keywords']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page keywords meta information.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Description:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_description" value="<?php echo $_RDATA['s_weights']['description']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page description meta information.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">In URL:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_url" value="<?php echo $_RDATA['s_weights']['url']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page URL.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">CSS Selector:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_css_value" value="<?php echo $_RDATA['s_weights']['css_value']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="An extra additive weight score will be added to content found in elements matching the CSS selectors below.">
                              </span>
                            </label>
                          </div>
                          <label class="col-12 order-sm-last mb-2">
                            <textarea rows="2" cols="30" name="os_s_weight_css" class="form-control"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Note that changes made here will only take effect after the next crawl. Simple CSS selectors only (#id, .class or element). Separated by spaces."><?php
                              echo htmlspecialchars($_ODATA['s_weight_css']);
                            ?></textarea>
                          </label>
                          <div class="col-sm-6">
                            <h5 class="text-center">
                              Multipliers
                              <img src="img/help.svg" alt="Information" class="align-middle svg-icon mb-1"
                                data-bs-toggle="tooltip" data-bs-placement="top" title="These values MULTIPLY the final relevance score for a search result. Should be greater than 1.0.">
                            </h5>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Multi-term:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_multi" value="<?php echo $_RDATA['s_weights']['multi']; ?>" min="0" max="10" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="If a result matches more than one of the given search terms; applied for every search term match beyond the first.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Important (+):</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_important" value="<?php echo $_RDATA['s_weights']['important']; ?>" min="0" max="10" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Applied for search terms the user has marked as '+important'. Also applied to &quot;phrase matches&quot;.">
                              </span>
                            </label>
                          </div>
                        </div>
                      </li>
                      <li class="list-group-item w-100">
                        <h4>Result Output</h4>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Output Encoding:</strong>
                          <span class="flex-grow-1 text-end">
                            <input type="text" name="os_s_charset" value="<?php echo $_ODATA['s_charset']; ?>" pattern="[\w\d-]*" class="form-control d-inline-block w-auto mw-10em" aria-labelledby="os_s_charset_text">
                          </span>
                        </label>
                        <p id="os_s_charset_text" class="form-text">
                          The <em>Output Encoding</em> value should match the encoding of your search results page, and
                          ideally match the character encoding of most of your crawled pages. UTF-8 is strongly
                          recommended.
                        </p>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Maximum Returned Results:</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_limit_results" value="<?php echo $_ODATA['s_limit_results']; ?>" min="0" max="255" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Maximum number of search results to return for a single query.">
                          </span>
                        </label>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Results per Page (pagination):</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_results_pagination" value="<?php echo $_ODATA['s_results_pagination']; ?>" min="0" max="255" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="If there are more than this many search results, break them up into pages with pagination links.">
                          </span>
                        </label>
                        <label class="d-flex lh-lg w-100 mb-2">
                          <strong class="pe-2">Maximum Matched Text (characters):</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_limit_matchtext" value="<?php echo $_ODATA['s_limit_matchtext']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Maximum amount of matched 'description' text to display beneath the heading of each search result.">
                          </span>
                        </label>
                        <div class="row">
                          <div class="col-3">
                            <strong>Options:</strong>
                          </div>
                          <div class="col-9">
                            <ul class="list-unstyled">
                              <li class="form-check mb-1">
                                <input type="checkbox" name="os_s_show_orphans" id="os_s_show_orphans" value="1" class="form-check-input"<?php
                                  if ($_ODATA['s_show_orphans']) echo ' checked="checked"'; ?>>
                                <label for="os_s_show_orphans" class="form-check-label">Show Orphans in search results</label>
                              </li>
                              <li class="form-check mb-1">
                                <input type="checkbox" name="os_s_show_filetype_html" id="os_s_show_filetype_html" value="1" class="form-check-input"
                                  data-bs-toggle="tooltip" data-bs-placement="top" title="By default the [HTML] filetype is hidden in search results, since it's assumed."<?php
                                  if ($_ODATA['s_show_filetype_html']) echo ' checked="checked"'; ?>>
                                <label for="os_s_show_filetype_html" class="form-check-label">Show [HTML] filetype in search results</label>
                              </li>
                            </ul>
                          </div>
                        </div>
                      </li>
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_s_search_config" class="btn btn-primary">Save Changes</button>
                    </div>
                  </div>
                </fieldset>
              </form>
            </div>

            <div class="col-xl-10 col-xxl-8 order-3">
              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Search Result Template</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <label class="w-100 lh-lg">
                          <strong>Search Result HTML Template:</strong>
                          <textarea rows="24" cols="60" name="os_s_result_template" wrap="off" class="form-control font-monospace" aria-labelledby="os_s_result_template_text"><?php
                            echo htmlspecialchars($_ODATA['s_result_template']);
                          ?></textarea>
                        </label>
                        <p id="os_s_result_template_text" class="form-text">
                          This template uses the
                          <a href="https://mustache.github.io" target="_blank">Mustache</a>
                          templating system. See the
                          <a href="https://mustache.github.io/mustache.5.html" target="_blank">Mustache
                          manual</a> for more information. To restore the default template, submit a
                          blank textarea.
                        </p>
                      </li>
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_s_search_template" class="btn btn-primary">Save Changes</button>
                    </div>
                  </div>
                </fieldset>
              </form>
            </div>
          </section><?php
          break;


        /* ************************************************************
         * Query Log *********************************************** */
        case 'queries': ?> 
          <section class="row justify-content-center">
            <header class="col-6 col-xl-5 col-xxl-4 mb-2">
              <h2>Query Log</h2>
            </header>
            <div class="col-6 col-xl-5 col-xxl-4 mb-2 text-end text-nowrap">
              <button type="button" class="btn btn-primary" id="os_query_log_download" title="Download Query Log"<?php
                if ($_ODATA['sp_crawling']) echo ' disabled="disabled"'; ?>>Download</button>
            </div><?php

            if (is_array($_RDATA['query_log_rows']) && count($_RDATA['query_log_rows'])) { ?> 
              <div class="col-xl-10 col-xxl-8">
                <div class="rounded-3 border border-1 border-secondary-subtle shadow border-bottom-0 mb-3 overflow-hidden">
                  <table class="table table-striped w-100 mb-0">
                    <thead id="os_queries_thead">
                      <tr class="text-nowrap user-select-none">
                        <th class="fs-5" scope="col">
                          <span role="button" id="os_queries_query">Query</span>
                          <img src="img/arrow-down.svg" alt="Sort" class="align-middle svg-icon-sm mb-1">
                        </th>
                        <th class="fs-5 text-center os_sorting os_desc" scope="col">
                          <span role="button" id="os_queries_hits">Hits</span>
                          <img src="img/arrow-down.svg" alt="Sort" class="align-middle svg-icon-sm mb-1">
                        </th>
                        <th class="fs-5 text-center d-none d-sm-table-cell" scope="col">
                          <span role="button" id="os_queries_results">Results</span>
                          <img src="img/arrow-down.svg" alt="Sort" class="align-middle svg-icon-sm mb-1">
                        </th>
                        <th class="fs-5 text-center" colspan="2" scope="col">
                          <span role="button" id="os_queries_stamp">
                            <span class="d-none d-md-inline">Last Requested</span>
                            <img src="img/clock.svg" alt="" class="d-md-none align-middle svg-icon mb-1"
                              data-bs-toggle="tooltip" data-bs-placement="left" title="Time since this query was last requested">
                          </span>
                          <img src="img/arrow-down.svg" alt="Sort" class="align-middle svg-icon-sm mb-1">
                        </th>
                      </tr>
                    </thead>
                    <tbody class="table-group-divider" id="os_queries_tbody"><?php
                      foreach ($_RDATA['query_log_rows'] as $query) { ?> 
                        <tr class="text-nowrap">
                          <th scope="row" data-value="<?php echo count($_RDATA['query_log_rows']) - $query['rownum']; ?>">
                            <div class="d-inline-block align-middle mw-90">
                              <div class="w-100 d-table table-fixed">
                                <div class="w-100 d-table-cell overflow-hidden text-ellipsis">
                                  <span title="<?php echo htmlspecialchars($query['query']); ?>"
                                    data-bs-toggle="modal" data-bs-target="#queriesModal" role="button"><?php
                                    echo htmlspecialchars($query['query']);
                                  ?></span>
                                </div>
                              </div>
                            </div>
                          </th>
                          <td class="text-center" data-value="<?php echo (int)$query['hits']; ?>"><?php
                            echo (int)$query['hits'];
                          ?></td>
                          <td class="text-center d-none d-sm-table-cell" data-value="<?php echo (int)$query['avg_results']; ?>"><?php
                            echo (int)$query['avg_results'];
                          ?></td>
                          <td class="text-end" data-value="<?php echo (int)$query['last_hit']; ?>">
                            <time datetime="<?php echo date('c', (int)$query['last_hit']); ?>"><?php
                              $periods = array(
                                array('d', 'day', 'days'),
                                array('h', 'hour', 'hours'),
                                array('m', 'minute', 'minutes'),
                                array('s', 'second', 'seconds')
                              );
                              $diff = time() - (int)$query['last_hit'];
                              if ($days = floor($diff / 86400)) {
                                $number = $days;
                                $units = $periods[0];
                              } else if ($hours = floor($diff / 3600)) {
                                $number = $hours;
                                $units = $periods[1];
                              } else if ($minutes = floor($diff / 60)) {
                                $number = $minutes;
                                $units = $periods[2];
                              } else {
                                $number = $diff;
                                $units = $periods[3];
                              }
                              echo $number.'<span class="d-sm-none">'.$units[0].'</span>';
                              echo '<span class="d-none d-sm-inline"> '.(($number == 1) ? $units[1] : $units[2]).'</span> ';
                              echo '<span class="d-none d-sm-inline">ago</span>';
                            ?></time>
                          </td><?php
                          if ($_GEOIP2) {
                            try {
                              $query['geo'] = $_GEOIP2->country($query['ipaddr']);
                            } catch(Exception $e) { $query['geo'] = false; }
                          } ?> 
                          <td class="text-end d-none d-md-table-cell" data-value="<?php echo $query['ipaddr']; ?>">
                            <a href="https://bgp.he.net/ip/<?php echo $query['ipaddr']; ?>" target="_blank"><?php echo $query['ipaddr']; ?></a><?php
                            if (!empty($query['geo'])) {
                              if (file_exists(__DIR__.'/img/flags/'.strtolower($query['geo']->raw['country']['iso_code']).'.png')) { ?> 
                                <img src="<?php echo
                                  'img/flags/'.strtolower($query['geo']->raw['country']['iso_code']).'.png';
                                  ?>" alt="<?php echo htmlspecialchars($query['geo']->raw['country']['names']['en']); ?>"
                                  title="<?php echo htmlspecialchars($query['geo']->raw['country']['names']['en']); ?>"
                                  class="align-middle svg-icon-flag mb-1"><?php
                              }
                            }
                          ?></td>
                        </tr><?php
                      } ?> 
                    </tbody>
                  </table>
                </div>

                <div class="modal fade" id="queriesModal" tabindex="-1" aria-labelledby="queriesModalLabel" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h1 class="modal-title fs-3" id="queriesModalLabel">Search Query Details</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" title="Close"></button>
                      </div>
                      <div class="modal-body">
                        <h4>Query</h4>
                        <ul class="list-group mb-3">
                          <li class="list-group-item">
                            <label class="d-flex flex-column">
                              <strong class="pe-2">Query Text</strong>
                              <var id="os_queries_modal_query"></var>
                            </label>
                          </li>
                          <li class="list-group-item">
                            <div class="row">
                              <div class="col-sm-6">
                                <label class="d-flex">
                                  <strong class="pe-2">Hit Count</strong>
                                  <var class="flex-grow-1 text-end" id="os_queries_modal_hits"></var>
                                </label>
                              </div>
                              <div class="col-sm-6">
                                <label class="d-flex">
                                  <strong class="pe-2">Results Returned</strong>
                                  <var class="flex-grow-1 text-end" id="os_queries_modal_results"></var>
                                </label>
                              </div>
                            </div>
                          </li>
                        </ul>
                        <h4>Last Request Information</h4>
                        <ul class="list-group">
                          <li class="list-group-item">
                            <label class="d-flex flex-column">
                              <strong class="pe-2">Date / Time Requested</strong>
                              <var id="os_queries_modal_stamp"></var>
                            </label>
                          </li>
                          <li class="list-group-item">
                            <label class="d-flex">
                              <strong class="pe-2">From IP Address</strong>
                              <var class="flex-grow-1 text-end" id="os_queries_modal_ipaddr"></var>
                            </label>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </div>
                </div>
              </div><?php
            } ?> 
          </section><?php
          break;

      } ?> 

      <div class="modal fade" id="crawlerModal" tabindex="-1" aria-labelledby="crawlerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
          <div class="modal-content">
            <div class="modal-header">
              <h1 class="modal-title fs-3" id="crawlerModalLabel">Run Crawler Manually</h1>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" title="Close"></button>
            </div>
            <div class="modal-body">
              <div class="text-center mb-2 crawl-controls">
                <button type="button" class="btn btn-primary" id="os_crawl_start"<?php
                  if ($_ODATA['sp_crawling']) echo ' disabled="disabled"'; ?>><?php
                  echo ($_ODATA['sp_crawling']) ? 'Crawling...' : 'Start Crawl';
                ?></button>
                <button type="button" class="btn btn-primary" id="os_crawl_cancel"<?php
                  if (!$_ODATA['sp_crawling']) echo ' disabled="disabled"'; ?>>Cancel Crawl</button>
              </div>
              <div class="row justify-content-center">
                <div class="col-lg-6 mb-2 text-center">
                  <strong>Show log:</strong> &nbsp;
                  <input type="radio" name="os_crawl_grep" value="errors"> Errors &nbsp;
                  <input type="radio" name="os_crawl_grep" value="" checked="checked"> Normal &nbsp;
                  <input type="radio" name="os_crawl_grep" value="all"> All
                </div>
                <div class="col-10 col-lg-6 mb-2 text-center crawl-progress">
                  <progress class="w-100 rounded-pill overflow-hidden border border-1 border-secondary-subtle shadow-sm bg-white position-relative"
                    max="100" value="0" title="[Links crawled] / [Total links]" id="os_crawl_progress">0%</progress>
                </div>
                <div class="col-12">
                  <textarea rows="15" cols="80" readonly="readonly" class="form-control" wrap="off" id="os_crawl_log" placeholder="Crawl log"></textarea>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <div class="d-flex">
                <p class="mb-0">
                  <strong>Note:</strong> You may close this popup and/or leave the page while the crawler is running.
                </p>
                <button type="button" class="btn btn-primary" id="os_crawl_log_download" title="Download Crawl Log"<?php
                  if ($_ODATA['sp_crawling']) echo ' disabled="disabled"'; ?>>Download</button>
              </div>
            </div>
          </div>
        </div>
      </div><?php


    /* ****************************************************************
     * Not logged in; Show login page ****************************** */
    } else { ?> 
      <section class="row justify-content-center">
        <header class="col-10 col-sm-8 col-md-6 col-lg-5 col-xl-4 mb-2">
          <h2>Welcome</h2>
        </header>
        <div class="w-100"></div>

        <div class="col-10 col-sm-8 col-md-6 col-lg-5 col-xl-4">
          <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
            class="shadow rounded-3 mb-3 overflow-visible">
            <fieldset>
              <legend class="mb-0">
                <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Log In</h3>
              </legend>
              <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                <ul class="list-group mb-2">
                  <li class="list-group-item">
                    <label class="d-flex lh-lg w-100">
                      <strong class="text-nowrap pe-2">Username</strong>
                      <span class="flex-grow-1 text-end">
                        <input type="text" name="os_admin_username" required="required" class="form-control d-inline-block w-auto mw-10em">
                      </span>
                    </label>
                  </li>
                  <li class="list-group-item">
                    <label class="d-flex lh-lg w-100">
                      <strong class="text-nowrap pe-2">Password</strong>
                      <span class="flex-grow-1 text-end">
                        <input type="password" name="os_admin_password" required="required" class="form-control d-inline-block w-auto mw-10em">
                      </span>
                    </label>
                  </li>
                </ul>
                <div class="text-center">
                  <button type="submit" name="os_submit" value="os_admin_login" class="btn btn-primary">Log In</button>
                </div>
              </div>
            </fieldset>
          </form>
        </div>
      </section><?php
    } ?> 
  </div>

  <script src="js/bootstrap.bundle.min.js"></script><?php
  if ($_SESSION['admin_username']) { ?> 
    <script src="js/admin.js"></script><?php
  } ?> 
</body>
</html>