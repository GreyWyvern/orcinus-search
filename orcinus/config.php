<?php /* ***** Orcinus Site Search - Global Configuration ********** */


$_DDATA = array();
$_RDATA = array();
require __DIR__.'/config.ini.php';


// ***** Connect to the database
$_DDATA['pdo'] = new PDO(
  'mysql:host='.$_DDATA['hostname'].';dbname='.$_DDATA['database'].';charset=UTF8',
  $_DDATA['username'],
  $_DDATA['password']
);
$err = $_DDATA['pdo']->errorInfo();
if ($err[0]) die('Fatal database connection error: '.$err[0]);
$_DDATA['pdo']->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$_DDATA['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$_DDATA['tables'] = $_DDATA['pdo']->query(
  'SHOW TABLES FROM `'.$_DDATA['database'].'` LIKE \''.$_DDATA['tbprefix'].'%\';'
);
$err = $_DDATA['tables']->errorInfo();
if ($err[0] == '00000') {
  $_DDATA['tables'] = $_DDATA['tables']->fetchAll(PDO::FETCH_NUM);
  foreach($_DDATA['tables'] as $key => $value)
    $_DDATA['tables'][$key] = $value[0];
} else die('Fatal database read error: '.$err[2]);


// ***** Create the configuration table if it doesn't exist
if (!in_array($_DDATA['tbprefix'].'config', $_DDATA['tables'])) {
  $create = $_DDATA['pdo']->query(
    'CREATE TABLE `'.$_DDATA['tbprefix'].'config` (
      `version` VARCHAR(8) NOT NULL,
      `admin_email` TEXT NOT NULL,
      `admin_install_domain` TINYTEXT NOT NULL,
      `admin_index_pagination` SMALLINT UNSIGNED NOT NULL,
      `sp_key` TINYTEXT NOT NULL,
      `sp_starting` TEXT NOT NULL,
      `sp_limit_store` SMALLINT UNSIGNED NOT NULL,
      `sp_limit_crawl` SMALLINT UNSIGNED NOT NULL,
      `sp_limit_depth` TINYINT UNSIGNED NOT NULL,
      `sp_limit_filesize` SMALLINT UNSIGNED NOT NULL,
      `sp_sleep` SMALLINT UNSIGNED NOT NULL,
      `sp_ignore_ext` TEXT NOT NULL,
      `sp_ignore_url` TEXT NOT NULL,
      `sp_ignore_css` TEXT NOT NULL,
      `sp_require_url` TEXT NOT NULL,
      `sp_title_strip` TEXT NOT NULL,
      `sp_timeout_url` TINYINT UNSIGNED NOT NULL,
      `sp_timeout_crawl` SMALLINT UNSIGNED NOT NULL,
      `sp_interval` TINYINT UNSIGNED NOT NULL,
      `sp_interval_start` TIME NOT NULL,
      `sp_interval_stop` TIME NOT NULL,
      `sp_timezone` TINYTEXT NOT NULL,
      `sp_time_start` INT UNSIGNED NOT NULL,
      `sp_time_end` INT UNSIGNED NOT NULL,
      `sp_time_end_success` INT UNSIGNED NOT NULL,
      `sp_time_last` SMALLINT UNSIGNED NOT NULL,
      `sp_data_transferred` INT UNSIGNED NOT NULL,
      `sp_data_stored` INT UNSIGNED NOT NULL,
      `sp_links_crawled` SMALLINT UNSIGNED NOT NULL,
      `sp_pages_stored` SMALLINT UNSIGNED NOT NULL,
      `sp_autodelete` BOOLEAN NOT NULL,
      `sp_ifmodifiedsince` BOOLEAN NOT NULL,
      `sp_cookies` BOOLEAN NOT NULL,
      `sp_sitemap_file` TINYTEXT NOT NULL,
      `sp_sitemap_hostname` TINYTEXT NOT NULL,
      `sp_useragent` TINYTEXT NOT NULL,
      `sp_crawling` BOOLEAN NOT NULL,
      `sp_cancel` BOOLEAN NOT NULL,
      `sp_progress` TINYTEXT NOT NULL,
      `sp_email_success` BOOLEAN NOT NULL,
      `sp_email_failure` BOOLEAN NOT NULL,
      `sp_log` MEDIUMTEXT NOT NULL,
      `s_limit_terms` TINYINT UNSIGNED NOT NULL,
      `s_limit_term_length` TINYINT UNSIGNED NOT NULL,
      `s_limit_results` TINYINT UNSIGNED NOT NULL,
      `s_results_pagination` TINYINT UNSIGNED NOT NULL,
      `s_limit_matchtext` SMALLINT UNSIGNED NOT NULL,
      `s_limit_cache` SMALLINT UNSIGNED NOT NULL,
      `s_weights` TINYTEXT NOT NULL,
      `s_weight_css` TEXT NOT NULL,
      `s_show_orphans` BOOLEAN NOT NULL,
      `s_show_filetype_html` BOOLEAN NOT NULL,
      `s_charset` TINYTEXT NOT NULL,
      `s_result_template` TEXT NOT NULL,
      `s_limit_query_log` TINYINT UNSIGNED NOT NULL,
      `jw_hostname` TINYTEXT NOT NULL,
      `jw_compression` TINYINT UNSIGNED NOT NULL,
      PRIMARY KEY (`version`)
    ) ENGINE = MyISAM, COLLATE = utf8_general_ci;'
  );
}

$testConf = $_DDATA['pdo']->query(
  'SELECT `version` FROM `'.$_DDATA['tbprefix'].'config`;'
);
$err = $testConf->errorInfo();
if ($err[0] == '00000') {
  $testConf = $testConf->fetchAll();
} else die('Fatal configuration table read error: '.$err[2]);

// ***** Set default configuration table values
if (!count($testConf)) {
  $insert = $_DDATA['pdo']->query(
    'INSERT INTO `'.$_DDATA['tbprefix'].'config` SET
      `version`=\'3.0\',
      `admin_email`=\'\',
      `admin_install_domain`=\'\',
      `admin_index_pagination`=100,
      `sp_key`=\'\',
      `sp_starting`=\'\',
      `sp_limit_store`=500,
      `sp_limit_crawl`=2500,
      `sp_limit_depth`=9,
      `sp_limit_filesize`=200,
      `sp_sleep`=0,
      `sp_ignore_ext`=\'7z au aiff avi bin bz bz2 cab cda cdr class com css csv doc docx dll dtd dwg dxf eps exe gif hqx ico image jar jav java jfif jpeg jpg js kbd mid mkv moov mov movie mp3 mp4 mpeg mpg ocx ogg png pps ppt ps psd qt ra ram rar rm rpm rtf scr sea sit svg swf sys tar.gz tga tgz tif tiff ttf uu uue vob wav woff woff2 xls xlsx z zip\',
      `sp_ignore_url`=\'\',
      `sp_ignore_css`=\'.noindex footer form head nav noscript select style svg textarea\',
      `sp_require_url`=\'\',
      `sp_title_strip`=\'\',
      `sp_timeout_url`=10,
      `sp_timeout_crawl`=300,
      `sp_interval`=24,
      `sp_interval_start`=\'00:00:00\',
      `sp_interval_stop`=\'00:00:00\',
      `sp_timezone`=\''.date_default_timezone_get().'\',
      `sp_time_start`=0,
      `sp_time_end`=0,
      `sp_time_end_success`=0,
      `sp_time_last`=0,
      `sp_data_transferred`=0,
      `sp_data_stored`=0,
      `sp_links_crawled`=0,
      `sp_pages_stored`=0,
      `sp_autodelete`=0,
      `sp_ifmodifiedsince`=1,
      `sp_cookies`=1,
      `sp_sitemap_file`=\'\',
      `sp_sitemap_hostname`=\''.$_SERVER['HTTP_HOST'].'\',
      `sp_useragent`=\'OrcinusSearch/3.0 (https://greywyvern.com/orcinus/#search)\',
      `sp_crawling`=0,
      `sp_cancel`=0,
      `sp_progress`=\'\',
      `sp_email_success`=0,
      `sp_email_failure`=1,
      `sp_log`=\'\',
      `s_limit_terms`=7,
      `s_limit_term_length`=3,
      `s_limit_results`=30,
      `s_results_pagination`=10,
      `s_limit_matchtext`=256,
      `s_limit_cache`=256,
      `s_weights`=\'1.3%0.5%2.1%0.4%1.9%0.2%2.5%1.5\',
      `s_weight_css`=\'.important dt h1 h2 h3\',
      `s_show_orphans`=0,
      `s_show_filetype_html`=0,
      `s_charset`=\'UTF-8\',
      `s_result_template`=\'\',
      `s_limit_query_log`=14,
      `jw_hostname`=\'\',
      `jw_compression`=25
    ;'
  );
}

// ***** Create the crawldata table if it doesn't exist
if (!in_array($_DDATA['tbprefix'].'crawldata', $_DDATA['tables'])) {
  $create = $_DDATA['pdo']->query(
    'CREATE TABLE `'.$_DDATA['tbprefix'].'crawldata` (
      `url` TEXT NOT NULL,
      `url_base` TINYTEXT NOT NULL,
      `url_sort` SMALLINT UNSIGNED NOT NULL,
      `title` TEXT NOT NULL,
      `description` TEXT NOT NULL,
      `keywords` TEXT NOT NULL,
      `category` TINYTEXT NOT NULL,
      `weighted` TEXT NOT NULL,
      `links` TEXT NOT NULL,
      `content` MEDIUMTEXT NOT NULL,
      `content_mime` TINYTEXT NOT NULL,
      `content_charset` TINYTEXT NOT NULL,
      `content_checksum` BINARY(20) NOT NULL,
      `status` TINYTEXT NOT NULL,
      `status_noindex` TINYTEXT NOT NULL,
      `flag_unlisted` BOOLEAN NOT NULL,
      `flag_updated` BOOLEAN NOT NULL,
      `last_modified` INT NOT NULL,
      `priority` DECIMAL(2,1) NOT NULL,
      UNIQUE `content_checksum` (`content_checksum`)
    ) ENGINE = MyISAM, COLLATE = utf8_general_ci;'
  );
}

// ***** Create the query log table if it doesn't exist
if (!in_array($_DDATA['tbprefix'].'query', $_DDATA['tables'])) {
  $create = $_DDATA['pdo']->query(
    'CREATE TABLE `'.$_DDATA['tbprefix'].'query` (
      `query` TINYTEXT NOT NULL,
      `results` TINYINT UNSIGNED NOT NULL,
      `stamp` INT UNSIGNED NOT NULL,
      `ip` INT UNSIGNED NOT NULL,
      `cache` MEDIUMBLOB NOT NULL
    ) ENGINE = MyISAM, COLLATE = utf8_general_ci;'
  );
}


/**
 * Generates a readable filesize string from an integer byte-count
 *  $abbr => Optional <abbr> tag with title attribute added
 */
function OS_readSize($bytes, $abbr = false) {
  $bytes = (int)$bytes;
  if ($bytes >= 1020054733) return round(($bytes / 1073741824), 1).' '.(($abbr) ? '<abbr title="gibibytes">GiB</abbr>' : 'GiB');
  if ($bytes >= 996148) return round(($bytes / 1048576), 1).' '.(($abbr) ? '<abbr title="mebibytes">MiB</abbr>' : 'MiB');
  if ($bytes >= 973) return round(($bytes / 1024), 1).' '.(($abbr) ? '<abbr title="kibibytes">kiB</abbr>' : 'kiB');
  if ($bytes >= 0) return $bytes.' '.(($abbr) ? '<abbr title="bytes">B</abbr>' : 'B');
  return '';
}


/**
 * Set an $_ODATA value by updating it in the config database
 *
 */
function OS_setValue($columnName, $value) {
  global $_ODATA, $_DDATA;

  if (!isset($_ODATA[$columnName])) return 0;

  $update = $_DDATA['pdo']->prepare(
    'UPDATE `'.$_DDATA['tbprefix'].'config` SET `'.$columnName.'`=:value;'
  );
  $update->execute(array('value' => $value));

  $err = $update->errorInfo();
  if ($err[0] != '00000') {
    if (isset($_SESSION['error']))
      $_SESSION['error'][] = 'Could not set value \''.$columnName.'\' in config database.';
    return 0;

  } else if ($update->rowCount())
    $_ODATA[$columnName] = $value;

  return $update->rowCount();
}


/**
 * Get a single live $_ODATA value from the database
 *
 */
function OS_getValue($columnName) {
  global $_ODATA, $_DDATA;

  if (isset($_ODATA[$columnName])) {
    $select = $_DDATA['pdo']->query(
      'SELECT `'.$columnName.'` FROM `'.$_DDATA['tbprefix'].'config`;'
    );

    $err = $select->errorInfo();
    if ($err[0] == '00000') {
      $select = $select->fetchAll();
      if (count($select))
        $_ODATA[$columnName] = $select[0][$columnName];

    } else if (isset($_SESSION['error']))
      $_SESSION['error'][] = 'Could not get live value of \''.$columnName.'\' from config database.';

  }

  return $_ODATA[$columnName];
}


/**
 * Initialize a generic cURL connection
 *  - If creating a cURL connection fails, we should try some fallbacks
 *
 */
function OS_getConnection() {
  global $_ODATA;

  if (function_exists('curl_init')) {
    $_ = curl_init();
    curl_setopt($_, CURLOPT_USERAGENT, $_ODATA['sp_useragent']);
    curl_setopt($_, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($_, CURLOPT_CONNECTTIMEOUT, $_ODATA['sp_timeout_url']);
    curl_setopt($_, CURLOPT_TIMEOUT, $_ODATA['sp_timeout_url']);
    curl_setopt($_, CURLOPT_ENCODING, 'gzip');
    curl_setopt($_, CURLOPT_FILETIME, true);
    curl_setopt($_, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

  } else {
    // Fall back to file_get_contents with stream context?

    // Fall back to socket connection?

    $_ = false;
  }

  return $_;
}


// ***** Pull the configuration data from the database
$_ODATA = $_DDATA['pdo']->query(
  'SELECT * FROM `'.$_DDATA['tbprefix'].'config`;'
)->fetchAll()[0];

error_reporting(E_ALL);
date_default_timezone_set($_ODATA['sp_timezone']);
ini_set('mbstring.substitute_character', 'none');


// ***** Determine the install domain from run location
if (!$_ODATA['admin_install_domain']) {
  if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] &&
      isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) {
    $base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'];
    if (isset($_SERVER['SCRIPT_URI']) && $_SERVER['SCRIPT_URI']) {
      $psuri = parse_url($_SERVER['SCRIPT_URI']);
      if ($psuri && isset($psuri['port']) && !is_null($psuri['port']))
        $base .= ':'.$psuri['port'];
    } else if (isset($_SERVER['SERVER_PORT'])) {
      if ($_SERVER['SERVER_PORT'] == '80') {
        if ($_SERVER['REQUEST_SCHEME'] != 'http')
          $base .= ':'.$_SERVER['SERVER_PORT'];
      } else if ($_SERVER['SERVER_PORT'] == '443') {
        if ($_SERVER['REQUEST_SCHEME'] != 'https')
          $base .= ':'.$_SERVER['SERVER_PORT'];
      } else $base .= ':'.$_SERVER['SERVER_PORT'];
    }
    OS_setValue('admin_install_domain', $base);
  }
}
if (!$_ODATA['sp_starting']) {
  if (!$_ODATA['admin_install_domain']) {
    die('Fatal error, could not determine install domain. Please run this script from a web browser.');
  } else OS_setValue('sp_starting', $_ODATA['admin_install_domain'].'/');
}


// ***** Load and Initialize PHPMailer
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
  if (file_exists(__DIR__.'/PHPMailer/PHPMailer.php')) {
    include __DIR__.'/PHPMailer/PHPMailer.php';
    include __DIR__.'/PHPMailer/Exception.php';
    include __DIR__.'/PHPMailer/SMTP.php';
  }
}
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
  $_MAIL = new PHPMailer\PHPMailer\PHPMailer();
  $_MAIL->From = $_SERVER['SERVER_ADMIN'];
  $_MAIL->FromName = "Orcinus Site Search Crawler";
  $_MAIL->CharSet = $_ODATA['s_charset'];
  if (count($ad = $_MAIL->parseAddresses($_ODATA['admin_email'])))
    foreach ($ad as $a) $_MAIL->AddAddress($a['address'], $a['name']);
} else $_MAIL = false;


// ***** Load the default Search Result Template
if (!$_ODATA['s_result_template']) {
  OS_setValue('s_result_template', <<<ORCINUS
<section id="os_results">
  <!-- Orcinus Site Search {{version}} - HTML Template -->

  {{#errors}}
    <ul>
      {{#error_list}}
        <li>{{text}}</li>
      {{/error_list}}
    </ul>
  {{/errors}}

  {{#searchable}}
    {{#searched}}
      {{#category}}
        <div>
          <p>
            Searching within category:
            <strong>{{request_c}}</strong>
          </p>
        </div>
      {{/category}}

      {{#results}}
        <div>
          <p>
            Showing results
            <var>{{from}}</var> &ndash; <var>{{to}}</var>
            of <var>{{of}}</var>
            {{#in}}
              in <var>{{in}}</var> seconds
            {{/in}}
          </p>
        </div>

        <ol start="{{from}}">
          {{#result_list}}
            <li>
              <header>
                <span title="File type">{{filetype}}</span>
                <a href="{{url}}" title="{{description}}">{{{title_highlight}}}</a>
                <small title="Category">{{category}}</small>
              </header>
              <blockquote>
                <p>{{{matchtext_highlight}}}</p>
              </blockquote>
              <footer>
                <cite>{{{url_highlight}}}</cite>
                <small title="Relevance">({{relevance}})</small>
              </footer>
            </li>
          {{/result_list}}
        </ol>

        {{#pagination}}
          <nav>
            <ul>
              <li>
                {{#page_gt1}}
                  <a href="?q={{request_q}}&page={{page_minus1}}">Previous</a>
                {{/page_gt1}}
                {{^page_gt1}}
                  <span>Previous</span>
                {{/page_gt1}}
              </li>
              {{#page_list}}
                <li>
                  {{^current}}
                    <a href="?q={{request_q}}&page={{index}}">{{index}}</a>
                  {{/current}}
                  {{#current}}
                    <span><strong>{{index}}</strong></span>
                  {{/current}}
                </li>
              {{/page_list}}
              <li>
                {{#page_ltpages}}
                  <a href="?q={{request_q}}&page={{page_plus1}}">Next</a>
                {{/page_ltpages}}
                {{^page_ltpages}}
                  <span>Next</span>
                {{/page_ltpages}}
              </li>
            </ul>
          </nav>
        {{/pagination}}
      {{/results}}
      {{^results}}
        <div>
          <p>
            Sorry, no results were found.
            {{#category}}
              Try this search in
              <a href="?q={{request_q}}">all categories?</a>
            {{/category}}
          </p>
        </div>
      {{/results}}
    {{/searched}}
    {{^searched}}
      <div>
        <p>
          Please enter your search terms below.
        </p>
        <ul>
          <li>Search terms with fewer than {{limit_term_length}} characters are ignored</li>
          <li>Enclose groups of terms in quotes ("") to search for phrases</li>
          <li>Prefix terms with a plus-sign (+) to make them important</li>
          <li>Prefix terms with a minus-sign (-) or exclamation point (!) to exclude terms</li>
        </ul>
      </div>
    {{/searched}}

    <search role="search">
      <form action="{{form_action}}" method="get">
        <label>
          <input type="search" name="q" value="{{request_q}}"
            class="os_typeahead" placeholder="Search..." aria-label="Search">
        </label>
        {{#categories}}
          <label>
            <select name="c">
              {{#category_list}}
                <option value="{{name}}"{{#selected}} selected="selected"{{/selected}}>
                  {{name}}
                </option>
              {{/category_list}}
            </select>
          </label>
        {{/categories}}
        <button type="submit">
          Search
        </button>
      </form>
    </search>
  {{/searchable}}
  {{^searchable}}
    <div>
      <p>
        There are no searchable pages in the database.
        Please try again later.
      </p>
    </div>
  {{/searchable}}

  <footer>
    <hr>
    <p>
      <small>
        Powered by
        <a href="https://greywyvern.com/orcinus/#search" target="_blank">
          Orcinus
        </a>
      </small>
    </p>
  </footer>
</section>
ORCINUS);
}

// {{{{{ Initialize the Mustache templating engine
class OS_Mustache {
  public $errors;

  public function __construct() {}

  public function addError($text) {
    if (!$this->errors) {
      $this->errors = new stdClass();
      $this->errors->error_list = array();
    }
    $this->errors->error_list[] = $text;
  }

  // We'll only autoload the Mustache engine if we need it
  public function render() {
    global $_ODATA;

    require_once __DIR__.'/Mustache/Autoloader.php';
    Mustache_Autoloader::register();

    $output = new Mustache_Engine(array('entity_flags' => ENT_QUOTES));
    echo $output->render($_ODATA['s_result_template'], $this);
  }
}


// Purge entries from the search query log older than
// 's_limit_query_log' ago
$deleteold = $_DDATA['pdo']->prepare(
  'DELETE FROM `'.$_DDATA['tbprefix'].'query` WHERE `stamp`<:cutoff;'
);
$deleteold->execute(array('cutoff' => time() - $_ODATA['s_limit_query_log'] * 86400));
$err = $deleteold->errorInfo();
if ($err[0] != '00000')
  if (isset($_SESSION['error']))
    $_SESSION['error'][] = 'Database error purging old records from the query log.';


// Reduce search result cache size to within limits
$_RDATA['s_cache_size'] = 0;
$_RDATA['s_cached_searches'] = 0;
$cachesize = $_DDATA['pdo']->query(
  'SELECT COUNT(`cache`) AS `count`, SUM(LENGTH(`cache`)) AS `size` FROM `'.$_DDATA['tbprefix'].'query` WHERE `cache`<>\'\';'
);
$err = $cachesize->errorInfo();
if ($err[0] == '00000') {
  $cachesize = $cachesize->fetchAll();

  $_RDATA['s_cached_searches'] = $cachesize[0]['count'];

  // If search result cache is over the size limit
  if ($cachesize[0]['size'] > $_ODATA['s_limit_cache'] * 1024) {
    $select = $_DDATA['pdo']->query(
      'SELECT `query`, `cache` FROM `'.$_DDATA['tbprefix'].'query` WHERE `cache`<>\'\' ORDER BY `stamp` ASC;'
    );
    $err = $select->errorInfo();
    if ($err[0] == '00000') {

      // Find out how many cache entries we need to delete, sorted by
      // the oldest cached search queries first
      $toDel = array();
      $select = $select->fetchAll();
      do {
        $first = array_shift($select);
        $toDel[$first['query']] = strlen($first['cache']);
      } while ($cachesize[0]['size'] - array_sum($toDel) > $_ODATA['s_limit_cache'] * 1024);

      // Delete cache entries with the oldest `cache` values until we
      // are below the cache size limit
      foreach ($toDel as $del => $size) {
        $update = $_DDATA['pdo']->prepare(
          'UPDATE `'.$_DDATA['tbprefix'].'query` SET `cache`=\'\' WHERE `query`=:query;'
        );
        $update->execute(array('query' => $del));
        if (!$update->rowCount()) {
          if (isset($_SESSION['error']))
            $_SESSION['error'][] = 'Database error while limiting the search result cache size.';
          break;

        } else {
          $cachesize[0]['size'] -= $size;
          $_RDATA['s_cached_searches']--;
        }
      }

    } else if (isset($_SESSION['error']))
      $_SESSION['error'][] = 'Could not read from search result cache.';

  }
  $_RDATA['s_cache_size'] = $cachesize[0]['size'];

} else if (isset($_SESSION['error']))
  $_SESSION['error'][] = 'Could not read search result cache size.';

// Get a list of all categories in the search database
$_RDATA['s_category_list'] = array('<none>' => 0);
$_RDATA['s_pages_stored'] = 0;
$categories = $_DDATA['pdo']->query(
  'SELECT `category`, COUNT(`category`) AS `count`
    FROM `'.$_DDATA['tbprefix'].'crawldata`
      GROUP BY `category` ORDER BY `category`;'
);
$err = $categories->errorInfo();
if ($err[0] == '00000') {
  $categories = $categories->fetchAll();
  foreach ($categories as $category) {
    $_RDATA['s_category_list'][$category['category']] = $category['count'];
    $_RDATA['s_pages_stored'] += $category['count'];
  }
} else if (isset($_SESSION['error']))
  $_SESSION['error'][] = 'Could not read categories from the search database.';

// Count base URLs / domains from the crawldata: if there is only one
// in the search database then we don't have to show it in a number of
// places
$_RDATA['s_crawldata_domains'] = array();
$domains = $_DDATA['pdo']->query(
  'SELECT `url_base`, COUNT(`url_base`) as `count`
    FROM `'.$_DDATA['tbprefix'].'crawldata`
      GROUP BY `url_base` ORDER BY `count` DESC;'
);
$err = $domains->errorInfo();
if ($err[0] == '00000') {
  $domains = $domains->fetchAll();
  foreach ($domains as $domain)
    $_RDATA['s_crawldata_domains'][$domain['url_base']] = $domain['count'];
} else if (isset($_SESSION['error']))
  $_SESSION['error'][] = 'Could not read domain count data from search database.';
if (count($_RDATA['s_crawldata_domains']) == 1)
  OS_setValue('jw_hostname', key($_RDATA['s_crawldata_domains']));

// Count searchable pages
$_RDATA['s_searchable_pages'] = 0;
$query_status = ($_ODATA['s_show_orphans']) ? '(`status`=\'OK\' || `status`=\'Orphan\')' : '`status`=\'OK\'';
$searchable = $_DDATA['pdo']->query(
  'SELECT COUNT(`status`) as `count`
    FROM `'.$_DDATA['tbprefix'].'crawldata`
      WHERE '.$query_status.' AND `flag_unlisted`=0;'
);
$err = $searchable->errorInfo();
if ($err[0] == '00000') {
  $searchable = $searchable->fetchAll();
  $_RDATA['s_searchable_pages'] = $searchable[0]['count'];
} else if (isset($_SESSION['error']))
  $_SESSION['error'][] = 'Could not read status data from search database.';


// Match Weighting Values
$weights = explode('%', $_ODATA['s_weights']);
$_RDATA['s_weights'] = array(
  'title' => $weights[0],
  'body' => $weights[1],
  'keywords' => $weights[2],
  'description' => $weights[3],
  'css_value' => $weights[4],
  'url' => $weights[5],
  'multi' => $weights[6],
  'important' => $weights[7]
);

$_RDATA['sp_smart'] = array(
  '’' => '\'',
  '‘' => '\'',
  '“' => '"',
  '”' => '"',
  '‟' => '"',
  '„' => '"',
  '…' => '...',
  '·' => '•',
  '›' => '>',
  '‖' => '|'
);
$_RDATA['s_latin'] = array(
   'a' => array('á', 'Á', 'à', 'À', 'â', 'Â', 'ä', 'Ä', 'ã', 'Ã', 'å', 'Å', 'ą', 'Ą', 'ă', 'Ă'),
  'ae' => array('æ', 'Æ'),
   'c' => array('ç', 'Ç', 'ć', 'Ć', 'č', 'Č'),
   'd' => array('ð', 'Ð', 'ď', 'Ď', 'đ', 'Đ'),
   'e' => array('é', 'É', 'è', 'È', 'ê', 'Ê', 'ë', 'Ë', 'ę', 'Ę', 'ě', 'Ě'),
   'g' => array('ğ', 'Ğ'),
   'i' => array('í', 'Í', 'ì', 'Ì', 'î', 'Î', 'ï', 'Ï', 'ı', 'İ'),
   'l' => array('ł', 'Ł', 'ľ', 'Ľ', 'ĺ', 'Ĺ'),
   'n' => array('ñ', 'Ñ', 'ń', 'Ń', 'ň', 'Ň'),
   'o' => array('ó', 'Ó', 'ò', 'Ò', 'ô', 'Ô', 'ö', 'Ö', 'õ', 'Õ', 'ø', 'Ø', 'ő', 'Ő'),
  'oe' => array('œ', 'Œ'),
   'r' => array('ŕ', 'Ŕ', 'ř', 'Ř'),
   's' => array('ş', 'Ş', 'ś', 'Ś', 'š', 'Š'),
  'sz' => array('ß'),
   't' => array('ť', 'Ť', 'ţ', 'Ţ'),
  'th' => array('þ', 'Þ'),
   'u' => array('ú', 'Ú', 'ù', 'Ù', 'û', 'Û', 'ü', 'Ü', 'ů', 'Ů', 'ű', 'Ű'),
   'x' => array('×'),
   'y' => array('ý', 'Ý', 'ÿ', 'Ÿ'),
   'z' => array('ź', 'Ź', 'ž', 'Ž', 'ż', 'Ż'),
   '?' => array('¿')
);
$_RDATA['s_filetypes'] = array(
   'PDF' => array('application/pdf'),
   'JPG' => array('image/jpeg'),
  'HTML' => array('text/html', 'application/xhtml+xml'),
   'XML' => array('text/xml', 'application/xml'),
   'TXT' => array('text/plain')
);

// Locate the sitemap file if given
if ($_ODATA['sp_sitemap_file']) {
  $sitemapPath = ($_ODATA['sp_sitemap_file'][0] == '/') ? $_SERVER['DOCUMENT_ROOT'] : __DIR__.'/';
  $sitemapPath .= $_ODATA['sp_sitemap_file'];
  $sitemapPath = preg_replace(array('/\/[^\/]+\/\.\.\//', '/\/\.\//'), '/', $sitemapPath);

  // If we did not try going beyond the document_root
  if (strpos($sitemapPath, $_SERVER['DOCUMENT_ROOT']) === 0) {
    if (file_exists($sitemapPath)) {
      $sitemapNewFile = str_replace($_SERVER['DOCUMENT_ROOT'], '', $sitemapPath);
      if ($sitemapNewFile != $_ODATA['sp_sitemap_file'])
        OS_setValue('sp_sitemap_file', $sitemapNewFile);
      if (is_writable($sitemapPath)) {
        $_RDATA['sp_sitemap_file'] = $sitemapPath;
      } else $_RDATA['sp_sitemap_file'] = 'not writable';
    } else $_RDATA['sp_sitemap_file'] = 'does not exist';
  } else {
    OS_setValue('sp_sitemap_file', '');
    $_RDATA['sp_sitemap_file'] = 'beyond root';
    if (isset($_SESSION['error']))
      $_SESSION['error'][] = 'Cannot set sitemap file location above the DOCUMENT_ROOT.';
  }
} else $_RDATA['sp_sitemap_file'] = '';


$_SERVER['REQUEST_URI'] = preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']);


$_RDATA['x_generated_by'] = 'X-Generated-By: Orcinus Site Search/'.$_ODATA['version'];
header($_RDATA['x_generated_by']);


// ***** Prevent caching of these pages
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache'); ?>