/* ***** Orcinus Site Search - Default Search Result Javascript **** */

// When offline, this script relies on being in the [root]/orcinus/js/
// directory in order for offline URLs to resolve correctly. If you
// have placed your Orcinus Site Search installation in a different
// location, you will need to edit the pathOffline value below.
let scrElem = document.currentScript;
let pathOnline = scrElem.src.replace(/\/js\/[^\/]+$/, '/search.php');
let pathOffline = scrElem.src.replace(/\/orcinus\/js\/[^\/]+$/, '');

let remoteValue;
if (typeof os_return_all !== 'function') {
  var os_return_all = function() { return []; }
  remoteValue = {
    url: pathOnline + '?q=%QUERY&json',
    wildcard: '%QUERY'
  };
}

$('input.os_typeahead').attr('autocomplete', 'off').typeahead({
  hint: true,
  highlight: true,
  minLength: 3
}, {
  source: new Bloodhound({
    datumTokenizer: Bloodhound.tokenizers.obj.whitespace('title'),
    queryTokenizer: Bloodhound.tokenizers.whitespace,
    limit: 5,
    remote: remoteValue,
    local: os_return_all
  }),
  display: 'title'
}).bind('typeahead:selected', function (obj, datum) {

  // If we are online
  if (window.location.protocol != 'file:') {

    // On user click of a search suggestion, add this search query to
    // the query log and cache
    fetch(new Request(pathOnline), {
      method: 'POST',
      headers: { 'Content-type': 'application/json' },
      body: JSON.stringify({ q: datum.query, log: 'log' })
    })
    .then((response) => response.text())
    .then((data) => { window.location.href = datum.url; });

  // Else we are offline
  } else window.location.href = pathOffline + datum.url;
});