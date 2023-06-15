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
  if (typeof os_odata.jw_depth == 'string')
    datum.url = datum.url.replace(/^\//, os_odata.jw_depth);

  window.location.href = datum.url;
});