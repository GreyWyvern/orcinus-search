/* ***** Orcinus Site Search - Default Search Result Javascript **** */
let remoteValue;
if (typeof os_return_all !== 'function') {
  function os_return_all() { return []; }
  remoteValue = { url: '?q=%QUERY&json', wildcard: '%QUERY' }
}

let os_bloodhound = new Bloodhound({
  datumTokenizer: Bloodhound.tokenizers.obj.whitespace('title'),
  queryTokenizer: Bloodhound.tokenizers.whitespace,
  limit: 5,
  remote: remoteValue,
  local: os_return_all
});

$('input.os_typeahead').attr('autocomplete', 'off').typeahead({
  hint: true,
  highlight: true,
  minLength: 3
}, {
  source: os_bloodhound,
  display: 'title'
}).bind('typeahead:selected', function (obj, datum) {

  // We are offline
  if (typeof os_odata != 'undefined' && typeof os_odata.jw_depth == 'string') {
    window.location.href = datum.url.replace(/^\//, os_odata.jw_depth);

  // Else we are online
  } else {

    // On user click of a search suggestion, add this search to the
    // query log
    fetch(new Request(window.location.origin + window.location.pathname), {
      method: 'POST',
      headers: { 'Content-type': 'application/json' },
      body: JSON.stringify({ q: datum.query, log: 'log' })
    })
    .then((response) => response.text())
    .then((data) => { window.location.href = datum.url; });
  }
});