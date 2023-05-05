<?php /* ***** Orcinus Site Search - Searching Engine ************** */


require __DIR__.'/config.php';


$_SDATA = array(
  'terms' => array(),
  'formatted' => array(),
  'cache' => array(
    'data' => '',
    'ip' => '',
    'stamp' => 0,
    'raw' => ''
  ),
  'results' => array(),
  'json' => array(),
  'pages' => 1,
  'time' => microtime(true)
);


foreach ($_RDATA['s_weights'] as $key => $weight)
  $_RDATA['s_weights'][$key] = (float)$weight;

// Prepare regexp translation array for accented / ligature characters
$_RDATA['s_latin_pcre'] = array();
$_RDATA['s_latin_pcre_multi'] = array();
foreach ($_RDATA['s_latin'] as $char => $latin) {
  if (strlen($char) > 1) {
    $pcre = '('.$char.'|'.implode('|', $latin).')';
  } else $pcre = '['.$char.implode('', $latin).']';
  $_RDATA['s_latin_pcre'][$char] = $pcre;
  foreach ($latin as $lchar)
    $_RDATA['s_latin_pcre'][$lchar] = $pcre;
  if (strlen($char) > 1) {
    $_RDATA['s_latin_pcre_multi'][$char] = $pcre;
    foreach ($latin as $lchar)
      $_RDATA['s_latin_pcre_multi'][$lchar] = $pcre;
  }
}


// {{{{{ Initialize the Mustache templating engine
class OS_Mustache {
  public $errors;
  public $version;
  public $searchable;

  function __construct() {
    global $_ODATA;

    $this->version = $_ODATA['version'];
  }

  function addError($text) {
    if (!$this->errors) {
      $this->errors = new stdClass();
      $this->errors->error_list = array();
    }
    $this->errors->error_list[] = $text;
  }

  // We'll only autoload the Mustache engine if we need it
  function render() {
    global $_ODATA;

    require_once __DIR__.'/mustache/src/Mustache/Autoloader.php';
    Mustache_Autoloader::register();

    $output = new Mustache_Engine(array('entity_flags' => ENT_QUOTES));
    echo $output->render($_ODATA['s_result_template'], $this);
  }
}

$_ORCINUS = new OS_Mustache();


// Check if there are rows in the search database
if ($_RDATA['s_searchable_pages']) {
  $_ORCINUS->searchable = new stdClass();
  $_ORCINUS->searchable->form_action = $_SERVER['REQUEST_URI'];
  $_ORCINUS->searchable->limit_query = $_ODATA['s_limit_query'];
  $_ORCINUS->searchable->limit_term_length = $_ODATA['s_limit_term_length'];

  if (empty($_REQUEST['c']) || empty($_RDATA['s_category_list'][$_REQUEST['c']]))
    $_REQUEST['c'] = '<none>';

  if (count($_RDATA['s_category_list']) > 2) {
    $_ORCINUS->searchable->categories = new stdClass();
    $_ORCINUS->searchable->categories->category_list = array();
    foreach ($_RDATA['s_category_list'] as $category => $count) {
      $cat = new stdClass();
      $cat->name = ($category = '<none>') ? 'All Categories' : $category;
      $cat->value = $category;
      $cat->selected = ($_REQUEST['c'] == $category);
      $_ORCINUS->searchable->categories->category_list[] = $cat;
    }
  }
  
  if (empty($_REQUEST['q'])) $_REQUEST['q'] = '';

  $_REQUEST['q'] = preg_replace(array('/\s/', '/ {2,}/'), ' ', trim($_REQUEST['q']));

  // If there is a text request
  if ($_REQUEST['q']) {

    // Convert to UTF-8 from specified encoding
    $_REQUEST['q'] = mb_convert_encoding($_REQUEST['q'], 'UTF-8', $_ODATA['s_charset']);

    if (strlen($_REQUEST['q']) > $_ODATA['s_limit_query']) {
      $_REQUEST['q'] = substr($_REQUEST['q'], 0, $_ODATA['s_limit_query']);
      $_ORCINUS->addError('Search query truncated to maximum '.$_ODATA['s_limit_query'].' characters');
    }

    $_ORCINUS->searchable->request_q = $_REQUEST['q'];

    // Split request string on quotation marks (")
    $request = explode('"', ' '.$_REQUEST['q'].' ');
    for ($x = 0; $x < count($request) && count($_SDATA['terms']) < $_ODATA['s_limit_terms']; $x++) {

      // Every second + 1 group of terms just a list of terms
      if (!($x % 2)) {

        // Split this list of terms on spaces
        $request[$x] = explode(' ', $request[$x]);

        foreach ($request[$x] as $t) {
          if (!$t) continue;

          // Leading + means important, a MUST match
          if ($t[0] == '+') {

            // Just count it as a 'phrase' of one word, functionally equivalent
            $_SDATA['terms'][] = array('phrase', substr($t, 1), false);

          // Leading - means negative, a MUST exclude
          } else if ($t[0] == '-') {
            $_SDATA['terms'][] = array('exclude', substr($t, 1), false);

          // Restrict to a specific filetype (not yet implemented)
          // Really, we'd only allow HTML, XML and PDF here
          } else if (strpos(strtolower($t), 'filetype:') === 0) {
            $t = trim(substr($t, 9));
            if ($t && isset($_RDATA['s_filetypes'][strtoupper($t)]))
              $_SDATA['terms'][] = array('filetype', $t, false);

          // Else if the term is greater than the term length limit, add it
          } else if (strlen($t) >= $_ODATA['s_limit_term_length'])
            $_SDATA['terms'][] = array('term', $t, false);
        }

      // Every second group of terms is a phrase, a MUST match
      } else $_SDATA['terms'][] = array('phrase', $request[$x], false);
    }


    // If we successfully procured some terms
    if (count($_SDATA['terms'])) {
      $_ORCINUS->searchable->searched = new stdClass();
      if ($_REQUEST['c'] != '<none>') {
        $_ORCINUS->searchable->searched->category = new stdClass();
        $_ORCINUS->searchable->searched->category->request_c = $_REQUEST['c'];
      }

      // Prepare PCRE match text for each phrase and term
      foreach ($_SDATA['terms'] as $key => list($type, $term, $pcre)) {

        // Normalize punctuation
        $term = strtr($term, $_RDATA['sp_punct']);
        $_SDATA['terms'][$key][1] = $term;

        switch ($type) {
          case 'filetype':
            $_SDATA['formatted'][] = $type.':'.$term;
            break;

          case 'exclude':
            $_SDATA['formatted'][] = '-'.$term;
            break;

          case 'phrase':
            $_SDATA['formatted'][] = '"'.$term.'"';

          case 'term':
            if ($type == 'term')
              $_SDATA['formatted'][] = $term;

            // Regexp for later use pattern matching results
            $_SDATA['terms'][$key][2] = preg_quote(strtolower($_SDATA['terms'][$key][1]), '/');
            $_SDATA['terms'][$key][2] = strtr($_SDATA['terms'][$key][2], $_RDATA['s_latin_pcre']);
            $_SDATA['terms'][$key][2] = '/('.$_SDATA['terms'][$key][2].')/iu';

        }
      }


      // Without this, category searches are merged, maybe okay?
      // if ($_REQUEST['c'] != '<none>')
      //   $_SDATA['formatted'][] = '('.


      // Check if this search is already cached
      $_SDATA['formatted'] = implode(' ', $_SDATA['formatted']);
      $checkCache = $_DDATA['pdo']->prepare(
        'SELECT `stamp`, INET_NTOA(`ip`) AS `ip`, `cache`
           FROM `'.$_DDATA['tbprefix'].'query`
             WHERE `query`=:query AND `cache`<>\'\'
               ORDER BY `stamp` DESC LIMIT 1;'
      );
      $checkCache->execute(array('query' => $_SDATA['formatted']));
      $err = $checkCache->errorInfo();
      if ($err[0] == '00000') {
        $checkCache = $checkCache->fetchAll();

        // If we retrieved a matching row from the query log
        if (count($checkCache)) {
          $_SDATA['cache']['ip'] = $checkCache[0]['ip'];
          $_SDATA['cache']['stamp'] = $checkCache[0]['stamp'];
          $_SDATA['cache']['raw'] = $checkCache[0]['cache'];
          $_SDATA['cache']['data'] = $checkCache[0]['cache'];

          // Try to gzunzip the cache data
          if (function_exists('gzuncompress')) {
            $checkGZ = gzuncompress($_SDATA['cache']['data']);
            if ($checkGZ) $_SDATA['cache']['data'] = $checkGZ;
          }

          // Try to json_decode the cache data
          // If this step fails, assume there is no cache data
          $checkJS = json_decode($_SDATA['cache']['data'], true);
          $_SDATA['cache']['data'] = ($checkJS) ? $checkJS : '';
        }

      // Database error accessing the query log
      } else $_ORCINUS->addError('Error reading the search result cache');


      // ***** Nothing in the cache, so do an actual search
      if (!is_array($_SDATA['cache']['data'])) {

        // Begin building the basic query
        $searchSQL = '
          SELECT `url`, `category`, `content`, `content_mime`, `title`,
                 `description`, `keywords`, `weighted`, `priority`
            FROM `'.$_DDATA['tbprefix'].'crawldata`
              WHERE `flag_unlisted`=0 AND `priority`>0';

        // Restrict by category
        if ($_REQUEST['c'] != '<none>')
          $searchSQL .= ' AND `category`=\''.addslashes($_REQUEST['c']).'\'';

        // Show or do not show Orphans
        if (!$_ODATA['s_show_orphans'])
          $searchSQL .= ' AND `status`!=\'Orphan\'';

        $ands = array();
        $ors = array();
        $negs = array();
        $filetypes = array();
        foreach ($_SDATA['terms'] as list($type, $term, $pcre)) {

          $slashTerm = addslashes($term);

          switch ($type) {
            case 'filetype':
              if (isset($_RDATA['s_filetypes'][strtoupper($term)]))
                foreach ($_RDATA['s_filetypes'][strtoupper($term)] as $mime)
                  $filetypes[] = '`content_mime`=\''.$mime.'\'';
              break;

            case 'exclude':
              $negs[] = 'INSTR(`content`, \''.$slashTerm.'\')=0';
              $negs[] = 'INSTR(`url`, \''.$slashTerm.'\')=0';
              $negs[] = 'INSTR(`title`, \''.$slashTerm.'\')=0';
              $negs[] = 'INSTR(`description`, \''.$slashTerm.'\')=0';
              $negs[] = 'INSTR(`keywords`, \''.$slashTerm.'\')=0';
              $negs[] = 'INSTR(`weighted`, \''.$slashTerm.'\')=0';
              break;

            case 'phrase':
              $ands[] = '('.implode(' OR ', array(
                'INSTR(`content`, \''.$slashTerm.'\')',
                'INSTR(`url`, \''.$slashTerm.'\')',
                'INSTR(`title`, \''.$slashTerm.'\')',
                'INSTR(`description`, \''.$slashTerm.'\')',
                'INSTR(`keywords`, \''.$slashTerm.'\')',
                'INSTR(`weighted`, \''.$slashTerm.'\')'
              )).')';
              break;

            case 'term':
              $ors[] = 'INSTR(`content`, \''.$slashTerm.'\')';
              $ors[] = 'INSTR(`url`, \''.$slashTerm.'\')';
              $ors[] = 'INSTR(`title`, \''.$slashTerm.'\')';
              $ors[] = 'INSTR(`description`, \''.$slashTerm.'\')';
              $ors[] = 'INSTR(`keywords`, \''.$slashTerm.'\')';
              $ors[] = 'INSTR(`weighted`, \''.$slashTerm.'\')';

          }
        }

        if (count($filetypes))
          $searchSQL .= ' AND ('.implode(' OR ', $filetypes).') ';

        if (count($ands)) {
          $searchSQL .= ' AND '.implode(' AND ', $ands).' ';
        } else $searchSQL .= ' AND ('.implode(' OR ', $ors).') ';

        if (count($negs))
          $searchSQL .= ' AND '.implode(' AND ', $negs);

        // Execute the query
        $searchQuery = $_DDATA['pdo']->query($searchSQL.';');
        $err = $searchQuery->errorInfo();
        if ($err[0] == '00000') {
          $searchQuery = $searchQuery->fetchAll();

          // Apply relevance to each listing and then sort
          foreach ($searchQuery as $key => $row) {
            $searchQuery[$key]['relevance'] = 0;
            $searchQuery[$key]['multi'] = -1;
            $searchQuery[$key]['phrase'] = 0;

            // Lowercase values for easy compare
            $row['lc_content'] = strtolower($row['content']);
            $row['lc_url'] = strtolower($row['url']);
            $row['lc_title'] = strtolower($row['title']);
            $row['lc_description'] = strtolower($row['description']);
            $row['lc_keywords'] = strtolower($row['keywords']);
            $row['lc_weighted'] = strtolower($row['weighted']);

            // Remove latin character accents
            foreach ($_RDATA['s_latin'] as $char => $latin) {
              $row['lc_content'] = str_replace($latin, $char, $row['lc_content']);
              $row['lc_url'] = str_replace($latin, $char, $row['lc_url']);
              $row['lc_title'] = str_replace($latin, $char, $row['lc_title']);
              $row['lc_description'] = str_replace($latin, $char, $row['lc_description']);
              $row['lc_keywords'] = str_replace($latin, $char, $row['lc_keywords']);
              $row['lc_weighted'] = str_replace($latin, $char, $row['lc_weighted']);
            }

            // Run through each term and check content for matches
            foreach ($_SDATA['terms'] as list($type, $term, $pcre)) {
              switch ($type) {
                case 'filetype': break;
                case 'exclude': break;

                case 'phrase':
                  $searchQuery[$key]['phrase']++;

                case 'term':
                  $term = strtolower($term);
                  foreach ($_RDATA['s_latin'] as $char => $latin)
                    $term = str_replace($latin, $char, $term);

                  $pcreterm = '/\b'.preg_quote($term, '/').'/i';

                  // Give full points for every instance of a term
                  // that's at the beginning of a word or phrase
                  $i = preg_match_all($pcreterm, $row['lc_content']);
                  $j = preg_match_all($pcreterm, $row['lc_url']);
                  $k = preg_match_all($pcreterm, $row['lc_title']);
                  $l = preg_match_all($pcreterm, $row['lc_description']);
                  $m = preg_match_all($pcreterm, $row['lc_keywords']);
                  $n = preg_match_all($pcreterm, $row['lc_weighted']);

                  if ($i || $j || $k || $l || $m || $n)
                    $searchQuery[$key]['multi']++;

                  // Limit generic matches to a maximum of three
                  $a = $i + min(substr_count($row['lc_content'], $term), 3);
                  $b = $j + min(substr_count($row['lc_url'], $term), 3);
                  $c = $k + min(substr_count($row['lc_title'], $term), 3);
                  $d = $l + min(substr_count($row['lc_description'], $term), 3);
                  $e = $m + min(substr_count($row['lc_keywords'], $term), 3);
                  $f = $n + min(substr_count($row['lc_weighted'], $term), 3);

                  $searchQuery[$key]['relevance'] += $a * $_RDATA['s_weights']['body'];
                  $searchQuery[$key]['relevance'] += $b * $_RDATA['s_weights']['url'];
                  $searchQuery[$key]['relevance'] += $c * $_RDATA['s_weights']['title'];
                  $searchQuery[$key]['relevance'] += $d * $_RDATA['s_weights']['description'];
                  $searchQuery[$key]['relevance'] += $e * $_RDATA['s_weights']['keywords'];
                  $searchQuery[$key]['relevance'] += $f * $_RDATA['s_weights']['css_value'];

              }
            }

            // Calculate multipliers
            $searchQuery[$key]['relevance'] *= $_RDATA['s_weights']['multi'] ** $searchQuery[$key]['multi'];
            $searchQuery[$key]['relevance'] *= $_RDATA['s_weights']['important'] ** $searchQuery[$key]['phrase'];

            $searchQuery[$key]['relevance'] *= $row['priority'];
          }

          // Sort the list by relevance value
          usort($searchQuery, function($a, $b) {
            if ($b['relevance'] == $a['relevance']) return 0;
            return ($b['relevance'] > $a['relevance']) ? 1 : -1;
          });

          // Normalize results from 0 - 100 and delete results with
          // relevance values < 5% of the top result
          for ($x = count($searchQuery) - 1; $x >= 0; $x--) {
            if ($searchQuery[0]['relevance'] * 0.05 <= $searchQuery[$x]['relevance']) {
              $searchQuery[$x]['relevance'] /= $searchQuery[0]['relevance'] * 0.01;
            } else unset($searchQuery[$x]);
          }

          // The final results list is the top slice of this data
          // limited by the 's_limit_results' value
          $_SDATA['results'] = array_slice($searchQuery, 0, $_ODATA['s_limit_results']);


          // Now loop through the remaining results to generate the
          // proper match text for each
          foreach ($_SDATA['results'] as $key => $row) {
            $_SDATA['results'][$key]['matchtext'] = array();

            // Add the page description to use as a default match text
            if (trim($row['description'])) {
              if (strlen($row['description']) > $_ODATA['s_limit_matchtext']) {
                $match = substr($row['description'], 0, $_ODATA['s_limit_matchtext'])."\u{2026}";
              } else $match = $row['description'];
              $_SDATA['results'][$key]['matchtext'][] = array(
                'rank' => 0,
                'text' => $match
              );
            }

            // Loop through each term to capture matchtexts
            foreach ($_SDATA['terms'] as list($type, $term, $pcre)) {
              switch ($type) {
                case 'filetype': break;
                case 'exclude': break;

                case 'phrase':
                case 'term':

                  // Split the content on the current term
                  $splitter = preg_split($pcre, $row['content'], 0,
                    PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);

                  // For each match, gather the appropriate amount of match
                  // text from either side of it
                  foreach ($splitter as $split) {
                    if (preg_match($pcre, $split[0]) || count($splitter) == 1) {
                      if (count($splitter) == 1) {
                        // Grab some random content if there were no
                        // matches in the content
                        $offset = mt_rand(0, strlen($row['content']) - $_ODATA['s_limit_matchtext']);
                      } else $offset = floor(max(0, $split[1] - (strlen($term) + $_ODATA['s_limit_matchtext']) / 2));
                      $match = trim(substr($row['content'], $offset, $_ODATA['s_limit_matchtext']));

                      // Add appropriate ellipses
                      if ($offset + ((strlen($term) + $_ODATA['s_limit_matchtext']) / 2) < strlen($row['content']))
                        $match .= "\u{2026}";

                      if ($offset) $match = "\u{2026}".$match;

                      $_SDATA['results'][$key]['matchtext'][] = array(
                        'rank' => 0,
                        'text' => $match
                      );
                    }
                  }

              }
            }

            // For each found match text, add a point for every time a
            // term is found in the match text; triple points for phrase
            // or important (+) matches
            foreach ($_SDATA['results'][$key]['matchtext'] as $mkey => $matchtext) {
              foreach ($_SDATA['terms'] as $tkey => list($type, $term, $pcre)) {
                switch ($type) {
                  case 'filetype': break;
                  case 'exclude': break;

                  case 'phrase':
                  case 'term':
                    $points = preg_match_all($pcre, $matchtext['text']); // / ($tkey + 1);
                    if ($type == 'phrase') $points *= 3;
                    $_SDATA['results'][$key]['matchtext'][$mkey]['rank'] += $points;

                }
              }
            }

            // Sort the match texts by score
            usort($_SDATA['results'][$key]['matchtext'], function($a , $b) {
              if ($b['rank'] == $a['rank']) return 0;
              return ($b['rank'] > $a['rank']) ? 1 : -1;
            });

            // Use the top-ranked match text as the official match text
            // Run an mb_convert_encoding() in case we chopped a UTF-8
            // character in the middle of its bytes
            $_SDATA['results'][$key]['matchtext'] = mb_convert_encoding(
              $_SDATA['results'][$key]['matchtext'][0]['text'],
              'UTF-8',
              'UTF-8'
            );

            // Unset result values we no longer need so they don't
            // bloat the cache unnecessarily
            unset($_SDATA['results'][$key]['content']);
            unset($_SDATA['results'][$key]['keywords']);
            unset($_SDATA['results'][$key]['weighted']);
            unset($_SDATA['results'][$key]['multi']);
            unset($_SDATA['results'][$key]['phrase']);
          }

        } else $_ORCINUS->addError('Database error reading results: '.$err[2]);


      // ***** Else this is a cached set of results
      } else $_SDATA['results'] = $_SDATA['cache']['data'];


      // Limit $_REQUEST['page'] to within boundaries
      $_REQUEST['page'] = (!empty($_REQUEST['page'])) ? max(1, (int)$_REQUEST['page']) : 1;
      $_SDATA['pages'] = max(1, ceil(count($_SDATA['results']) / $_ODATA['s_results_pagination']));
      $_REQUEST['page'] = min($_SDATA['pages'], $_REQUEST['page']);

      // Database log (and potentially cache) this page only if:
      // - This is not a JSON output request
      // - The user is visiting page 1 of results
      // - Their IP does not match the IP of the previous request for
      //   this same query
      // - ... but if their IP *does* match, check that their last
      //   request for this same query was more than ten seconds ago
      if (!isset($_REQUEST['json']) && $_REQUEST['page'] == 1 &&
           ($_SDATA['cache']['ip'] != $_SERVER['REMOTE_ADDR'] ||
            $_SDATA['cache']['stamp'] + 10 < time())) {

        // Delete the cache from all other searches for this query
        $clear = $_DDATA['pdo']->prepare(
          'UPDATE `'.$_DDATA['tbprefix'].'query` SET `cache`=\'\'
             WHERE `query`=:query;'
        );
        $clear->execute(array('query' => $_SDATA['formatted']));
        $err = $clear->errorInfo();
        if ($err[0] != '00000')
          $_ORCINUS->addError('Could not clear previous search cache: '.$err[2]);

        // If we are caching search results
        if ($_ODATA['s_limit_cache']) {

          // If this search query hasn't been cached yet
          if (!is_array($_SDATA['cache']['data'])) {

            // JSON encode and potentially gzip the results for storage
            $searchCache = json_encode($_SDATA['results'], JSON_INVALID_UTF8_IGNORE);
            if (function_exists('gzcompress'))
              $searchCache = gzcompress($searchCache);

          // else use the cache we retrieved from the database
          } else $searchCache = $_SDATA['cache']['raw'];

        } else $searchCache = '';

        $insertQuery = $_DDATA['pdo']->prepare(
          'INSERT INTO `'.$_DDATA['tbprefix'].'query` SET
            `query`=:query,
            `results`=:results,
            `stamp`=UNIX_TIMESTAMP(),
            `ip`=INET_ATON(:ipaddr),
            `cache`=:cache
          ;'
        );
        $insertQuery->execute(array(
          'query' => $_SDATA['formatted'],
          'results' => count($_SDATA['results']),
          'ipaddr' => $_SERVER['REMOTE_ADDR'],
          'cache' => $searchCache
        ));
        if (!$insertQuery->rowCount()) {
          $_ORCINUS->addError('Could not cache search results');
          $err = $insertQuery->errorInfo();
          if ($err[0] != '00000')
            $_ORCINUS->addError('MySQL error: '.$err[2]);
        }
      }


      // ***** We have completed searching and caching! *****
      // Now it's time to focus on how we will format the data we
      // obtained for display to the viewer


      // Get a slice of the results that corresponds to the current
      // search results pagination page we are on
      $resultsPage = array_slice(
        $_SDATA['results'],
        ($_REQUEST['page'] - 1) * $_ODATA['s_results_pagination'],
        $_ODATA['s_results_pagination']
      );

      // If we have more than zero results...
      if (count($resultsPage)) {
        $_ORCINUS->searchable->searched->results = new stdClass();
        $_ORCINUS->searchable->searched->results->result_list = array();

        // Prepare PCRE for removing base domains
        if (count($_RDATA['s_crawldata_domains']) == 1)
          $repStr = '/^'.preg_quote(key($_RDATA['s_crawldata_domains']), '/').'/';

        // Do a last once-over of the results
        foreach ($resultsPage as $key => $result) {
          $_RESULT = new stdClass();

          $_RESULT->filetype = '';
          foreach ($_RDATA['s_filetypes'] as $type => $mimes)
            foreach ($mimes as $mime)
              if ($result['content_mime'] == $mime)
                $_RESULT->filetype = $type;

          // Don't display filetype of HTML pages
          if (!$_ODATA['s_show_filetype_html'])
            if ($_RESULT->filetype == 'HTML')
              $_RESULT->filetype = '';

          if ($_RESULT->filetype)
            $_RESULT->filetype = '['.$_RESULT->filetype.']';

          // Don't display category if there's only one
          if (count($_RDATA['s_category_list']) > 2) {
            $_RESULT->category = $result['category'];
          } else $_RESULT->category = '';

          // Format relevance
          $_RESULT->relevance = number_format($result['relevance'], 2, '.', '');

          // Remove base domain from URL if they are all the same
          if (count($_RDATA['s_crawldata_domains']) == 1)
            $result['url'] = preg_replace($repStr, '', $result['url']);

          // Highlight the terms in the title, url and matchtext
          $_RESULT->title = $result['title'];
          $_RESULT->url = $result['url'];
          $_RESULT->matchtext = $result['matchtext'];
          $_RESULT->description = $result['description'];
          $_RESULT->title_highlight = htmlspecialchars($result['title']);
          $_RESULT->url_highlight = htmlspecialchars($result['url']);
          $_RESULT->matchtext_highlight = htmlspecialchars($result['matchtext']);
          $_RESULT->description_highlight = htmlspecialchars($result['description']);

          foreach ($_SDATA['terms'] as list($type, $term, $pcre)) {
            switch ($type) {
              case 'filetype': break;
              case 'exclude': break;

              case 'phrase':
              case 'term':
                $_RESULT->title_highlight = preg_replace($pcre, '<strong>$1</strong>', $_RESULT->title_highlight);
                $_RESULT->url_highlight = preg_replace($pcre, '<strong>$1</strong>', $_RESULT->url_highlight);
                $_RESULT->matchtext_highlight = preg_replace($pcre, '<strong>$1</strong>', $_RESULT->matchtext_highlight);
                $_RESULT->description_highlight = preg_replace($pcre, '<strong>$1</strong>', $_RESULT->description_highlight);

            }
          }

          // Convert output back to $_ODATA['s_charset'] before storing
          if (strtoupper($_ODATA['s_charset']) != 'UTF-8') {
            $_RESULT = json_encode($_RESULT, JSON_INVALID_UTF8_IGNORE);
            $_RESULT = mb_convert_encoding($_RESULT, $_ODATA['s_charset'], 'UTF-8');
            $_RESULT = json_decode($_RESULT, true);
          }

          $_ORCINUS->searchable->searched->results->result_list[] = $_RESULT;
        }

        // If there are more than just one page of results, prepare all
        // the pagination variables for the template
        if ($_SDATA['pages'] > 1) {
          $pagination = new stdClass();
          $pagination->page_gt1 = ($_REQUEST['page'] > 1);
          $pagination->page_minus1 = $_REQUEST['page'] - 1;
          $pagination->page_list = array();
          for ($x = 1; $x <= $_SDATA['pages']; $x++) {
            $page = new stdClass();
            $page->index = $x;
            $page->current = ($x == $_REQUEST['page']);
            $pagination->page_list[] = $page;
          }
          $pagination->page_ltpages = ($_REQUEST['page'] < $_SDATA['pages']);
          $pagination->page_plus1 = $_REQUEST['page'] + 1;
          $_ORCINUS->searchable->searched->results->pagination = $pagination;
        }

        // Final numerical and stopwatch time values
        $_ORCINUS->searchable->searched->results->from = min(count($_SDATA['results']), ($_REQUEST['page'] - 1) * $_ODATA['s_results_pagination'] + 1);
        $_ORCINUS->searchable->searched->results->to = min(count($_SDATA['results']), $_REQUEST['page'] * $_ODATA['s_results_pagination']);
        $_ORCINUS->searchable->searched->results->of = count($_SDATA['results']);
        $_ORCINUS->searchable->searched->results->in = number_format(microtime(true) - $_SDATA['time'], 2, '.', '');

        $_SDATA['json'] = array_slice($_ORCINUS->searchable->searched->results->result_list, 0, 5);

      } // No results

    } // No valid terms

    // Convert request query back to $_ODATA['s_charset'] before exiting
    if (strtoupper($_ODATA['s_charset']) != 'UTF-8')
      $_REQUEST['q'] = mb_convert_encoding($_REQUEST['q'], $_ODATA['s_charset'], 'UTF-8');

  } // No request data

} // No searchable pages in search database



// IF the crawler 'time_start' is more than 'timeout_crawl'
// seconds ago, the crawler is probably stuck. Unstick it.
if (OS_getValue('sp_crawling') &&
    time() - $_ODATA['sp_time_start'] > $_ODATA['sp_timeout_crawl']) {
  OS_setValue('sp_crawling', 0);

  $reason = 'The crawler halted unexpectedly';

  if (strpos(OS_getValue('sp_log'), "\n") === false && file_exists($_ODATA['sp_log'])) {
    $log = file_get_contents($_ODATA['sp_log']);
    OS_setValue('sp_log', $log."\n".'[ERROR] '.$reason);
  } else OS_setValue('sp_log', '[ERROR] '.$eason);
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

// ***** Trigger another crawl
if ($_ODATA['sp_interval'] && !$_ODATA['sp_crawling'] &&
    time() - $_ODATA['sp_time_end_success'] > $_ODATA['sp_interval'] * 3600) {

  // If we can only trigger the crawl during certain time period
  if ($_ODATA['sp_interval_start'] != $_ODATA['sp_interval_stop']) {
    $timeNow = new DateTime();
    $timeStart = DateTime::createFromFormat('H:i:s', $_ODATA['sp_interval_start']);
    $timeStop = DateTime::createFromFormat('H:i:s', $_ODATA['sp_interval_stop']);

    // Move PM start times back one day
    if ($timeStart > $timeStop) $timeStart->modify('-1 day');

    // Make sure at least the stop time is in the future
    if ($timeNow > $timeStop) {
      $timeStart->modify('+1 day');
      $timeStop->modify('+1 day');
    }

    $allowCrawl = ($timeStart < $timeNow && $timeNow < $timeStop);

  // Otherwise always allow the crawl
  } else $allowCrawl = true;

  if ($allowCrawl) {

    // Set the key for initiating the crawler
    $md5 = md5(hrtime(true));
    OS_setValue('sp_key', $md5);

    // ***** Initialize the cURL connection
    $_cURL = OS_getConnection();
    if ($_cURL) {

      // Customize this cURL connection
      curl_setopt($_cURL, CURLOPT_POST, true);
      curl_setopt($_cURL, CURLOPT_POSTFIELDS, json_encode(array(
        'action' => 'crawl',
        'sp_key' => $_ODATA['sp_key']
      )));
      curl_setopt($_cURL, CURLOPT_HTTPHEADER, array(
        'Content-type: application/json; charset='.$_ODATA['s_charset']
      ));
      curl_setopt($_cURL, CURLOPT_CONNECTTIMEOUT, 1);
      curl_setopt($_cURL, CURLOPT_TIMEOUT, 1);

      $crawlerDir = str_replace($_ODATA['admin_install_root'], '', __DIR__);
      $crawlerURL = $_ODATA['admin_install_domain'].$crawlerDir.'/crawler.php';
      curl_setopt($_cURL, CURLOPT_URL, str_replace(' ', '%20', $crawlerURL));

      curl_exec($_cURL);

      // Error code 28 (timeout) is okay
      $errno = curl_errno($_cURL);
      if ($errno && $errno != 28) {
        $error = curl_error($_cURL);
        if ($error) $_ORCINUS->addError($error); // Hide this?
      }

      curl_close($_cURL);

    } // Could not create a connection, but don't let the user know
  }
}


// Output JSON and exit if requested
if (isset($_REQUEST['json'])) {
  header('Content-type: application/json; charset='.$_ODATA['s_charset']);
  die(json_encode($_SDATA['json'], JSON_INVALID_UTF8_IGNORE));
} ?>