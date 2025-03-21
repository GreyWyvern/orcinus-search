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
  $duration = ($time < 1000000);
  $since = ($duration) ? $time : time() - $time;
  $periods = array(
    array('d', 'day', 'days'),
    array('h', 'hour', 'hours'),
    array('m', 'minute', 'minutes'),
    array('s', 'second', 'seconds')
  );
  $days = floor($since / 86400); $since %= 86400;
  $hours = floor($since / 3600); $since %= 3600;
  $minutes = floor($since / 60);
  $seconds = $since % 60;
  if ($duration) {
    $duration = 'PT';
    if ($days) $duration .= $days.'D';
    if ($hours) $duration .= $hours.'H';
    if ($minutes) $duration .= $minutes.'M';
    if ($seconds) $duration .= $seconds.'S';
  } ?> 
  <time class="countup_timer<?php if (!$duration) echo ' active'; ?>" data-start="<?php
    echo $time; ?>" datetime="<?php echo ($duration) ? $duration :  date('c', $time);
    ?>"<?php if (!$duration) echo ' title="'.date('r', $time).'"';
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
      <?php echo ($seconds == 1) ? $periods[3][1] : $periods[3][2]; ?>
    </span>
  </time><?php
}


/**
 * Accept a single query log database row and return a geolocation
 * array; only 'ip' and 'geo' values from the row are used
 *
 * Attempt to check for a cached value before resorting to the
 * GEOIP2 database
 *
 */
function OS_getGeo($row) {
  global $_GEOIP2, $_RDATA;

  if (!$_GEOIP2 || empty($row['ip'])) return false;

  // Check if this query has already been geolocated
  if (!empty($row['geo']) && isset($_RDATA['geocache'][$row['geo']]))
    return $_RDATA['geocache'][$row['geo']];

  // Check if the IP has already been geolocated
  if (isset($_RDATA['geocache'][$row['ip']]))
    return $_RDATA['geocache'][$row['ip']];

  // Fetch a result from the GEOIP2 database
  try {
    $geo = $_GEOIP2->country($row['ip']);

    if (!empty($geo->country->names['en'])) {
      $geo = $geo->country;

      // Cache this result by ISO code
      $_RDATA['geocache'][$geo->isoCode] = $geo;

      // If the database row from the query hasn't already
      // been geolocated, add its ISO code to the geo column
      // and cache it based on IP
      if (empty($row['geo'])) {
        $_RDATA['addGeo']->execute(array(
          'geo' => $geo->isoCode,
          'ip' => $row['ip']
        ));
        $_RDATA['geocache'][$row['ip']] = $geo;
      }

      return $geo;
    }
  } catch(Exception $e) {}

  return false;
}


// ***** Load Maxmind GeoIP2
$_GEOIP2 = false;
if (!class_exists('GeoIp2\Database\Reader') && file_exists(__DIR__.'/geoip2/geoip2.phar'))
  include __DIR__.'/geoip2/geoip2.phar';
if (class_exists('GeoIp2\Database\Reader') && file_exists(__DIR__.'/geoip2/GeoLite2-Country.mmdb')) {
  if ($_GEOIP2 = new GeoIp2\Database\Reader(__DIR__.'/geoip2/GeoLite2-Country.mmdb')) {
    $_RDATA['geoip2_version'] = GeoIp2\WebService\Client::VERSION;
    $_RDATA['addGeo'] = $_DDATA['pdo']->prepare(
      'UPDATE `'.$_DDATA['tbprefix'].'query` SET `geo`=:geo WHERE `ip`=:ip;'
    );
    $_RDATA['geocache'] = array('unk' => new stdClass());
    $_RDATA['geocache']['unk']->names = array('en' => 'Unknown');
  }
}


// ***** Database derived runtime data
$_RDATA['page_index_total_rows'] = 0;
$crawldata_rows = $_DDATA['pdo']->query(
  'SELECT COUNT(*) AS `count` FROM `'.$_DDATA['tbprefix'].'crawldata`;'
);
$err = $crawldata_rows->errorInfo();
if ($err[0] == '00000') {
  $crawldata_rows = $crawldata_rows->fetchAll();
  $_RDATA['page_index_total_rows'] = $crawldata_rows[0]['count'];
} else if (isset($_SESSION['error']))
  $_SESSION['error'][] = 'Could not determine page index count';

$_RDATA['s_query_log_size'] = $_RDATA['s_cache_size'];
$querydata_size = $_DDATA['pdo']->query(
  'SELECT * FROM `'.$_DDATA['tbprefix'].'query`;'
);
$err = $querydata_size->errorInfo();
if ($err[0] == '00000') {
  $_RDATA['s_query_log_size'] = strlen(serialize($querydata_size->fetchAll()));
} else if (isset($_SESSION['error']))
  $_SESSION['error'][] = 'Could not determine query log size';


// ***** Other runtime data
if (count($_ODATA['sp_domains']) == 1 && $_ODATA['jw_hostname'] != key($_ODATA['sp_domains']))
  OS_setValue('jw_hostname', key($_ODATA['sp_domains']));

$_RDATA['admin_pagination_options'] = array(25, 50, 100, 250, 500, 1000);
if (!in_array($_ODATA['admin_index_pagination'], $_RDATA['admin_pagination_options'], true))
  OS_setValue('admin_index_pagination', 100);

$_RDATA['admin_query_log_display_options'] = array(10, 25, 50, 100, 250, 500, 1000, 0);
if (!in_array($_ODATA['admin_query_log_display'], $_RDATA['admin_query_log_display_options'], true))
  OS_setValue('admin_query_log_display', 250);

$_RDATA['admin_pages'] = array(
  'crawler' => 'Crawler',
  'index' => 'Page Index',
  'search' => 'Search',
  'stats' => 'Statistics'
);
if ($_ODATA['s_limit_query_log'])
  $_RDATA['admin_pages']['queries'] = 'Query Log';

$_RDATA['page_index_charsets'] = array();
$_RDATA['page_index_mime_types'] = array();

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
if (empty($_SESSION['index_show_page_titles'])) $_SESSION['index_show_page_titles'] = 'off';
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

// We are logged in with a valid admin username
} else {

  /* ***** Handle POST Requests ************************************** */
  if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // JSON POST request
    // These are usually sent by javascript fetch()
    if ($_SERVER['CONTENT_TYPE'] == 'application/json') {
      $postBody = file_get_contents('php://input');
      $_POST = json_decode($postBody, false);

      $response = array();

      switch ($_POST->action ?? '') {

        // ***** Set the key for initiating the crawler
        case 'setkey':
          if (!$_ODATA['sp_crawling']) {
            $md5 = md5(hrtime(true));
            OS_setValue('sp_key', $md5);
            OS_setValue('sp_log', '');
            OS_setValue('sp_progress', array(0, 1, false, 0));
            $response = array(
              'status' => 'Success',
              'message' => 'Key set to initiate crawler',
              'sp_key' => $md5
            );

          } else {
            $response = array(
              'status' => 'Error',
              'message' => 'Crawler is already running; current progress: '.$_ODATA['sp_progress'][0].' / '.$_ODATA['sp_progress'][1]
            );
          }
          break;

        // ***** Download a text or csv file
        case 'download':
          switch ($_POST->content ?? '') {

            // Download a text file of the latest crawl log
            case 'crawl_log':
              if (!$_ODATA['sp_crawling']) {
                if ($_ODATA['sp_time_end']) {
                  $lines = explode("\n", $_ODATA['sp_log']);

                  switch ($_POST->grep ?? '') {
                    case 'all':
                      $postGrep = '-all';
                      break;

                    case 'errors':
                      $lines = preg_grep('/^[\[\*]/', $lines);
                      $postGrep = '-errors';
                      break;

                    default:
                      $lines = preg_grep('/^[\[\*\w\d]/', $lines);
                      $postGrep = '';
                  }

                  header('Content-type: text/plain; charset='.strtolower($_ODATA['s_charset']));
                  header('Content-disposition: attachment; filename="'.
                    'crawl-log'.$postGrep.'_'.date('Y-m-d', $_ODATA['sp_time_end']).'.txt"');

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

            // Download a csv of the unfiltered page index
            case 'page_index':
              $pageIndex = $_DDATA['pdo']->query(
                'SELECT `url`, `category`, `content_mime`, `content_charset`,
                  `status`, `flag_unlisted`, `last_modified`, `priority`
                    FROM `'.$_DDATA['tbprefix'].'crawldata` ORDER BY `url_sort`;');
              $err = $pageIndex->errorInfo();
              if ($err[0] == '00000') {

                $pageIndex = $pageIndex->fetchAll();
                if (count($pageIndex)) {

                  header('Content-type: text/csv; charset='.strtolower($_ODATA['s_charset']));
                  header('Content-disposition: attachment; filename="'.
                    'page-index_'.date('Y-m-d').'.csv"');

                  $output = fopen('php://output', 'w');

                  // UTF-8 byte order mark
                  if (strtolower($_ODATA['s_charset']) == 'utf-8')
                    fwrite($output, "\xEF\xBB\xBF");

                  $headings = array(
                    'URL', 'Category', 'MIME Type', 'Character Encoding',
                    'Status', 'Last Modified', 'Priority'
                  );
                  fputcsv($output, $headings);

                  foreach ($pageIndex as $line) {
                    if ($line['flag_unlisted'])
                      $line['status'] .= ' (Unlisted)';
                    unset($line['flag_unlisted']);

                    $line['last_modified'] = date('c', $line['last_modified']);

                    fputcsv($output, $line);
                  }

                  fclose($output);
                  die();

                } else {
                  $response = array(
                    'status' => 'Error',
                    'message' => 'The page index is empty; nothing to download'
                  );
                }
              } else {
                $response = array(
                  'status' => 'Error',
                  'message' => 'Could not read the page index database'
                );
              }
              break;

            // Download a csv of the complete query log
            case 'query_log':
              $queryLog = $_DDATA['pdo']->query(
                'SELECT `query`, `results`, `stamp`, `ip`, `geo`
                   FROM `'.$_DDATA['tbprefix'].'query` ORDER BY `stamp` DESC;'
              );
              $err = $queryLog->errorInfo();
              if ($err[0] == '00000') {

                $queryLog = $queryLog->fetchAll();
                if (count($queryLog)) {

                  header('Content-type: text/csv; charset='.strtolower($_ODATA['s_charset']));
                  header('Content-disposition: attachment; filename="'.
                    'query-log_'.date('Y-m-d').'.csv"');

                  $output = fopen('php://output', 'w');

                  // UTF-8 byte order mark
                  if (strtolower($_ODATA['s_charset']) == 'utf-8')
                    fwrite($output, "\xEF\xBB\xBF");

                  $headings = array('Query', 'Results', 'Time Stamp', 'IP');
                  if ($_GEOIP2) $headings = [...$headings, 'Country Code', 'Country Name'];
                  fputcsv($output, $headings);

                  foreach ($queryLog as $line) {
                    $line['stamp'] = date('c', $line['stamp']);

                    // If we found a valid geolocation for this query, then
                    // append the data to this row of the CSV output
                    if ($geo = OS_getGeo($line)) {
                      $line['geo'] = $geo->isoCode;
                      $line['country'] = $geo->names['en'];
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

        // ***** Set an admin UI session variable
        case 'setsession':
          if (!empty($_POST->variable) && isset($_SESSION[$_POST->variable])) {
            $_SESSION[$_POST->variable] = $_POST->value ?? '';

            $response = array(
              'status' => 'Success',
              'message' => $_SESSION[$_POST->variable]
            );

          } else {
            $response = array(
              'status' => 'Error',
              'message' => 'Invalid session variable given'
            );
          }
          break;

        // ***** Not used?
        case 'fetch':
          if (!empty($_POST->value) && !empty($_ODATA[$_POST->value])) {
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
            $_POST['os_sp_starting'] = explode("\n", substr(preg_replace('/\n+/', "\n", str_replace("\r\n", "\n", trim($_POST['os_sp_starting']))), 0, 4095));
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
            $_POST['os_sp_require_url'] = explode("\n", substr(preg_replace('/\n+/', "\n", str_replace("\r\n", "\n", trim($_POST['os_sp_require_url']))), 0, 4095));
            foreach ($_POST['os_sp_require_url'] as $key => $require) {
              if (strlen($require) && $require[0] == '*') {
                $require = substr($require, 1);
                $test = preg_match('/'.str_replace('/', '\/', $require).'/', 'test');
                if ($test === false) {
                  $_SESSION['error'][] = 'Invalid regular expression in Require URL Match field \''.$require.'\' removed.';
                  unset($_POST['os_sp_require_url'][$key]);
                }
              } else $_POST['os_sp_require_url'][$key] = filter_var($require, FILTER_SANITIZE_URL);
            }
            OS_setValue('sp_require_url', implode("\n", $_POST['os_sp_require_url']));
          }

          if (isset($_POST['os_sp_ignore_url'])) {
            $_POST['os_sp_ignore_url'] = explode("\n", substr(preg_replace('/\n+/', "\n", str_replace("\r\n", "\n", trim($_POST['os_sp_ignore_url']))), 0, 4095));
            foreach ($_POST['os_sp_ignore_url'] as $key => $ignore) {
              if (strlen($ignore) && $ignore[0] == '*') {
                $ignore = substr($ignore, 1);
                $test = preg_match('/'.str_replace('/', '\/', $ignore).'/', 'test');
                if ($test === false) {
                  $_SESSION['error'][] = 'Invalid regular expression in Ignore URL Match field \''.$ignore.'\' removed.';
                  unset($_POST['os_sp_ignore_url'][$key]);
                }
              } else $_POST['os_sp_ignore_url'][$key] = filter_var($ignore, FILTER_SANITIZE_URL);
            }
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
            $_POST['os_sp_category_default'] = preg_replace('/[^\w \d-]/', '', preg_replace(array('/\s/', '/ {2,}/'), ' ', trim($_POST['os_sp_category_default'])));
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
            $_POST['os_sp_title_strip'] = explode("\n", substr(preg_replace('/\n+/', "\n", str_replace("\r\n", "\n", trim($_POST['os_sp_title_strip']))), 0, 4095));
            foreach ($_POST['os_sp_title_strip'] as $key => $title_strip) {
              if (strlen($title_strip) && $title_strip[0] == '*') {
                $title_strip = substr($title_strip, 1);
                $test = preg_match('/'.str_replace('/', '\/', $title_strip).'/', 'test');
                if ($test === false) {
                  $_SESSION['error'][] = 'Invalid regular expression in Remove Text from Titles field \''.$title_strip.'\' removed.';
                  unset($_POST['os_sp_title_strip'][$key]);
                }
              } else $_POST['os_sp_title_strip'][$key] = filter_var($title_strip, FILTER_SANITIZE_SPECIAL_CHARS);
            }
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
            if (in_array($_POST['os_sp_timezone'], timezone_identifiers_list(), true))
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
              $_POST['os_admin_email'] = explode("\n", substr(preg_replace('/\n+/', "\n", str_replace("\r\n", "\n", $_POST['os_admin_email'])), 0, 4095));
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
            $_POST['os_sp_sitemap_file'] = filter_var(substr($_POST['os_sp_sitemap_file'], 0, 255), FILTER_SANITIZE_URL);
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

                  // Refresh sp_domains and sp_crawldata_size since we
                  // deleted some rows
                  $domainList = array();
                  $urls = $_DDATA['pdo']->query(
                    'SELECT `url` FROM `'.$_DDATA['tbprefix'].'crawldata`;'
                  );
                  $err = $urls->errorInfo();
                  if ($err[0] == '00000') {
                    $urls = $urls->fetchAll();
                    foreach ($urls as $url) {
                      $url = parse_url($url['url']);
                      if (is_array($url)) {
                        $domain = $url['scheme'].'://'.$url['host'];
                        if (!isset($domainList[$domain])) {
                          $domainList[$domain] = 1;
                        } else $domainList[$domain]++;
                      }
                    }
                    OS_setValue('sp_domains', $domainList);
                  } else $_SESSION['error'][] = 'Could not read domain count data from search database: '.$err[2];

                  $crawldata_size = $_DDATA['pdo']->query(
                    'SELECT * FROM `'.$_DDATA['tbprefix'].'crawldata`;'
                  );
                  $err = $crawldata_size->errorInfo();
                  if ($err[0] == '00000') {
                    OS_setValue('sp_crawldata_size', strlen(serialize($crawldata_size->fetchAll())));
                  } else $_SESSION['error'][] = 'Could not determine updated crawl table size';
                  break;

                case 'category':
                  if (!empty($_POST['os_apply_new_category'])) {
                    $_POST['os_apply_new_category'] = substr(preg_replace('/[^\w \d-]/', '', preg_replace(array('/\s/', '/ {2,}/'), ' ', trim($_POST['os_apply_new_category']))), 0, 30);

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
                    $_POST['os_apply_new_priority'] = round(max(0, min(1, (float)$_POST['os_apply_new_priority'])), 5);

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

          if (!isset($_POST['os_s_weight_title'])) $_POST['os_s_weight_title'] = $_ODATA['s_weights']['title'];
          $_POST['os_s_weight_title'] = number_format(max(0, (float)$_POST['os_s_weight_title']), 1, '.', '');

          if (!isset($_POST['os_s_weight_body'])) $_POST['os_s_weight_body'] = $_ODATA['s_weights']['body'];
          $_POST['os_s_weight_body'] = number_format(max(0, (float)$_POST['os_s_weight_body']), 1, '.', '');

          if (!isset($_POST['os_s_weight_keywords'])) $_POST['os_s_weight_keywords'] = $_ODATA['s_weights']['keywords'];
          $_POST['os_s_weight_keywords'] = number_format(max(0, (float)$_POST['os_s_weight_keywords']), 1, '.', '');

          if (!isset($_POST['os_s_weight_description'])) $_POST['os_s_weight_description'] = $_ODATA['s_weights']['description'];
          $_POST['os_s_weight_description'] = number_format(max(0, (float)$_POST['os_s_weight_description']), 1, '.', '');

          if (!isset($_POST['os_s_weight_url'])) $_POST['os_s_weight_url'] = $_ODATA['s_weights']['url'];
          $_POST['os_s_weight_url'] = number_format(max(0, (float)$_POST['os_s_weight_url']), 1, '.', '');

          if (!isset($_POST['os_s_weight_multi'])) $_POST['os_s_weight_multi'] = $_ODATA['s_weights']['multi'];
          $_POST['os_s_weight_multi'] = number_format(max(0, (float)$_POST['os_s_weight_multi']), 1, '.', '');

          if (!isset($_POST['os_s_weight_important'])) $_POST['os_s_weight_important'] = $_ODATA['s_weights']['important'];
          $_POST['os_s_weight_important'] = number_format(max(0, (float)$_POST['os_s_weight_important']), 1, '.', '');

          if (!isset($_POST['os_s_weight_pdflastmod'])) $_POST['os_s_weight_pdflastmod'] = $_ODATA['s_weights']['pdflastmod'];
          $_POST['os_s_weight_pdflastmod'] = number_format(max(0.1, (float)$_POST['os_s_weight_pdflastmod']), 1, '.', '');

          if (!isset($_POST['os_s_weight_css_value'])) $_POST['os_s_weight_css_value'] = $_ODATA['s_weights']['css_value'];
          $_POST['os_s_weight_css_value'] = number_format(max(0, (float)$_POST['os_s_weight_css_value']), 1, '.', '');

          OS_setValue('s_weights', array(
            'title' => $_POST['os_s_weight_title'],
            'body' => $_POST['os_s_weight_body'],
            'keywords' => $_POST['os_s_weight_keywords'],
            'description' => $_POST['os_s_weight_description'],
            'css_value' => $_POST['os_s_weight_css_value'],
            'url' => $_POST['os_s_weight_url'],
            'multi' => $_POST['os_s_weight_multi'],
            'important' => $_POST['os_s_weight_important'],
            'pdflastmod' => $_POST['os_s_weight_pdflastmod']
          ));

          if (isset($_POST['os_s_weight_css'])) {
            $_POST['os_s_weight_css'] = preg_replace(
              array('/[^\w\d\. #_:-]/', '/ {2,}/'),
              array('', ' '),
              trim($_POST['os_s_weight_css'])
            );
            OS_setValue('s_weight_css', substr($_POST['os_s_weight_css'], 0, 4095));
          }

          if (isset($_POST['os_s_charset'])) {
            $_POST['os_s_charset'] = preg_replace('/[^\w\d\.:_-]/', '', substr($_POST['os_s_charset'], 0, 63));
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

          if (isset($_POST['os_s_text_fragments']) && $_POST['os_s_text_fragments'] == '1') {
            $_POST['os_s_text_fragments'] = 1;
            if (strpos($_ODATA['s_result_template'], ' href="{{url}}" rel="noopener" ') === false) {
              OS_setValue('s_result_template', str_replace(
                ' href="{{url}}" ',
                ' href="{{url}}" rel="noopener" ',
                $_ODATA['s_result_template']
              ));
              $_SESSION['message'][] = <<<ORCINUS
Note: <code>rel="noopener"</code> has been added to the links in your result template.
<a href="https://developer.mozilla.org/en-US/docs/Web/Text_fragments#:~:text=noopener" rel="noopener" target="_blank">More info...</a>
ORCINUS;
            }
          } else {
            $_POST['os_s_text_fragments'] = 0;
            OS_setValue('s_result_template', str_replace(
              ' href="{{url}}" rel="noopener" ',
              ' href="{{url}}" ',
              $_ODATA['s_result_template']
            ));
          }
          OS_setValue('s_text_fragments', $_POST['os_s_text_fragments']);

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
            $_POST['os_s_limit_query_log'] = max(0, min(52, (int)$_POST['os_s_limit_query_log']));
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
          $crawldata = $_DDATA['pdo']->query(
            'SELECT `url`, `title`, `description`, `keywords`, `category`,
                    `content_mime`, `weighted`, `content`, `last_modified`, `priority`
              FROM `'.$_DDATA['tbprefix'].'crawldata`
                WHERE `flag_unlisted`=0 '.$_RDATA['s_show_orphans'].' AND
                      `url` LIKE \''.addslashes($_ODATA['jw_hostname']).'/%\';'
          );
          $err = $crawldata->errorInfo();
          if ($err[0] == '00000') {
            $crawldata = $crawldata->fetchAll();

            // If compression value is less than 100 then get a word
            // list frequency report from all indexed pages
            if ($_ODATA['jw_compression'] < 100) {

              $words = array();
              foreach ($crawldata as $key => $row) {
                $crawldata[$key]['words'] = array_unique(explode(' ', $row['content']));

                foreach ($crawldata[$key]['words'] as $index => $word) {
                  if ($word) {
                    if (empty($words[$word])) {
                      $words[$word] = 1;
                    } else $words[$word]++;
                  }
                }
              }

              // Use the word frequency report to create a filter of
              // words that are more common than the compression
              // threshold
              $compressionFilter = array();
              Foreach ($words as $word => $count)
                if (($count / count($crawldata)) * 100 >= $_ODATA['jw_compression'])
                  $compressionFilter[] = $word;
            }

            $repStr = '/^'.preg_quote($_ODATA['jw_hostname'], '/').'/';

            foreach ($crawldata as $key => $row) {

              // Use the compression filter to remove all of the most
              // common words from the content of this page
              if ($_ODATA['jw_compression'] < 100) {
                $crawldata[$key]['words'] = array_diff($row['words'], $compressionFilter);
                $crawldata[$key]['words'] = implode(' ', $crawldata[$key]['words']);
              } else $crawldata[$key]['words'] = $row['content'];

              // Remove the common domain from all URLs
              $crawldata[$key]['url'] = preg_replace($repStr, '', $row['url']);

              // Format non-.html filenames into .html ones
              if ($row['content_mime'] == 'text/html') {
                $rq = explode('?', $crawldata[$key]['url'], 2);
                if ($rq[0] == '' || $rq[0][strlen($rq[0]) - 1] == '/')
                  $rq[0] .= 'index.html';

                if (!preg_match('/\.html?$/', $rq[0]))
                  $rq[0] .= '.html';

                $crawldata[$key]['url'] = implode('?', $rq);
              }

              foreach ($crawldata[$key] as $prop => $value)
                if (is_string($value)) $crawldata[$key][$prop] = addslashes($value);
            }

            // Use this for dodgy character check on javascript output
            // [^\w\s()\[\]{};:.‖‘’‟„…/@©~®§⇔⇕⇒⇨⇩↪&\\^<>›×™*·,±_²°|≥!#$¢£+≤=•«%½»?`"'-]

            header('Content-type: text/javascript; charset='.strtolower($_ODATA['s_charset']));
            header('Content-disposition: attachment; filename="offline-search.js"');

            // Populate the offline javascript Mustache template
            require_once __DIR__.'/mustache/src/Mustache/Autoloader.php';
            Mustache_Autoloader::register();

            $output = new Mustache_Engine(array('entity_flags' => ENT_QUOTES));
            die(mb_convert_encoding(
              $output->render(
                file_get_contents(__DIR__.'/js/template.offline.js'),
                array(
                  'version' => $_ODATA['version'],
                  'date' => date('r'),
                  'sp_punct' => json_encode($_RDATA['sp_punct'], JSON_INVALID_UTF8_IGNORE),
                  's_latin' => json_encode($_RDATA['s_latin'], JSON_INVALID_UTF8_IGNORE),
                  's_filetypes' => json_encode($_RDATA['s_filetypes'], JSON_INVALID_UTF8_IGNORE),
                  's_category_list' => json_encode($_RDATA['s_category_list'], JSON_INVALID_UTF8_IGNORE),
                  'jw_compression' => $_ODATA['jw_compression'],
                  's_limit_query' => $_ODATA['s_limit_query'],
                  's_limit_terms' => $_ODATA['s_limit_terms'],
                  's_limit_term_length' => $_ODATA['s_limit_term_length'],
                  's_limit_matchtext' => $_ODATA['s_limit_matchtext'],
                  's_show_filetype_html' => $_ODATA['s_show_filetype_html'],
                  's_text_fragments' => $_ODATA['s_text_fragments'],
                  's_results_pagination' => $_ODATA['s_results_pagination'],
                  's_limit_results' => $_ODATA['s_limit_results'],
                  's_result_template' => json_encode(preg_replace('/\s{2,}/', ' ', $_ODATA['s_result_template']), JSON_INVALID_UTF8_IGNORE),
                  's_weights' => json_encode($_ODATA['s_weights'], JSON_INVALID_UTF8_IGNORE),
                  'os_crawldata' => $crawldata
                )
              ),
              'UTF-8',
              $_ODATA['s_charset']
            ));

          } else $_SESSION['error'][] = 'Error reading from the search result database: '.$err[2];
          break;


        // ***** Query Log >> Purge or Nuke multiple from Query Log
        case 'os_query_log_purge':
        case 'os_query_log_nuke':
          $_POST['os_queries_multi'] = $_POST['os_queries_multi'] ?? array();
          if (!is_array($_POST['os_queries_multi'])) $_POST['os_queries_multi'] = array();
          $_POST['os_query_log_hidden_query'] = '';
          $_POST['os_query_log_delete'] = ($_POST['os_submit'] == 'os_query_log_nuke') ? 'assoc' : 'query';


        // ***** Query Log >> Delete from Query Log
        case 'os_query_log_delete':
          $_POST['os_query_log_delete'] = $_POST['os_query_log_delete'] ?? '';
          $deleteQueryIP = $_DDATA['pdo']->prepare(
            'DELETE FROM `'.$_DDATA['tbprefix'].'query` WHERE `ip`=:ip;'
          );

          $_POST['os_query_log_hidden_query'] = $_POST['os_query_log_hidden_query'] ?? '';
          if (empty($_POST['os_queries_multi']) && $_POST['os_query_log_hidden_query'])
            $_POST['os_queries_multi'] = array($_POST['os_query_log_hidden_query']);
          $_POST['os_queries_multi'] = $_POST['os_queries_multi'] ?? array();

          switch ($_POST['os_query_log_delete']) {
            case 'query':
              if (!empty($_POST['os_queries_multi'])) {
                $deleteQuery = $_DDATA['pdo']->prepare(
                  'DELETE FROM `'.$_DDATA['tbprefix'].'query` WHERE `query`=:query;'
                );

                $tally = 0;
                foreach ($_POST['os_queries_multi'] as $query) {
                  $deleteQuery->execute(array('query' => $query));
                  $err = $deleteQuery->errorInfo();
                  if ($err[0] == '00000') {
                    $tally += $deleteQuery->rowCount();
                  } else $_SESSION['error'][] = 'Database error while deleting this query.';
                }
                if ($tally == 1) {
                  $_SESSION['message'][] = 'Deleted '.$tally.' query from the Query Log.';
                } else if ($tally) {
                  $_SESSION['message'][] = 'Deleted '.$tally.' queries from the Query Log.';
                } else $_SESSION['error'][] = 'Did not delete any queries from the Query Log.';
              } else $_SESSION['error'][] = 'No queries selected to delete.';
              break;

            case 'ip':
              if (!empty($_POST['os_query_log_hidden_ip'])) {
                if (preg_match('/^[\da-f:.]+$/i', $_POST['os_query_log_hidden_ip'])) {
                  $deleteQueryIP->execute(array('ip' => $_POST['os_query_log_hidden_ip']));
                  $err = $deleteQueryIP->errorInfo();
                  if ($err[0] == '00000') {
                    if ($deleteQueryIP->rowCount()) {
                      $_SESSION['message'][] = 'Queries from IP address \''.$_POST['os_query_log_hidden_ip'].'\' have been removed from the Query Log.';
                    } else $_SESSION['error'][] = 'IP address to delete not found in Query Log.';
                  } else $_SESSION['error'][] = 'Database error while deleting queries from this IP address.';
                } else $_SESSION['error'][] = 'Not a valid IP address.';
              } else $_SESSION['error'][] = 'No IP address selected to delete.';
              break;

            case 'assoc':
              if (!empty($_POST['os_queries_multi'])) {
                $selectQuery = $_DDATA['pdo']->prepare(
                  'SELECT DISTINCT `ip` FROM `'.$_DDATA['tbprefix'].'query` WHERE `query`=:query;'
                );

                $tally = 0;
                foreach ($_POST['os_queries_multi'] as $query) {
                  $selectQuery->execute(array('query' => $query));
                  $err = $selectQuery->errorInfo();
                  if ($err[0] == '00000') {
                    $select = $selectQuery->fetchAll();
                    if (count($select)) {
                      foreach ($select as $row) {
                        $deleteQueryIP->execute(array('ip' => $row['ip']));
                        $err = $deleteQueryIP->errorInfo();
                        if ($err[0] == '00000')
                          $tally += $deleteQueryIP->rowCount();
                      }
                    }
                  } else $_SESSION['error'][] = 'Database error while searching the Query Log.';
                }
                if ($tally == 1) {
                  $_SESSION['message'][] = 'Deleted '.$tally.' query from the Query Log.';
                } else if ($tally) {
                  $_SESSION['message'][] = 'Deleted '.$tally.' queries from the Query Log.';
                } else $_SESSION['error'][] = 'Did not delete any queries from the Query Log.';
              } else $_SESSION['error'][] = 'No queries selected to delete.';
              break;

            default:
              $_SESSION['error'][] = 'Invalid command.';
          }
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
        if (in_array($_POST['os_index_hidden_pagination'], $_RDATA['admin_pagination_options'], true)) {
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
        if (in_array($_POST['os_index_new_filter_status'], $_RDATA['index_status_list'], true)) {
          $_SESSION['index_filter_status'] = $_POST['os_index_new_filter_status'];
          $_SESSION['index_page'] = 1;
        }

        header('Location: '.$_SERVER['REQUEST_URI']);
        exit();
      }

      // Query Log row display limit
      if (isset($_POST['os_admin_query_log_display'])) {
        $_POST['os_admin_query_log_display'] = (int)$_POST['os_admin_query_log_display'];
        if (in_array($_POST['os_admin_query_log_display'], $_RDATA['admin_query_log_display_options'], true))
          OS_setValue('admin_query_log_display', $_POST['os_admin_query_log_display']);

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

      if ($_RDATA['page_index_total_rows']) {

        // ***** Select rows to populate the Page Index table
        $indexRows = $_DDATA['pdo']->prepare(
          'SELECT SQL_CALC_FOUND_ROWS
              `url`, `title`, `category`, `content_checksum`,
              `status`, `flag_unlisted`, `flag_updated`, `priority`
            FROM `'.$_DDATA['tbprefix'].'crawldata`
              WHERE (:text1=\'\' OR `url` LIKE :text2) AND
                    (:category1=\'\' OR `category`=:category2) AND
                    (:status1=\'\' OR `status`=:status2) AND
                    (:flag_unlisted1=\'any\' OR `flag_unlisted`=:flag_unlisted2) AND
                    (:flag_updated1=\'any\' OR `flag_updated`=:flag_updated2)
                ORDER BY `url_sort`
                  LIMIT :offset, :pagination;'
        );

        $text = trim($_SESSION['index_filter_text']);

        $category = ($_SESSION['index_filter_category'] != '<none>') ? $_SESSION['index_filter_category'] : '';

        if ($_SESSION['index_filter_status'] == 'OK' ||
            $_SESSION['index_filter_status'] == 'Orphan') {
          $status = $_SESSION['index_filter_status'];
        } else $status = '';

        $unlisted = ($_SESSION['index_filter_status'] == 'Unlisted') ? 1 : 'any';
        $updated = ($_SESSION['index_filter_status'] == 'Updated') ? 1 : 'any';

        $_RDATA['page_index_offset'] = ($_SESSION['index_page'] - 1) * $_ODATA['admin_index_pagination'];

        $indexRows->execute(array(
          'text1' => $text,
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
          $_RDATA['page_index_charsets'][$row['content_charset']] = $row['num'];
        }
      } else $_SESSION['error'][] = 'Could not read charset counts from search database.';

      // Search Database MIME-types
      $mimetypes = $_DDATA['pdo']->query(
        'SELECT `content_mime`, COUNT(*) as `num`
          FROM `'.$_DDATA['tbprefix'].'crawldata`
            GROUP BY `content_mime` ORDER BY `num` DESC;'
      );
      $err = $mimetypes->errorInfo();
      if ($err[0] == '00000') {
        $mimetypes = $mimetypes->fetchAll();
        foreach ($mimetypes as $row) {
          if (!$row['content_mime']) $row['content_mime'] = '<none>';
          $_RDATA['page_index_mime_types'][$row['content_mime']] = $row['num'];
        }
      } else $_SESSION['error'][] = 'Could not read charset counts from search database.';
      break;

    case 'stats':
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
          $_RDATA['q_median_results'] = (count($median) & 1) ? $median[$index]['results'] : ($median[$index - 1]['results'] + $median[$index]['results']) / 2;
        }
      } else $_SESSION['error'][] = 'Could not read result counts from query log.';

      if ($_GEOIP2) {
        // Check if the geolocation database is more than a year old
        if ((time() - filemtime(__DIR__.'/geoip2/GeoLite2-Country.mmdb')) > 31536000)
          $_SESSION['message'][] = 'Your geolocation database is out of date. You should consider getting the latest GeoLite2-Country.mmdb from Maxmind.com.';

        $_RDATA['geo_loc_count'] = array();
        $select = $_DDATA['pdo']->query(
          'SELECT `ip`, COUNT(*) AS `ips`, `geo`
            FROM `'.$_DDATA['tbprefix'].'query`
              GROUP BY `ip`;'
        );
        $err = $select->errorInfo();
        if ($err[0] == '00000') {
          foreach ($select as $row) {

            // If we found a valid geolocation for this IP, then
            // add the count to the tally
            if ($geo = OS_getGeo($row)) {
              if (empty($_RDATA['geo_loc_count'][$geo->isoCode])) {
                $_RDATA['geo_loc_count'][$geo->isoCode] = $row['ips'];
              } else $_RDATA['geo_loc_count'][$geo->isoCode] += $row['ips'];

            // If we didn't find a valid geolocation, add these IPs
            // to the "Unknown" pile
            } else {
              if (empty($_RDATA['geo_loc_count']['unk'])) {
                $_RDATA['geo_loc_count']['unk'] = $row['ips'];
              } else $_RDATA['geo_loc_count']['unk'] += $row['ips'];
            }
          }
          arsort($_RDATA['geo_loc_count']);
        }
      }

      $weekCount = min($_ODATA['s_limit_query_log'], ceil($_RDATA['s_hours_since_oldest_hit'] / 168));

      $select = $_DDATA['pdo']->query('SELECT COUNT(*) as `searches`, DAYNAME(FROM_UNIXTIME(`stamp`)) as `weekday` FROM `'.$_DDATA['tbprefix'].'query` GROUP BY `weekday`;')->fetchAll();
      $_RDATA['day_walker'] = array('Sun' => 0, 'Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0);
      foreach ($select as $day)
        $_RDATA['day_walker'][substr($day['weekday'], 0, 3)] = round($day['searches'] / $weekCount, 1);

      $select = $_DDATA['pdo']->query('SELECT COUNT(*) as `searches`, `stamp` FROM `'.$_DDATA['tbprefix'].'query` GROUP BY HOUR(FROM_UNIXTIME(`stamp`));')->fetchAll();
      for ($x = 0, $days = array(), $_RDATA['hour_walker'] = array(); $x < 24; $x++)
        $_RDATA['hour_walker'][str_pad((string)$x, 2, '0', STR_PAD_LEFT).':00'] = 0;
      foreach ($select as $hour)
        $_RDATA['hour_walker'][date('H:00', $hour['stamp'])] = round($hour['searches'] / ($weekCount * 7), 1);
      break;

    case 'queries':
      $_RDATA['query_log_rows'] = array();
      $_RDATA['query_log_found_rows'] = false;

      $queries = $_DDATA['pdo']->query(
        'SELECT `t`.`query`, `t`.`results`, `t`.`ip`,
                REGEXP_REPLACE(`t`.`query`, \'^[[:punct:]]+\', \'\') AS `alpha`,
                `s`.`hits`, `s`.`ipuni`, `s`.`last_hit`
           FROM `'.$_DDATA['tbprefix'].'query` AS `t`
             INNER JOIN (
               SELECT `query`, COUNT(DISTINCT(`ip`)) AS `ipuni`,
                      COUNT(`query`) AS `hits`, MAX(`stamp`) AS `last_hit`
                 FROM `'.$_DDATA['tbprefix'].'query`
                   GROUP BY `query`
             ) AS `s` ON `s`.`query`=`t`.`query` AND `s`.`last_hit`=`t`.`stamp`
               GROUP BY `t`.`query`
                 ORDER BY `alpha` ASC;'
      );
      $err = $queries->errorInfo();
      if ($err[0] == '00000') {
        $_RDATA['query_log_rows'] = $queries->fetchAll();
        $_RDATA['query_log_found_rows'] = count($_RDATA['query_log_rows']);

        if (count($_RDATA['query_log_rows'])) {
          $x = 0;

          // Add the `alpha` sort order as an index
          foreach ($_RDATA['query_log_rows'] as $key => $query)
            $_RDATA['query_log_rows'][$key]['rownum'] = $x++;

          // On first load, sort list by # of hits
          usort($_RDATA['query_log_rows'], function($a, $b) {
            return $b['hits'] - $a['hits'];
          });

          // Limit the queries displayed to just the top X
          if ($_ODATA['admin_query_log_display'])
            $_RDATA['query_log_rows'] = array_slice($_RDATA['query_log_rows'], 0, (int)$_ODATA['admin_query_log_display']);

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

  <title>Orcinus Site Search - <?php
    echo $_RDATA['admin_pages'][$_SESSION['admin_page']];
  ?></title>
</head>
<body class="pt-5">
  <nav class="navbar fixed-top navbar-expand-md bg-body-secondary">
    <div class="container-fluid">
      <a href="https://greywyvern.com/orcinus/" class="navbar-brand flex-grow-1 flex-md-grow-0 mb-1"
        target="_blank" title="Orcinus Search v<?php echo $_ODATA['version']; ?>">Orcinus</a><?php
      if ($_SESSION['admin_username']) { ?> 
        <div class="flex-grow-0 order-md-last">
          <var class="me-1" title="Orcinus Site Search - Version: <?php echo $_ODATA['version']; ?>">
             v<?php echo $_ODATA['version']; ?> 
          </var>
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
          <ul class="navbar-nav me-auto mt-2 mt-md-0"><?php
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
            echo $error; ?> 
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        </div><?php
      }
      while ($message = array_shift($_SESSION['message'])) { ?> 
        <div class="col-10 col-sm-8 col-md-7">
          <div class="alert alert-info alert-dismissible fade show mx-auto" role="alert"><?php
            echo $message; ?> 
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
                          <strong class="pe-2">Most Recent Crawl:</strong>
                          <span class="flex-grow-1 text-end">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#crawlerModal" data-bs-crawl="log">
                              View Log
                            </button>
                          </span>
                        </label>
                        <div><?php
                          if (!$_ODATA['sp_crawling']) {
                            OS_countUp(($_ODATA['sp_time_end']) ? $_ODATA['sp_time_end'] : time(), 'os_countup_time_end');
                            ?> ago<?php
                          } else { ?> 
                            <em>Currently crawling...</em><?php
                          }
                        ?></div><?php
                        if (!$_ODATA['sp_crawling'] && $_ODATA['sp_time_end'] != $_ODATA['sp_time_end_success']) { ?> 
                          <p class="data-text text-danger">
                            <strong>Warning:</strong> The previous crawl did not complete successfully.
                            Please check the crawl log for more details.
                          </p><?php
                        } ?> 
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Crawl Time:</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_time_last"><?php
                            if ($_ODATA['sp_crawling']) {
                              OS_countUp($_ODATA['sp_time_start'], 'os_countup_time_crawl');
                            } else {
                              if ($_ODATA['sp_progress'][2]) { ?> 
                                <img src="img/warning.svg" alt="Notice" class="align-middle svg-icon mb-1 me-1"
                                  data-bs-toggle="tooltip" data-bs-placement="top" title="The most recent crawl was interrupted and resumed. Values displayed may not reflect the actual crawl data."><?php
                              }
                              OS_countUp($_ODATA['sp_time_last'], 'os_countup_time_crawl');
                            }
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Data Transferred:</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_data_transferred"><?php
                            echo OS_readSize($_ODATA['sp_data_transferred'], true);
                          ?></var>
                        </label>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Data Stored:
                            <img src="img/help.svg" alt="Information" class="align-middle svg-icon mb-1"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="The amount of data stored by new or updated pages.">
                          </strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_data_stored"><?php
                            if (!$_ODATA['sp_crawling']) {
                              if ($_ODATA['sp_data_transferred']) { ?> 
                                <small data-bs-toggle="tooltip" data-bs-placement="bottom" title="Efficiency percentage of data stored vs. data downloaded"><?php
                                  echo '('.round(($_ODATA['sp_data_stored'] / $_ODATA['sp_data_transferred']) * 100, 1).'%)';
                                ?></small> <?php
                              }
                              echo OS_readSize($_ODATA['sp_data_stored'], true);
                            } else echo '0';
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Links Crawled:</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_links_crawled"><?php
                            if ($_ODATA['sp_crawling']) {
                              echo $_ODATA['sp_progress'][0].' / '.$_ODATA['sp_progress'][1];
                            } else echo $_ODATA['sp_progress'][0];
                          ?></var>
                        </label>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Pages Stored:</strong>
                          <var class="flex-grow-1 text-end" id="os_crawl_pages_stored"><?php
                            if (!$_ODATA['sp_crawling']) {
                              if ($_ODATA['sp_progress'][0]) { ?> 
                                <small data-bs-toggle="tooltip" data-bs-placement="bottom" title="Efficiency percentage of pages stored vs. links crawled"><?php
                                  echo '('.round(($_ODATA['sp_pages_stored'] / $_ODATA['sp_progress'][0]) * 100, 1).'%)';
                                ?></small> <?php
                              }
                              echo $_ODATA['sp_pages_stored'];
                            } else echo '0';
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
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Maximum number of links to crawl away from your starting URLs. Pages deeper than this value will be ignored, but the crawler will continue scanning links in the queue.">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">File Size:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_sp_limit_filesize" value="<?php echo $_ODATA['sp_limit_filesize']; ?>" min="0" max="65535" step="1" class="form-control d-inline-block me-1"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Files larger than this size will not be downloaded, stored nor scanned for links."> <abbr title="kibibytes">kiB</abbr>
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
                          putting groups of pages into different categories. You can set page categories
                          from the <a href="?page=index">Page Index</a>.
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
                            <textarea rows="5" cols="30" name="os_sp_ignore_css" class="form-control"
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
            <header class="col-6 col-xl-5 col-xxl-4 mb-2">
              <h2>Page Index</h2>
            </header>
            <div class="col-6 col-xl-5 col-xxl-4 mb-2 text-end text-nowrap">
              <button type="button" class="btn btn-primary" id="os_page_index_download" title="Download Page Index"<?php
                if (!$_RDATA['page_index_total_rows']) echo ' disabled="disabled"'; ?>>Download</button>
            </div><?php

            // If there are *any* rows in the database
            if ($_RDATA['page_index_total_rows']) {

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
                          for ($x = 1; $x <= $_RDATA['index_pages']; $x++) {
                            if ($x != $_SESSION['index_page']) { ?>
                              <li class="page-item"><a class="page-link" href="?ipage=<?php echo $x; ?>"><?php echo $x; ?></a></li><?php
                            } else { ?> 
                              <li class="page-item disabled"><span class="page-link"><strong><?php echo $x; ?></strong></span></li><?php
                            }
                          } ?> 
                          <li class="page-item d-none border border-1" id="os_pagination_jump">
                            <label class="text-nowrap ps-3 pe-3 h-100 d-flex align-items-center">
                              <span class="pe-1">Page:</span>
                              <select name="os_index_pagination_page_select" class="form-select form-select-sm d-inline-block w-auto" title="Jump to page"><?php
                                for ($x = 1; $x <= $_RDATA['index_pages']; $x++) { ?> 
                                  <option value="<?php echo $x; ?>"<?php 
                                    if ($x == $_SESSION['index_page']) echo ' selected="selected"';
                                    ?>><?php echo $x; ?></option><?php
                                }
                              ?></select>
                            </label>
                          </li><?php
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
                      <table id="os_index_table" class="table table-striped w-100 mb-0 <?php
                        if ($_SESSION['index_show_page_titles'] == 'on') echo 'show-page-titles'; ?>">
                        <thead>
                          <tr>
                            <th colspan="6" class="bg-black text-white">
                              <div class="row">
                                <div class="col-md-6 d-flex mb-2 mb-md-0">
                                  <h3 class="d-flex flex-column justify-content-center mb-0 pe-2">Filters:</h3>
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
                            <th class="fs-5 text-nowrap" scope="col">URL
                              <input type="checkbox" class="form-check-input fs-6 ms-1 mt-2" id="os_show_page_titles"
                                data-bs-toggle="tooltip" data-bs-placement="top" title="Show Page Titles">
                            </th>
                            <td class="text-center w-100">
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
                          if (count($_ODATA['sp_domains']) == 1)
                            $repStr = '/^'.preg_quote(key($_ODATA['sp_domains']), '/').'/';

                          if (count($_RDATA['page_index_rows'])) {
                            foreach ($_RDATA['page_index_rows'] as $key => $row) { ?> 
                              <tr class="lh-sm">
                                <td class="align-middle pe-0">
                                  <input type="checkbox" data-index="<?php echo $key; ?>" name="os_index_pages[]" value="<?php echo base64_encode($row['content_checksum']); ?>" class="form-check-input mt-1">
                                </td>
                                <td colspan="2" class="text-nowrap">
                                  <div class="d-inline-block align-middle mw-90">
                                    <div class="w-100 d-table table-fixed">
                                      <div class="w-100 d-table-cell overflow-hidden text-ellipsis"
                                        data-page-title="<?php echo htmlspecialchars($row['title']); ?>">
                                        <a href="<?php echo htmlspecialchars($row['url']); ?>" title="<?php
                                          echo htmlspecialchars($row['url']); ?>" target="_blank" class="align-middle<?php
                                          if ($row['flag_updated']) echo ' fw-bold'; ?>"><?php
                                          if (count($_ODATA['sp_domains']) == 1) {
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
                                  <td class="d-none d-md-table-cell text-center align-middle"><?php
                                    echo htmlspecialchars($row['category']);
                                  ?></td><?php
                                } ?> 
                                <td class="text-center text-nowrap align-middle">
                                  <span><?php echo htmlspecialchars($row['status']); ?></span><?php
                                  if ($row['flag_unlisted']) { ?> 
                                    <img src="img/hidden.svg" alt="Unlisted" class="align-middle svg-icon mb-1"
                                      data-bs-toggle="tooltip" data-bs-placement="top" title="Unlisted: This page will be crawled for content and links as normal, but will not show up in any search results."><?php
                                  }
                                ?></td>
                                <td class="d-none d-md-table-cell text-center align-middle"><?php
                                  echo htmlspecialchars($row['priority']);
                                ?></td>
                              </tr><?php
                            }

                          // No pages to list with these filters
                          } else { ?> 
                            <tr>
                              <td colspan="100%">
                                <p class="text-center m-0">
                                  No pages to list.
                                </p>
                              </td>
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

            <div class="col-sm-10 col-md-8 col-lg-5 col-xl-4 col-xxl-3 order-lg-2">
              <div class="shadow rounded-3 mb-3 overflow-visible">
                <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Search Data</h3>
                <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                  <ul class="list-group">
                    <li class="list-group-item">
                      <label class="d-flex lh-lg w-100">
                        <strong class="pe-2">Pages in Database:</strong>
                        <var class="text-end flex-grow-1 text-nowrap"><?php
                          echo $_RDATA['s_pages_stored'];
                        ?></var>
                      </label><?php
                      if ($_RDATA['s_pages_stored']) { ?> 
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Searchable Pages:
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
                          <strong class="pe-2">MIME-types:</strong>
                          <ol class="list-group list-group-flush flex-grow-1"><?php
                            foreach ($_RDATA['page_index_mime_types'] as $mimetype => $value) { ?> 
                              <li class="list-group-item text-end p-0 border-0">
                                <strong><?php echo htmlspecialchars($mimetype); ?>:</strong>
                                <var title="<?php echo $value; ?> pages"><?php
                                  echo round(($value / $_RDATA['page_index_total_rows']) * 100, 1);
                                ?>%</var>
                              </li><?php
                            } ?> 
                          </ol>
                        </label>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex w-100">
                          <strong class="pe-2">Encodings:</strong>
                          <ol class="list-group list-group-flush flex-grow-1"><?php
                            foreach ($_RDATA['page_index_charsets'] as $encoding => $value) { ?> 
                              <li class="list-group-item text-end p-0 border-0">
                                <strong><?php echo htmlspecialchars($encoding); ?>:</strong>
                                <var title="<?php echo $value; ?> pages"><?php
                                  echo round(($value / $_RDATA['page_index_total_rows']) * 100, 1);
                                ?>%</var>
                              </li><?php
                            } ?> 
                          </ol>
                        </label><?php
                      } ?> 
                    </li>
                  </ul>
                </div>
              </div>

              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post"
                class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Query Log &amp; Cache</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <h4>Query Log</h4>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Current Query Log Size:</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo OS_readSize($_RDATA['s_query_log_size'] - $_RDATA['s_cache_size'], true);
                          ?></var>
                        </label>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Store Query Log for:</strong>
                          <span class="flex-grow-1 text-end text-nowrap">
                            <input type="number" name="os_s_limit_query_log" value="<?php echo $_ODATA['s_limit_query_log']; ?>" min="0" max="52" step="1" class="form-control d-inline-block ms-1 me-1" aria-labelledby="os_s_limit_query_log_text"
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="To disable the query log, set this value to zero (0). Disabling the query log will also disable search result caching."> week(s)
                          </span>
                        </label>
                        <p id="os_s_limit_query_log_text" class="form-text">
                          The query log is a rolling log of searches on which the
                          <a href="?page=stats">search statistics</a> are based. Longer query log
                          periods will give more accurate statistics, but also require more
                          database space. (max: 52 weeks)
                        </p>
                      </li>
                      <li class="list-group-item">
                        <h4>Search Result Cache</h4>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Currently Cached Searches:</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo $_RDATA['s_cached_searches'];
                          ?></var>
                        </label>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Current Cache Size:
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
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Search Database Size:</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo OS_readSize($_ODATA['sp_crawldata_size'], true);
                          ?></var>
                        </label>
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">PHP <code>memory_limit</code>:
                            <img src="img/help.svg" alt="Information" class="align-middle svg-icon mb-1"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="If your search database is large, you may need to increase your PHP memory_limit to successfully export an offline Javascript version. See: config.ini.php">
                          </strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            // Decipher the PHP memory_limit
                            $memory_limit = ini_get('memory_limit');
                            if ($memory_limit != -1) {
                              preg_match('/(\d+)([KMG]?)/i', trim((string)$memory_limit), $match);
                              if (count($match)) {
                                switch (strtoupper($match[2])) {
                                  case 'G': $memory_limit = OS_readSize((int)$match[1] * 1073741824, true); break;
                                  case 'M': $memory_limit = OS_readSize((int)$match[1] * 1048576, true); break;
                                  case 'K': $memory_limit = OS_readSize((int)$match[1] * 1024, true); break;
                                  default: $memory_limit = OS_readSize((int)$match[1], true);
                                }
                              } else $memory_limit = 'Unknown';
                            } else $memory_limit = 'No limit';
                            echo $memory_limit;
                          ?></var>
                        </label>
                      </li>
                      <li class="list-group-item"><?php
                        if (count($_ODATA['sp_domains']) > 1) { ?> 
                          <label class="d-flex lh-lg w-100">
                            <strong class="pe-2">Domain:</strong>
                            <span class="text-end flex-grow-1 text-nowrap">
                              <select name="os_jw_hostname" class="form-select d-inline-block"><?php
                                foreach ($_ODATA['sp_domains'] as $domain => $count) { ?> 
                                  <option value="<?php echo $domain; ?>"<?php
                                    if ($_ODATA['jw_hostname'] == $domain) echo ' selected="selected"'; ?>><?php
                                    echo $domain, ' (', $count, ')';
                                  ?></option><?php
                                } ?> 
                              </select>
                            </span>
                          </label><?php
                        } ?> 
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">Compression Level:</strong>
                          <var class="text-end flex-grow-1 text-nowrap">
                            <input type="number" name="os_jw_compression" value="<?php echo $_ODATA['jw_compression']; ?>" min="0" max="100" step="1" class="form-control d-inline-block"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Apply text content compression to the output. 100 is no compression, while 0 is maximum compression.">
                          </var>
                        </label>
                      </li>
                    </ul>
                    <div class="text-center">
                      <button type="submit" name="os_submit" value="os_jw_config" class="btn btn-primary">Save Changes</button>
                      <button type="submit" name="os_submit" value="os_jw_write" class="btn btn-primary" title="Download Javascript File"<?php
                        if (!$_RDATA['page_index_total_rows']) {
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
                              data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms beyond this limit in a single query will be ignored. &quot;Phrase searches&quot; count as a single term.">
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
                                <input type="number" name="os_s_weight_title" value="<?php echo $_ODATA['s_weights']['title']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page title. Default: 1.3">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Body Text:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_body" value="<?php echo $_ODATA['s_weights']['body']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page body text. Default: 0.5">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Keywords:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_keywords" value="<?php echo $_ODATA['s_weights']['keywords']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page keywords meta information. Default: 2.1">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Description:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_description" value="<?php echo $_ODATA['s_weights']['description']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page description meta information. Default: 0.4">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">In URL:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_url" value="<?php echo $_ODATA['s_weights']['url']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Search terms found in the page URL. Default: 0.2">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">CSS Selector:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_css_value" value="<?php echo $_ODATA['s_weights']['css_value']; ?>" min="0" max="100" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="An extra additive weight score will be added to content found in elements matching the CSS selectors below. Default: 1.9">
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
                                data-bs-toggle="tooltip" data-bs-placement="top" title="These values MULTIPLY the final relevance score for a search result.">
                            </h5>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Multi-term:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_multi" value="<?php echo $_ODATA['s_weights']['multi']; ?>" min="0" max="10" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Applied if a result matches more than one of the given search terms, for every search term match beyond the first. Default: 2.5">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">Important (+):</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_important" value="<?php echo $_ODATA['s_weights']['important']; ?>" min="0" max="10" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Applied for search terms the user has marked as '+important' and &quot;phrase matches&quot;. Default: 1.5">
                              </span>
                            </label>
                            <label class="d-flex lh-lg w-100 mb-2">
                              <strong class="pe-2">PDF Last Modified:</strong>
                              <span class="flex-grow-1 text-end text-nowrap">
                                <input type="number" name="os_s_weight_pdflastmod" value="<?php echo $_ODATA['s_weights']['pdflastmod']; ?>" min="0.1" max="10" step="0.1" class="form-control d-inline-block"
                                  data-bs-toggle="tooltip" data-bs-placement="bottom" title="Attempt to rank PDFs by examining the ages of their content. Default: 1.0">
                              </span>
                            </label>
                            <p id="os_s_weight_pdflastmod_text" class="form-text">
                              The <em>PDF Last Modified</em> multiplier lets you rank older PDFs lower in
                              search results based on years of age. <em>eg</em>. a value of 0.5 means a
                              year-old PDF has its relevance value halved. Minimum value: 0.1
                            </p>
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
                              <li class="form-check mb-1">
                                <input type="checkbox" name="os_s_text_fragments" id="os_s_text_fragments" value="1" class="form-check-input"
                                  data-bs-toggle="tooltip" data-bs-placement="top" title="Use special links that try to highlight the first match of each term on the target page."<?php
                                  if ($_ODATA['s_text_fragments']) echo ' checked="checked"'; ?>>
                                <label for="os_s_text_fragments" class="form-check-label">
                                  Use <a href="https://developer.mozilla.org/en-US/docs/Web/Text_fragments#:~:text=Text%20fragment" rel="noopener" target="_blank">text fragments</a> in result links
                                </label>
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
         * Search Statistics *************************************** */
        case 'stats': ?> 
          <section class="row justify-content-center">
            <header class="col-sm-10 col-md-8 col-lg-12 col-xl-10 col-xxl-8 mb-2">
              <h2>Search Statistics</h2>
            </header>
            <div class="w-100"></div>

            <div class="col-sm-10 col-md-8 col-lg-5 col-xl-4 col-xxl-3"><?php
              if ($_ODATA['s_limit_query_log']) { ?> 
                <div class="shadow rounded-3 mb-3 overflow-visible">
                  <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">General Statistics</h3>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group"><?php
                      if ($_RDATA['s_hits_per_hour']) { ?> 
                        <li class="list-group-item"><?php
                          if ($_RDATA['s_hours_since_oldest_hit'] >= 1) { ?> 
                            <label class="d-flex lh-lg w-100">
                              <strong class="pe-2">Searches per Hour:</strong>
                              <var class="text-end flex-grow-1 text-nowrap"><?php
                                echo round($_RDATA['s_hits_per_hour'], 1);
                              ?></var>
                            </label><?php
                          }
                          if ($_RDATA['s_hours_since_oldest_hit'] >= 24) { ?> 
                            <label class="d-flex lh-lg w-100">
                              <strong class="pe-2">Searches per Day:</strong>
                              <var class="text-end flex-grow-1 text-nowrap"><?php
                                echo round($_RDATA['s_hits_per_hour'] * 24, 1);
                              ?></var>
                            </label><?php
                          }
                          if ($_RDATA['s_hours_since_oldest_hit'] >= 168) { ?> 
                            <label class="d-flex lh-lg w-100">
                              <strong class="pe-2">Searches per Week:</strong>
                              <var class="text-end flex-grow-1 text-nowrap"><?php
                                echo round($_RDATA['s_hits_per_hour'] * 24 * 7, 1);
                              ?></var>
                            </label><?php
                          } ?> 
                        </li>
                        <li class="list-group-item">
                          <label class="d-flex lh-lg w-100">
                            <strong class="pe-2">Average Search Results per Query:</strong>
                            <var class="text-end flex-grow-1 text-nowrap"><?php
                              echo round($_RDATA['q_average_results'], 1);
                            ?></var>
                          </label>
                          <label class="d-flex lh-lg w-100">
                            <strong class="pe-2">Median Search Results per Query:</strong>
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
                    </ul>
                  </div>
                </div><?php
              }

              if ($_GEOIP2) { ?> 
                <div class="shadow rounded-3 mb-3 overflow-visible">
                  <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Query Geolocation</h3>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group">
                      <li class="list-group-item">
                        <table class="table">
                          <thead>
                            <tr>
                              <th scope="col" class="w-75">Location</th>
                              <th scope="col" colspan="2" class="text-center">Searches</th>
                            </tr>
                          </thead>
                          <tbody><?php
                            $top10 = 0;
                            foreach ($_RDATA['geo_loc_count'] as $iso => $searches) {
                              if ($top10++ < 10 || $iso == array_key_last($_RDATA['geo_loc_count'])) { ?> 
                                <tr>
                                  <th scope="row"><?php
                                    if (file_exists(__DIR__.'/img/flags/'.strtolower($iso).'.png')) { ?> 
                                      <img src="img/flags/<?php echo strtolower($iso); ?>.png" alt="<?php echo strtoupper($iso); ?>" title="<?php echo $_RDATA['geocache'][$iso]->names['en']; ?>" class="svg-icon-flag"><?php
                                    } else { ?>
                                      <img src="img/help.svg" alt="?" title="<?php echo $_RDATA['geocache'][$iso]->names['en']; ?>" class="svg-icon"><?php
                                    } ?> 
                                    <span class="align-middle"><?php echo $_RDATA['geocache'][$iso]->names['en']; ?></span>
                                  </th>
                                  <td class="text-end"><?php echo $searches; ?></td>
                                  <td class="text-center"><small>(<?php echo round($searches / array_sum($_RDATA['geo_loc_count']) * 100, 1); ?>%)</small></td>
                                </tr><?php
                              } else {
                                $capture = false; $hits = 0;
                                foreach ($_RDATA['geo_loc_count'] as $iso2 => $searches2) {
                                  if ($iso2 == $iso) $capture = true;
                                  if ($capture) $hits += $searches2;
                                } ?> 
                                <tr>
                                  <th scope="row">
                                    <img src="img/help.svg" alt="?" title="Other" class="svg-icon">
                                    <span class="align-middle">Other</span>
                                  </th>
                                  <td class="text-end"><?php echo $hits; ?></td>
                                  <td class="text-center"><small>(<?php echo round($hits / array_sum($_RDATA['geo_loc_count']) * 100, 1); ?>%)</small></td>
                                </tr><?php
                                break;
                              }
                            } ?> 
                          </tbody>
                        </table>
                      </li>
                      <li class="list-group-item">
                        <label class="d-flex lh-lg w-100">
                          <strong class="pe-2">GeoIP2 Version (<a href="https://github.com/maxmind/GeoIP2-php/releases" title="See latest releases">geoip2.phar</a>):</strong>
                          <var class="text-end flex-grow-1 text-nowrap"><?php
                            echo $_RDATA['geoip2_version'];
                          ?></var>
                        </label>
                      </li>
                    </ul>
                  </div>
                </div><?php
              } ?> 
            </div>

            <div class="col-sm-10 col-md-8 col-lg-7 col-xl-6 col-xxl-5">
              <div class="shadow rounded-3 mb-3 overflow-visible">
                <fieldset>
                  <legend class="mb-0">
                    <h3 class="bg-black rounded-top-3 text-white p-2 mb-0">Graphical Statistics</h3>
                  </legend>
                  <div class="p-2 border border-1 border-secondary-subtle rounded-bottom-3">
                    <ul class="list-group mb-2">
                      <li class="list-group-item">
                        <h4>Searches by Day of Week</h4>
                        <table class="bar-graph d-flex align-items-end position-relative w-100 gap-1 pt-4 mb-5">
                          <tbody class="flex-fill d-flex gap-3" style="height:14em;"><?php
                            foreach ($_RDATA['day_walker'] as $day => $value) { ?> 
                              <tr class="flex-fill d-flex flex-column justify-content-end<?php
                                if ($day == array_key_first($_RDATA['day_walker'])) echo ' ps-2';
                                if ($day == array_key_last($_RDATA['day_walker'])) echo ' pe-2'; ?>">
                                <th class="position-relative p-0">
                                  <span class="position-absolute top-0 start-50 translate-middle-x"><?php echo $day; ?></span>
                                </th>
                                <td class="order-first position-relative bg-secondary bg-gradient p-0" data-value="<?php echo $value; ?>" title="<?php echo $day; ?>">
                                  <small class="position-absolute bottom-100 start-50 translate-middle-x"><?php
                                    if ($value > 0) echo $value;
                                  ?></small>
                                </td>
                              </tr><?php
                            } ?> 
                          </tbody>
                        </table>
                      </li>
                      <li class="list-group-item">
                        <h4>Searches by Time of Day</h4>
                        <table class="bar-graph d-flex align-items-end position-relative w-100 gap-1 pt-4 mb-5">
                          <tbody class="flex-fill d-flex gap-1" style="height:14em;"><?php
                            foreach ($_RDATA['hour_walker'] as $hour => $value) { ?> 
                              <tr class="flex-fill d-flex flex-column justify-content-end<?php
                                if ($hour == array_key_first($_RDATA['hour_walker'])) echo ' ps-2';
                                if ($hour == array_key_last($_RDATA['hour_walker'])) echo ' pe-2'; ?>">
                                <th class="position-relative p-0">
                                  <time class="position-absolute top-0 start-50 translate-middle-x <?php
                                    echo (!((int)$hour % 6) || $hour == array_key_last($_RDATA['hour_walker'])) ? 'd-block' : 'd-none';
                                    ?>"><?php echo $hour; ?></time>
                                </th>
                                <td class="order-first position-relative bg-secondary bg-gradient p-0" data-value="<?php echo $value; ?>" title="<?php echo $hour; ?>">
                                  <small class="position-absolute bottom-100 start-50 rotate-neg-80"><?php
                                    if ($value > 0) echo $value;
                                  ?></small>
                                </td>
                              </tr><?php
                            } ?> 
                          </tbody>
                        </table>
                      </li>
                    </ul>
                  </div>
                </fieldset>
              </div>
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
                if (!count($_RDATA['query_log_rows'])) echo ' disabled="disabled"'; ?>>Download</button>
            </div><?php

            if (count($_RDATA['query_log_rows'])) { ?> 
              <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="post" class="col-xl-10 col-xxl-8">
                <fieldset class="rounded-3 border border-1 border-secondary-subtle shadow border-bottom-0 mb-3 overflow-hidden">
                  <table class="table table-striped w-100 mb-0">
                    <thead id="os_queries_thead">
                      <tr class="text-nowrap user-select-none">
                        <th class="fs-5" scope="col">
                          <span role="button" id="os_queries_query">Query</span>
                          <img src="img/arrow-down.svg" alt="Sort" title="Sort order" class="align-middle svg-icon-sm mb-1">
                        </th>
                        <td class="p-1">
                          <label>
                            <span class="d-none d-md-inline">Show top:</span>
                            <select name="os_admin_query_log_display" class="form-select d-inline-block w-auto align-middle"
                              data-bs-toggle="tooltip" data-bs-placement="top" title="Limit display of the Query Log to the top X queries sorted by hits; the 'Download' button will still download the entire log"><?php
                              foreach ($_RDATA['admin_query_log_display_options'] as $opt) { ?> 
                                <option value="<?php echo $opt; ?>"<?php
                                  if ($_ODATA['admin_query_log_display'] == $opt) echo ' selected="selected"';
                                  ?>><?php echo ($opt) ? $opt : 'All'; ?></option><?php
                              } ?> 
                            </select>
                          </label>
                        </td>
                        <th class="fs-5 text-center os_sorting os_desc" scope="col">
                          <span data-bs-toggle="tooltip" data-bs-placement="top" title="The number of times this query has been searched for, with unique users by IP address in (brackets)"
                            role="button" id="os_queries_hits">Hits</span>
                          <img src="img/arrow-down.svg" alt="Sort" title="Sort order" class="align-middle svg-icon-sm mb-1">
                        </th>
                        <th class="fs-5 text-center d-none d-sm-table-cell" scope="col">
                          <span role="button" id="os_queries_results" title="Search results returned">Results</span>
                          <img src="img/arrow-down.svg" alt="Sort" title="Sort order" class="align-middle svg-icon-sm mb-1">
                        </th>
                        <th class="fs-5 text-center" colspan="2" scope="col">
                          <span role="button" id="os_queries_stamp">
                            <span class="d-none d-md-inline">Last Requested</span>
                            <img src="img/clock.svg" alt="" class="d-md-none align-middle svg-icon mb-1"
                              data-bs-toggle="tooltip" data-bs-placement="left" title="Time since this query was last requested">
                          </span>
                          <img src="img/arrow-down.svg" alt="Sort" title="Sort order" class="align-middle svg-icon-sm mb-1">
                        </th>
                      </tr>
                    </thead>
                    <tfoot class="table-group-divider"><?php
                      if ($_RDATA['query_log_found_rows'] > count($_RDATA['query_log_rows'])) { ?> 
                        <tr>
                          <td class="text-center" colspan="6">
                            <em>
                              Showing top <?php
                                echo count($_RDATA['query_log_rows']);
                              ?> queries of <?php
                                echo $_RDATA['query_log_found_rows'];
                              ?> total queries
                            </em>
                          </td>
                        </tr><?php
                      } ?> 
                      <tr class="d-none">
                        <td class="text-center" colspan="6">
                          <button type="submit" name="os_submit" id="os_queries_purge" value="os_query_log_purge" class="btn btn-primary" title="Delete multiple queries">Delete Selected Queries</button>
                          <button type="submit" name="os_submit" id="os_queries_nuke" value="os_query_log_nuke" class="btn btn-primary" title="Delete multiple queries AND associated IP addresses">Nuke Selected Queries</button>
                        </td>
                      </tr>
                    </tfoot>
                    <tbody class="table-group-divider" id="os_queries_tbody"><?php
                      foreach ($_RDATA['query_log_rows'] as $query) { ?> 
                        <tr class="text-nowrap">
                          <th scope="row" data-value="<?php echo count($_RDATA['query_log_rows']) - $query['rownum']; ?>" colspan="2">
                            <div class="d-inline-block align-middle mw-90">
                              <div class="w-100 d-table table-fixed">
                                <div class="w-100 d-table-cell overflow-hidden text-ellipsis">
                                  <input type="checkbox" name="os_queries_multi[]" value="<?php echo htmlspecialchars($query['query']); ?>" class="form-check-input d-none me-1">
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
                            ?> <small title="Unique IPs" data-value="<?php echo (int)$query['ipuni']; ?>">(<?php
                            echo (int)$query['ipuni'];
                          ?>)</small></td>
                          <td class="text-center d-none d-sm-table-cell" data-value="<?php echo (int)$query['results']; ?>"><?php
                            echo (int)$query['results'];
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
                          </td>
                          <td class="text-end d-none d-md-table-cell" data-value="<?php echo $query['ip']; ?>">
                            <a href="https://bgp.he.net/ip/<?php echo $query['ip']; ?>" target="_blank"><?php
                              echo preg_replace('/:.+:/', ':&hellip;:', $query['ip']); ?></a><?php
                            if ($geo = OS_getGeo($query)) {
                              $title = $geo->names['en'];
                              if (file_exists(__DIR__.'/img/flags/'.strtolower($geo->isoCode).'.png')) {
                                $flag = 'img/flags/'.strtolower($geo->isoCode).'.png';
                                $classname = 'svg-icon-flag';
                              } else { // Missing flag
                                $flag = 'img/help.svg';
                                $classname = 'svg-icon';
                              } ?> 
                              <img src="<?php echo $flag; ?>" class="align-middle <?php echo $classname; ?> mb-1"
                                alt="<?php echo htmlspecialchars($title); ?>" title="<?php echo htmlspecialchars($title); ?>"><?php
                            }
                          ?></td>
                        </tr><?php
                      } ?> 
                    </tbody>
                  </table>
                </fieldset>

                <div class="modal fade" id="queriesModal" tabindex="-1" aria-labelledby="queriesModalLabel" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <input type="hidden" name="os_query_log_hidden_ip" value="">
                      <input type="hidden" name="os_query_log_hidden_query" value="">
                      <div class="modal-header">
                        <h1 class="modal-title fs-3" id="queriesModalLabel">Search Query Details</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" title="Close"></button>
                      </div>
                      <div class="modal-body">
                        <h4>Query</h4>
                        <ul class="list-group mb-3">
                          <li class="list-group-item">
                            <label class="d-flex flex-column">
                              <strong class="pe-2">Query Text:</strong>
                              <var class="overflow-auto" id="os_queries_modal_query"></var>
                            </label>
                          </li>
                          <li class="list-group-item">
                            <div class="row">
                              <div class="col-sm-6">
                                <label class="d-flex">
                                  <strong class="pe-2" data-bs-toggle="tooltip" data-bs-placement="top"
                                    title="Total requests for this query">Hit Count:</strong>
                                  <var class="flex-grow-1 text-end" id="os_queries_modal_hits"></var>
                                </label>
                              </div>
                              <div class="col-sm-6">
                                <label class="d-flex">
                                  <strong class="pe-2" data-bs-toggle="tooltip" data-bs-placement="top"
                                    title="Unique requests for this query based on IP address">Unique IPs:</strong>
                                  <var class="flex-grow-1 text-end" id="os_queries_modal_hits_unique"></var>
                                </label>
                              </div>
                            </div>
                          </li>
                        </ul>
                        <h4>Last Request Information</h4>
                        <ul class="list-group mb-3">
                          <li class="list-group-item">
                            <label class="d-flex flex-column">
                              <strong class="pe-2">Date / Time Requested:</strong>
                              <var id="os_queries_modal_stamp"></var>
                            </label>
                          </li>
                          <li class="list-group-item">
                            <label class="d-flex">
                              <strong class="pe-2">Results Returned:</strong>
                              <var class="flex-grow-1 text-end" id="os_queries_modal_results"></var>
                            </label>
                          </li>
                          <li class="list-group-item">
                            <label class="d-flex">
                              <strong class="pe-2 flex-grow-1">IP Address:</strong>
                              <var id="os_queries_modal_ip"></var>
                            </label>
                          </li>
                        </ul>
                        <h4>Delete from Query Log</h4>
                        <fieldset class="text-center mb-2">
                          <label class="d-inline-block text-nowrap">Delete by:
                            <select name="os_query_log_delete" class="form-select d-inline-block w-auto align-middle">
                              <option value="query">Query</option>
                              <option value="ip">IP address</option>
                              <option value="assoc">All associated</option>
                            </select>
                          </label>
                          <button type="submit" name="os_submit" value="os_query_log_delete" class="btn btn-primary text-top">Go</button>
                        </fieldset>
                      </div>
                    </div>
                  </div>
                </div>
              </form><?php
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