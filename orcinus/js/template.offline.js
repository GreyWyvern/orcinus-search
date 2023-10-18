/* ********************************************************************
 * Orcinus Site Search {{version}} - Offline Javascript Search File
 *  - Generated {{date}}
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
  sp_punct: {{{sp_punct}}},
  s_latin: {{{s_latin}}},
  s_filetypes: {{{s_filetypes}}},
  s_category_list: {{{s_category_list}}}
};

let os_odata = {
  s_weights: {{{s_weights}}}
};

Object.keys(os_odata.s_weights).forEach(key => {
  os_odata.s_weights[key] = parseFloat(os_odata.s_weights[key]);
});

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
function os_page(content_mime, url, category, priority, last_modified, title, description, keywords, weighted, content) {
  this.content_mime = content_mime;
  this.url = url;
  this.category = category;
  this.priority = parseFloat(priority);
  this.last_modified = parseInt(last_modified);
  this.title = title;
  this.description = description;
  this.keywords = keywords;
  this.weighted = weighted;
  this.content = content;

  this.matchtext = [];
  this.fragment = [];

  this.relevance = 0;
  this.multi = -1;
  this.phrase = 0;
}

// ***** Search Database
let os_crawldata = [
{{#os_crawldata}}
new os_page('{{{content_mime}}}', '{{{url}}}', '{{{category}}}', {{priority}}, {{last_modified}}, '{{{title}}}', '{{{description}}}', '{{{keywords}}}', '{{{weighted}}}', '{{{words}}}'),
{{/os_crawldata}}
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

// Create the Mustache template
let os_TEMPLATE = {
  errors: false,

  online: false,
  version: '{{version}}',
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
  os_TEMPLATE.searchable.limit_query = {{s_limit_query}};
  os_TEMPLATE.searchable.limit_term_length = {{s_limit_term_length}};

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
    if ({{jw_compression}} < 100)
      os_request.q = os_request.q.replace(/"/g, '');

    if (os_request.q.length > {{s_limit_query}}) {
      os_request.q = os_request.q.substring(0, {{s_limit_query}});
      os_TEMPLATE.addError('Search query truncated to maximum ' + {{s_limit_query}} + ' characters');
    }

    os_TEMPLATE.searchable.request_q = os_request.q;

    // Split request string on quotation marks (")
    let request = (' ' + os_request.q + ' ').split('"');
    for (let x = 0; x < request.length && os_sdata.terms.length < {{s_limit_terms}}; x++) {

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
          } else if (t.length >= {{s_limit_term_length}})
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

        // Normalize punctuation
        Object.keys(os_rdata.sp_punct).forEach(key => {
          os_sdata.terms[x][1] = os_sdata.terms[x][1].replace(key, os_rdata.sp_punct[key]);
        });

        switch (os_sdata.terms[x][0]) {
          case 'filetype':
            if (os_rdata.s_filetypes[os_sdata.terms[x][1].toUpperCase()])
              for (let z = 0; z < os_rdata.s_filetypes[os_sdata.terms[x][1].toUpperCase()].length; z++)
                filetypes.push(os_rdata.s_filetypes[os_sdata.terms[x][1].toUpperCase()][z]);
            break;

          case 'exclude':
            break;

          case 'phrase':

          case 'term':

            // Regexp for later use pattern matching results
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
      let pdfList = [];
      for (let y = os_crawldata.length - 1; y >= 0; y--) {
        if (filetypes.length) {
          let allowMime = false;
          for (let x = 0; x < filetypes.length; x++)
            if (os_crawldata[y].content_mime == filetypes[x]) allowMime = true;
          if (!allowMime) {
            os_crawldata.splice(y, 1);
            continue;
          }
        }

        let addRelevance;
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
              addRelevance += os_odata.s_weights.title;

            if (os_crawldata[y].description.match(os_sdata.terms[x][2]))
              addRelevance += os_odata.s_weights.description;

            if (os_crawldata[y].keywords.match(os_sdata.terms[x][2]))
              addRelevance += os_odata.s_weights.keywords;

            if (os_crawldata[y].weighted.match(os_sdata.terms[x][2]))
              addRelevance += os_odata.s_weights.css_value;

            if (os_crawldata[y].content.match(os_sdata.terms[x][2]))
              addRelevance += os_odata.s_weights.body;

            if (addRelevance) {
              os_crawldata[y].multi++;
              os_crawldata[y].relevance += addRelevance;
            } else if (os_sdata.terms[x][0] == 'phrase')
              os_crawldata.splice(y, 1);

          }
        }

        if (os_crawldata[y].content_mime == 'application/pdf')
          pdfList.push([y, os_crawldata[y].last_modified]);

        // Calculate multipliers
        os_crawldata[y].relevance *= Math.pow(os_odata.s_weights.multi, os_crawldata[y].multi);
        os_crawldata[y].relevance *= Math.pow(os_odata.s_weights.important, os_crawldata[y].phrase);

        os_crawldata[y].relevance *= os_crawldata[y].priority;
      }

      // Apply the PDF Last Modified multiplier
      if (pdfList.length > 1) {
        for (let y = 0, diff; y < pdfList.length; y++) {
          diff = ((new Date()).getTime() / 1000 - pdfList[y][1]) / (60 * 60 * 24 * 365);
          os_crawldata[pdfList[y][0]].relevance *= os_odata.s_weights.pdflastmod ** diff;
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
        if (os_crawldata[0].relevance > 0 && os_crawldata[0].relevance * 0.05 <= os_crawldata[x].relevance) {
          os_crawldata[x].relevance /= os_crawldata[0].relevance * 0.01;
        } else os_crawldata.splice(x, 1);
      }

      // The final results list is the top slice of this data
      // limited by the 's_limit_results' value
      os_sdata.results = os_crawldata.slice(0, {{s_limit_results}});


      // Now loop through the remaining results to generate the
      // proper match text for each
      for (let x = 0; x < os_sdata.results.length; x++) {

        // Add the page description to use as a default match text
        if (os_sdata.results[x].description.trim()) {
          os_sdata.results[x].matchtext.push({
            rank: 0,
            text: os_sdata.results[x].description.substring(0, {{s_limit_matchtext}})
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
                    offset = Math.floor(Math.random() * os_sdata.results[x].content.length - {{s_limit_matchtext}});
                  } else {
                    os_sdata.results[x].fragment.push(splitter[z]);
                    offset = Math.floor(Math.max(0, caret - (splitter[z].length + {{s_limit_matchtext}}) / 2));
                  }
                  let match = os_sdata.results[x].content.substring(offset, offset + {{s_limit_matchtext}}).trim();

                  // Add appropriate ellipses
                  if (offset + ((splitter[z].length + {{s_limit_matchtext}}) / 2) < os_sdata.results[x].content.length)
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
      os_sdata.pages = Math.ceil(os_sdata.results.length / {{s_results_pagination}});
      os_request.page = Math.min(os_sdata.pages, os_request.page);


      // Get a slice of the results that corresponds to the current
      // search results pagination page we are on
      let resultsPage = os_sdata.results.slice(
        (os_request.page - 1) * {{s_results_pagination}},
        (os_request.page - 1) * {{s_results_pagination}} + {{s_results_pagination}}
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
          if (!{{s_show_filetype_html}})
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

          // Append text fragment(s) to the URL if applicable
          if ({{s_text_fragments}} && resultsPage[x].fragment.length) {
            resultsPage[x].fragment = resultsPage[x].fragment.filter(function(v, i, a) {
              return a.indexOf(v) === i;
            });
            resultsPage[x].fragment = resultsPage[x].fragment.map(function(x) {
              return x.replace(',', '%2C').replace('-', '%2D');
            });
            result.url += '#:~:text=' + resultsPage[x].fragment.join('&text=');
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
        os_TEMPLATE.searchable.searched.results.from = Math.min(os_sdata.results.length, (os_request.page - 1) * {{s_results_pagination}} + 1);
        os_TEMPLATE.searchable.searched.results.to = Math.min(os_sdata.results.length, os_request.page * {{s_results_pagination}});
        os_TEMPLATE.searchable.searched.results.of = os_sdata.results.length;
        // os_TEMPLATE.searchable.searched.results.in = Math.round(((new Date()).getTime() - os_sdata.time) / 10) / 100;

      } // No results

    } // No valid terms

  } // No request data

} // No searchable pages in search database

document.write(mustache.render(
  {{{s_result_template}}},
  os_TEMPLATE
));