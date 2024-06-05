/* ***** Orcinus Site Search - Administration UI Javascript ******** */


/**
 * Request a file from the server and trigger a download prompt
 *
 */
let os_download = function(defaultFilename, postValues) {
  fetch(new Request('./admin.php'), {
    method: 'POST',
    headers: { 'Content-type': 'application/json' },
    body: JSON.stringify(postValues)
  })
  .then((response) => {
    if (response.status === 200) {
      let ct = response.headers.get('content-type').trim();
      if (ct.indexOf('application/json') === 0) {
        response.json().then((data) => {
          if (data.status == 'Error')
            alert(data.message);
        });
      } else {
        let cd = response.headers.get('content-disposition');
        if (cd) {
          let filename = cd.match(/filename="([^"]+)"/);
          filename = (filename.length > 1) ? filename[1] : defaultFilename;
          response.blob().then((blob) => {
            let file = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
                a.href = file;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
          });
        } else alert('Something went wrong!');
      }
    }
  });
}


/**
 * Generates a readable filesize string from an integer byte-count
 *  abbr => Optional <abbr> tag with title attribute added
 */
let os_readSize = function(bytes, abbr) {
  bytes = parseInt(bytes);
  if (bytes >= 1020054733) return (Math.round((bytes * 10 / 1073741824)) / 10) + ((abbr) ? ' <abbr title="gibibytes">GiB</abbr>' : ' GiB');
  if (bytes >= 996148) return (Math.round((bytes * 10 / 1048576)) / 10) + ((abbr) ? ' <abbr title="mebibytes">MiB</abbr>' : ' MiB');
  if (bytes >= 973) return (Math.round((bytes * 10 / 1024)) / 10) + ((abbr) ? ' <abbr title="kibibytes">kiB</abbr>' : ' kiB');
  if (bytes >= 0) return bytes + ((abbr) ? ' <abbr title="bytes">B</abbr>' : ' B');
  return '';
}


// Enable Popper.js tooltips
let toolTipElems = document.querySelectorAll('[data-bs-toggle="tooltip"]');
let toolTipList = [...toolTipElems].map(elem => new bootstrap.Tooltip(elem));


/* ***** Page >> Crawler Managment ********************************* */
let countUpTimers = document.querySelectorAll('time.countup_timer');
let timeTracker = (new Date()).getTime();
let countUpPeriods = [['day', 'days'], ['hour', 'hours'], ['minute', 'minutes'], ['second', 'seconds']];
for (let x = 0; x < countUpTimers.length; x++) {
  countUpTimers[x].originalStart = parseInt(countUpTimers[x].getAttribute('data-start').trim());

  countUpTimers[x].spans = [];
  for (let y = 0; y < countUpPeriods.length; y++) {
    countUpTimers[x].spans[y] = countUpTimers[x].querySelector(':scope span[data-period="' + countUpPeriods[y][1] + '"]');
    countUpTimers[x].spans[y].tVar = countUpTimers[x].spans[y].getElementsByTagName('var')[0];
  }

  countUpTimers[x].incrementTime = function() {

    // If the start date has changed, or if we are more than 5 seconds
    // off the tracker, update all the values
    let timeNow = (new Date()).getTime();
    let dataStart = parseInt(this.getAttribute('data-start').trim());
    if (timeTracker + 5000 < timeNow || this.originalStart != dataStart) {
      this.originalStart = dataStart;

      // Update the title attribute
      let startDate = new Date(dataStart * 1000);
      this.title = startDate.toString();

      let since = Math.round((new Date()).getTime() / 1000) - dataStart;
      let periods = [];
      periods[0] = Math.floor(since / 86400); since %= 86400;
      periods[1] = Math.floor(since / 3600); since %= 3600;
      periods[2] = Math.floor(since / 60);
      periods[3] = (since %= 60) - 1;

      for (let y = 0; y < periods.length; y++)
        this.spans[y].tVar.firstChild.nodeValue = parseInt(periods[y]);

    }

    timeTracker = timeNow;

    this.spans[3].tVar.firstChild.nodeValue = parseInt(this.spans[3].tVar.firstChild.nodeValue) + 1;
    if (parseInt(this.spans[3].tVar.firstChild.nodeValue) > 59) {
      this.spans[3].tVar.firstChild.nodeValue = 0;
      this.spans[2].tVar.firstChild.nodeValue = parseInt(this.spans[2].tVar.firstChild.nodeValue) + 1;
    }
    if (parseInt(this.spans[2].tVar.firstChild.nodeValue) > 59) {
      this.spans[2].tVar.firstChild.nodeValue = 0;
      this.spans[1].tVar.firstChild.nodeValue = parseInt(this.spans[1].tVar.firstChild.nodeValue) + 1;
    }
    if (parseInt(this.spans[1].tVar.firstChild.nodeValue) > 23) {
      this.spans[1].tVar.firstChild.nodeValue = 0;
      this.spans[0].tVar.firstChild.nodeValue = parseInt(this.spans[0].tVar.firstChild.nodeValue) + 1;
    }

    // Plurals + display
    let dayPlural = (parseInt(this.spans[0].tVar.firstChild.nodeValue) == 1) ? 0 : 1;
    this.spans[0].tVar.nextSibling.nodeValue = ' ' + countUpPeriods[0][dayPlural] + ',';
    if (!parseInt(this.spans[0].tVar.firstChild.nodeValue)) {
      this.spans[0].classList.add('d-none');
    } else this.spans[0].classList.remove('d-none');

    let houPlural = (parseInt(this.spans[1].tVar.firstChild.nodeValue) == 1) ? 0 : 1;
    this.spans[1].tVar.nextSibling.nodeValue = ' ' + countUpPeriods[1][houPlural] + ',';
    if (!parseInt(this.spans[0].tVar.firstChild.nodeValue) &&
        !parseInt(this.spans[1].tVar.firstChild.nodeValue)) {
      this.spans[1].classList.add('d-none');
    } else this.spans[1].classList.remove('d-none');

    let minPlural = (parseInt(this.spans[2].tVar.firstChild.nodeValue) == 1) ? 0 : 1;
    this.spans[2].tVar.nextSibling.nodeValue = ' ' + countUpPeriods[2][minPlural] + ',';
    if (!parseInt(this.spans[0].tVar.firstChild.nodeValue) &&
        !parseInt(this.spans[1].tVar.firstChild.nodeValue) &&
        !parseInt(this.spans[2].tVar.firstChild.nodeValue)) {
      this.spans[2].classList.add('d-none');
    } else this.spans[2].classList.remove('d-none');

    let secPlural = (parseInt(this.spans[3].tVar.firstChild.nodeValue) == 1) ? 0 : 1;
    this.spans[3].tVar.nextSibling.nodeValue = ' ' + countUpPeriods[3][secPlural];
  };

  if (countUpTimers[x].classList.contains('active')) {
    countUpTimers[x].interval = setInterval(function() {
      countUpTimers[x].incrementTime();
    }, 1000);
  }
}


/* ***** Page >> Page Index **************************************** */
let os_page_index_download = document.getElementById('os_page_index_download');
if (os_page_index_download) {
  os_page_index_download.addEventListener('click', function() {
    os_download('page-index.txt', {
      action: 'download',
      content: 'page_index'
    });
  }, false);
}

let select_pagination = document.querySelectorAll('select[name="os_index_select_pagination"]');
for (let x = 0; x < select_pagination.length; x++) {
  select_pagination[x].addEventListener('change', function() {
    let hidden_pagination = document.querySelector('input[name="os_index_hidden_pagination"]');
    if (hidden_pagination) {
      hidden_pagination.value = this.value;
      this.form.submit();
    }
  }, false);
}

let os_index_pagination_page_select = document.querySelectorAll('select[name="os_index_pagination_page_select"]');
for (let x = 0; x < os_index_pagination_page_select.length; x++) {
  os_index_pagination_page_select[x].addEventListener('change', function() {
    window.location.href = '?ipage=' + this.value;
  }, false);
}

let os_index_pagination_bars = document.querySelectorAll('nav > ul.pagination');
for (let x = 0; x < os_index_pagination_bars.length; x++) {
  os_index_pagination_bars[x].items = os_index_pagination_bars[x].getElementsByTagName('li');
  os_index_pagination_bars[x].checkWidth = function() {
    let totalWidth = 0;
    for (let y = 0; y < this.items.length; y++) {
      if (this.items[y].id != 'os_pagination_jump') {
        this.items[y].classList.remove('d-none');
        totalWidth += this.items[y].offsetWidth;
      }
    }
    for (let y = 1; y < this.items.length - 1; y++) {
      if (totalWidth / this.offsetWidth > 0.75) {
        this.items[y].classList.add('d-none');
        if (this.items[y].id == 'os_pagination_jump')
          this.items[y].classList.remove('d-none');
      } else if (this.items[y].id == 'os_pagination_jump')
        this.items[y].classList.add('d-none');
    }
  }
  os_index_pagination_bars[x].checkWidth();
}
if (os_index_pagination_bars.length) {
  window.addEventListener('resize', function() {
    for (let x = 0; x < os_index_pagination_bars.length; x++)
      os_index_pagination_bars[x].checkWidth();
  }, false);
}

let os_index_filter_text = document.querySelector('input[name="os_index_filter_text"]');
let os_index_filter_text_clear = document.querySelector('button[name="os_index_filter_text_clear"]');
if (os_index_filter_text_clear && os_index_filter_text) {
  os_index_filter_text_clear.addEventListener('click', function() {
    os_index_filter_text.value = '';
    let os_submit = document.querySelector('button[name="os_submit"][value="os_index_filter_text"]');
    if (os_submit) os_submit.click();
  }, false);
}

let os_index_filter_by_category = document.querySelector('select[name="os_index_filter_by_category"]');
if (os_index_filter_by_category) {
  os_index_filter_by_category.addEventListener('change', function() {
    let new_filter_category = document.querySelector('input[name="os_index_new_filter_category"]');
    if (new_filter_category) {
      new_filter_category.value = this.value;
      this.form.submit();
    }
  }, false);
}

let os_index_filter_by_status = document.querySelector('select[name="os_index_filter_by_status"]');
if (os_index_filter_by_status) {
  os_index_filter_by_status.addEventListener('change', function() {
    let new_filter_status = document.querySelector('input[name="os_index_new_filter_status"]');
    if (new_filter_status) {
      new_filter_status.value = this.value;
      this.form.submit();
    }
  }, false);
}

let os_show_page_titles = document.getElementById('os_show_page_titles');
let os_index_table = document.getElementById('os_index_table');
if (os_show_page_titles && os_index_table) {
  if (os_index_table.classList.contains('show-page-titles'))
    os_show_page_titles.checked = 'checked';

  os_show_page_titles.addEventListener('change', function() {
    if (this.checked) {
      os_index_table.classList.add('show-page-titles');
    } else os_index_table.classList.remove('show-page-titles');

    fetch(new Request('./admin.php'), {
      method: 'POST',
      headers: { 'Content-type': 'application/json' },
      body: JSON.stringify({
        action: 'setsession',
        variable: 'index_show_page_titles',
        value: (this.checked) ? 'on' : 'off'
      })
    })
    .then((response) => response.text())
    .then((data) => {
      try {
        data = JSON.parse(data);
      } catch(e) { 
        data = {
         'status': 'Error',
         'message': 'Invalid response from server'
        };
      }

      if (data.status != 'Success')
        console.log('Could not save session variable. Reason: ' + data.message);
    });
  }, false);
}

let os_index_check_all = document.querySelectorAll('input[name="os_index_check_all"]');
for (let x = 0; x < os_index_check_all.length; x++) {
  os_index_check_all[x].addEventListener('click', function() {
    let os_index_pages = document.querySelectorAll('input[name="os_index_pages[]"]');
    for (let y = 0; y < os_index_pages.length; y++)
      os_index_pages[y].checked = this.checked;
    for (let y = 0; y < os_index_check_all.length; y++)
      os_index_check_all[y].checked = this.checked;
  }, false);
}

let os_index_pages_last_checked = false;
let os_index_pages = document.querySelectorAll('input[name="os_index_pages[]"]');
for (let x = 0; x < os_index_pages.length; x++) {
  os_index_pages[x].addEventListener('click', function(e) {
    let thisIndex = parseInt(this.getAttribute('data-index'));
    if (e.shiftKey && os_index_pages_last_checked !== false) {
      let i = os_index_pages_last_checked;
      do {
        if (i < thisIndex) { i++; } else i--;
        let box = document.querySelector('input[name="os_index_pages[]"][data-index="' + i + '"]');
        if (box) box.checked = 'checked';
      } while (i != thisIndex);
      os_index_pages_last_checked = thisIndex;
    } else if (this.checked) {
      os_index_pages_last_checked = thisIndex;
    } else os_index_pages_last_checked = false;
  }, false);
}

let os_index_select_action = document.querySelectorAll('select[name="os_index_select_action"]');
let os_index_with_selected = document.querySelectorAll('button[name="os_submit"][value="os_index_with_selected"]');
for (let x = 0; x < os_index_with_selected.length; x++) {
  os_index_with_selected[x].addEventListener('click', function(e) {
    let any_checked = false;
    for (let y = 0; y < os_index_pages.length && !any_checked; y++)
      if (os_index_pages[y].checked) any_checked = true;

    if (any_checked) {
      let sib_select = this.parentNode.getElementsByTagName('select')[0];
      for (let z = 0; z < os_index_select_action.length; z++)
        if (os_index_select_action[z] != sib_select)
          os_index_select_action[z].selectedIndex = sib_select.selectedIndex;

      switch (sib_select.value) {
        case 'delete':
          if (confirm('Are you sure you\'d like to delete the selected pages from the search database?')) {
            return true;
          }
          break;

        case 'category':
          let new_category = prompt('Enter a category name to apply to the selected pages. Maximum length is 30 characters, but shorter is better.');
          if (new_category) {
            let os_apply_new_category = document.querySelector('input[name="os_apply_new_category"]');
            if (os_apply_new_category) os_apply_new_category.value = new_category;
            return true;
          }
          break;

        case 'priority':
          let new_priority = prompt('Enter a new priority value for these pages. A valid value is bewteen 0.0 and 1.0 inclusive.');
          if (new_priority) {
            let os_apply_new_priority = document.querySelector('input[name="os_apply_new_priority"]');
            if (os_apply_new_priority) os_apply_new_priority.value = new_priority;
            return true;
          }
          break;

        case 'unlisted':
          return true;

        default:
          alert('Select an action');

      }
    } else alert('No pages selected');

    e.preventDefault();
    return false;
  }, false);
}


/* ***** Page >> Search Statistics ********************************* */
let bars = document.querySelectorAll('table.bar-graph td[data-value]');
for (let x = 0; x < bars.length; x++) {
  bars[x].style.height = '0px';
  bars[x].style.transition = 'height 0.8s ease';

  bars[x].tbody = bars[x].parentNode.parentNode;
  if (!bars[x].tbody.dataMaxValue) bars[x].tbody.dataMaxValue = 2;
  bars[x].tbody.dataMaxValue = Math.max(
    parseFloat(bars[x].getAttribute('data-value')),
    bars[x].tbody.dataMaxValue
  );

  setTimeout((function(elem) { return function() {
    elem.style.height = (parseFloat(elem.getAttribute('data-value') / elem.tbody.dataMaxValue) * elem.tbody.offsetHeight) + 'px';
  }})(bars[x]), 5);
}

let graphs = document.querySelectorAll('table.bar-graph');
let ticks = [1, 5, 10, 50, 100, 500, 1000, 5000, 10000, 50000, 100000, 500000, 1000000];
for (let x = 0; x < graphs.length; x++) {
  let thead = graphs[x].getElementsByTagName('thead');
  if (!thead.length) {
    let tbody = graphs[x].getElementsByTagName('tbody')[0];

    // Adjust background lines
    if (tbody.dataMaxValue) {
      graphs[x].unitHeight = tbody.offsetHeight / tbody.dataMaxValue;

      let divisions = 1;
      do {
        graphs[x].interval = tbody.dataMaxValue / divisions;
      } while (
        graphs[x].interval * graphs[x].unitHeight > 20 &&
        divisions < tbody.dataMaxValue &&
        divisions++ < tbody.offsetHeight / 50
      );

      let divVal = Math.floor(Math.log10(tbody.dataMaxValue));
      graphs[x].interval = Math.max(1, Math.round(graphs[x].interval / ticks[divVal])) * ticks[divVal] * graphs[x].unitHeight;

    } else {
      graphs[x].unitHeight = tbody.offsetHeight;
      graphs[x].interval = tbody.offsetHeight;
    }

        thead = document.createElement('thead');
      let tr = document.createElement('tr');
          tr.classList.add('d-flex', 'flex-column-reverse', 'justify-content-end');
        for (let y = 0, z = Math.ceil(tbody.offsetHeight / graphs[x].interval); y < z; y++) {
          let td = document.createElement('td');
              td.classList.add('d-flex', 'flex-column', 'justify-content-end', 'text-end');
              td.style.height = (y < z - 1) ? Math.min(tbody.offsetHeight, graphs[x].interval) + 'px' : 'auto';
            let small = document.createElement('small');
                small.appendChild(document.createTextNode(Math.round(y * graphs[x].interval / graphs[x].unitHeight)));
              td.appendChild(small);
            tr.appendChild(td);
        }
        thead.appendChild(tr);
      graphs[x].insertBefore(thead, tbody);

        tbody.style.backgroundClip = 'content-box';
        tbody.style.background = 'repeating-linear-gradient(to top, rgba(0,0,0,0.4) 0px, transparent 1px, transparent ' + graphs[x].interval + 'px)';
  }
}


/* ***** Page >> Query Log ***************************************** */
let os_admin_query_log_display = document.querySelector('select[name="os_admin_query_log_display"]');
if (os_admin_query_log_display) {
  os_admin_query_log_display.addEventListener('change', function() {
    this.form.submit();
  }, false);
}

let os_queries_tbody = document.getElementById('os_queries_tbody');
if (os_queries_tbody) {
  let os_queries_sort = function() {
    let self = this;
    let sorted = this.sorted;

    Object.keys(os_queries_columns).forEach(key => {
      os_queries_columns[key].parentNode.classList.remove('os_sorting', 'os_asc', 'os_desc');
      os_queries_columns[key].sorted = '';
    });

    if (sorted == 'desc') {
      this.sorted = 'asc';
    } else this.sorted = 'desc';

    this.parentNode.classList.add('os_sorting', 'os_' + this.sorted);

    let row_list = Array.prototype.slice.call(
      os_queries_tbody.getElementsByTagName('tr'), 0);

    row_list.sort(function(a, b) {
      let adval = parseInt(a.cells[self.index].getAttribute('data-value'));
      let bdval = parseInt(b.cells[self.index].getAttribute('data-value'));
      if (bdval == adval) return 0;
      if (self.sorted == 'desc') {
        return (bdval > adval) ? 1 : -1;
      } else return (bdval > adval) ? -1 : 1;
    });

    // Add back the sorted rows
    for (let x = 0; x < row_list.length; x++)
      os_queries_tbody.appendChild(row_list[x]);

  };

  let os_queries_columns = {
    query: document.getElementById('os_queries_query'),
    hits: document.getElementById('os_queries_hits'),
    results: document.getElementById('os_queries_results'),
    stamp: document.getElementById('os_queries_stamp')
  };
  let index = 0;
  Object.keys(os_queries_columns).forEach(key => {
    os_queries_columns[key].addEventListener('click', os_queries_sort, false);
    os_queries_columns[key].index = index++;
  });
  os_queries_columns.hits.sorted = 'desc';
}

let queriesModal = document.getElementById('queriesModal');
if (queriesModal) {
  queriesModal.addEventListener('show.bs.modal', function(e) {
    let btn = e.relatedTarget;

    let parentRow = btn.parentNode;
    while (parentRow.nodeName != 'TR')
      parentRow = parentRow.parentNode;

    let values = parentRow.querySelectorAll('[data-value]');
 
    let hidden_query = document.getElementsByName('os_query_log_hidden_query')[0];
    hidden_query.value = btn.title;

    let hidden_ip = document.getElementsByName('os_query_log_hidden_ip')[0];
    hidden_ip.value = values[5].getAttribute('data-value');

    values = {
      query: btn.title,
      hits: values[1].getAttribute('data-value'),
      hits_unique: values[2].getAttribute('data-value'),
      results: values[3].getAttribute('data-value'),
      stamp: new Date(parseInt(values[4].getAttribute('data-value')) * 1000).toString(),
      ip: values[5].innerHTML
    };

    Object.keys(values).forEach(keys => {
      let dd = document.getElementById('os_queries_modal_' + keys);
      dd.innerHTML = values[keys];
    });
  }, false);
}

let queriesDelete = document.querySelector('select[name="os_query_log_delete"]');
let queriesDeleteSubmit = document.querySelector('button[value="os_query_log_delete"]');
if (queriesDeleteSubmit) {
  queriesDeleteSubmit.addEventListener('click', function(e) {
    let ask;
    switch (queriesDelete.value) {
      case 'query': ask = 'query'; break;
      case 'ip': ask = 'IP address'; break;
      case 'assoc': ask = 'query and queries from all associated IP addresses'; break;
    }

    if (ask && confirm('Are you sure you want to delete this ' + ask + ' from the Query Log?'))
      return true;

    e.preventDefault();
    return false;
  }, false);
}

let os_query_log_download = document.getElementById('os_query_log_download');
if (os_query_log_download) {
  os_query_log_download.addEventListener('click', function() {
    os_download('query-log.txt', {
      action: 'download',
      content: 'query_log'
    });
  }, false);
}


/* ***** Crawler Modal ********************************************* */
let os_get_crawl_progress = function(getLog) {
  if (!getLog) getLog = !os_crawl_start.complete;

  fetch(new Request('./crawler.php'), {
    method: 'POST',
    headers: { 'Content-type': 'application/json' },
    body: JSON.stringify({
      action: 'progress',
      log: getLog,
      grep: document.querySelector('input[name="os_crawl_grep"]:checked').value
    })
  })
  .then((response) => response.text())
  .then((data) => {
    try {
      data = JSON.parse(data);
    } catch(e) { 
      console.log('Invalid JSON received: ' + data);
      return;
    }

    // If crawl is complete...
    if (os_crawl_start.complete) {

      let os_countup_time_end = document.getElementById('os_countup_time_end');
      let os_countup_time_crawl = document.getElementById('os_countup_time_crawl');

      // Check if a new crawl has started and is ongoing
      if (data.status == 'Crawling') {
        os_crawl_start.disabled = 'disabled';

        os_crawl_progress.value = 0
        os_crawl_progress.max = 1;
        os_crawl_progress.setAttribute('data-progress', '');
        os_crawl_progress.innerHTML = '0%';

        os_crawl_start.allow_grep = false;
        os_crawl_start.complete = false;

        os_crawl_cancel.disabled = '';
        os_crawl_log_download.disabled = 'disabled';
        os_crawl_start.innerHTML = 'Crawling...';
        os_crawl_navbar.innerHTML = 'Crawling...';

        if (os_countup_time_end) {
          clearInterval(os_countup_time_end.interval);
          os_countup_time_end.parentNode.innerHTML = '<em>Currently crawling...</em>';
        }

        if (os_countup_time_crawl) {
          os_countup_time_crawl.classList.add('active');
          os_countup_time_crawl.setAttribute('data-start', data.time_start);
          os_countup_time_crawl.interval = setInterval(function() {
            os_countup_time_crawl.incrementTime();
          }, 1000);
        }

        // Start an interval progress check
        clearInterval(os_crawl_interval);
        os_crawl_interval = setInterval(os_get_crawl_progress, 1000);

      // Else check if the given time_end is later than the time this
      // page was loaded; if so, a crawl has finished after this page
      // was loaded; if so, reload the page
      } else if (os_crawl_loaded < data.time_end) {

        if (os_countup_time_crawl)
          clearInterval(os_countup_time_crawl.interval);

        // Check if the crawler modal window is open
        if (crawlerModal && crawlerModal.classList.contains('show')) {

          // Don't refresh the page until the user closes the modal
          crawlerModal.addEventListener('hide.bs.modal', function() {
            window.location.reload();
          }, false);
        } else window.location.reload();
      }
    }

    if (!data.tail) return;

    if (data.status == 'Crawling' && data.time_crawl > data.timeout_crawl) {
      clearInterval(os_crawl_interval);
      data.status = 'Complete';

      alert('The Crawl appears to have timed out. Canceling...');

      os_crawl_cancel.force = true;
      os_crawl_cancel.click();
    }

    os_crawl_start.allow_grep = true;
    os_crawl_log.value = data.tail;

    if (os_crawl_interval) {
      os_crawl_progress.value = data.progress[0];
      os_crawl_progress.max = data.progress[1];
      os_crawl_progress.setAttribute('data-progress', data.progress[0] + ' / ' + data.progress[1]);
      os_crawl_progress.innerHTML = Math.ceil(data.progress[0] / data.progress[1]) + '%';
      os_crawl_log.scrollTop = os_crawl_log.scrollHeight;

      if (os_crawl_data_transferred)
        os_crawl_data_transferred.innerHTML = os_readSize(data.data_transferred, true);

      if (os_crawl_data_stored)
        os_crawl_data_stored.innerHTML = 0;

      if (os_crawl_links_crawled)
        os_crawl_links_crawled.innerHTML = data.progress[0] + ' / ' + data.progress[1];

      if (os_crawl_pages_stored)
        os_crawl_pages_stored.innerHTML = 0;
    }

    if (!os_crawl_start.complete && data.status == 'Complete') {
      os_crawl_cancel.disabled = 'disabled';
      os_crawl_log_download.disabled = '';
      os_crawl_start.complete = true;

      os_crawl_start.innerHTML = 'Crawl Complete';
      os_crawl_navbar.innerHTML = 'Crawler';
      os_crawl_interval = false;

      clearInterval(os_crawl_interval);
      os_crawl_interval = setInterval(os_get_crawl_progress, 5000);
    }
  });
};

let os_crawl_interval;
let os_crawl_loaded = parseInt((new Date()).getTime() / 1000);
let os_crawl_start = document.getElementById('os_crawl_start');
let os_crawl_navbar = document.getElementById('os_crawl_navbar');
let os_crawl_cancel = document.getElementById('os_crawl_cancel');
let os_crawl_grep = document.querySelectorAll('input[name="os_crawl_grep"]');
let os_crawl_progress = document.getElementById('os_crawl_progress');
let os_crawl_log = document.getElementById('os_crawl_log');
let os_crawl_log_download = document.getElementById('os_crawl_log_download');

let os_crawl_data_transferred = document.getElementById('os_crawl_data_transferred');
let os_crawl_data_stored = document.getElementById('os_crawl_data_stored');
let os_crawl_links_crawled = document.getElementById('os_crawl_links_crawled');
let os_crawl_pages_stored = document.getElementById('os_crawl_pages_stored');

os_crawl_cancel.force = false;
os_crawl_cancel.reason = '';
os_crawl_start.allow_grep = false;
os_crawl_start.complete = true;
os_crawl_start.addEventListener('click', function(e) {
  e.preventDefault();

  os_crawl_start.disabled = 'disabled';
  os_crawl_start.innerHTML = 'Starting Crawl...';
  os_crawl_navbar.innerHTML = 'Starting Crawl...';
  os_crawl_log.value = '';

  os_crawl_progress.value = 0
  os_crawl_progress.max = 1;
  os_crawl_progress.setAttribute('data-progress', '');
  os_crawl_progress.innerHTML = '0%';

  os_crawl_start.allow_grep = false;
  os_crawl_start.complete = false;

  // Create a crawl key
  fetch(new Request('./admin.php'), {
    method: 'POST',
    headers: { 'Content-type': 'application/json' },
    body: JSON.stringify({ action: 'setkey' })
  })

  // If we successfully set a crawl key, start the crawl with it
  .then((response) => response.text())
  .then((data) => {
    try {
      data = JSON.parse(data);
    } catch(e) { 
      data = {
       'status': 'Error',
       'message': 'Invalid key response from server'
      };
    }

    if (data.status == 'Success') {
      os_crawl_cancel.disabled = '';
      os_crawl_log_download.disabled = 'disabled';
      os_crawl_start.innerHTML = 'Crawling...';
      os_crawl_navbar.innerHTML = 'Crawling...';

      let os_countup_time_end = document.getElementById('os_countup_time_end');
      if (os_countup_time_end) {
        clearInterval(os_countup_time_end.interval);
        os_countup_time_end.parentNode.innerHTML = '<em>Currently crawling...</em>';
      }

      let os_countup_time_crawl = document.getElementById('os_countup_time_crawl');
      if (os_countup_time_crawl) {
        os_countup_time_crawl.classList.add('active');
        os_countup_time_crawl.setAttribute('data-start', parseInt((new Date()).getTime() / 1000));
        os_countup_time_crawl.interval = setInterval(function() {
          os_countup_time_crawl.incrementTime();
        }, 1000);
      }

      fetch(new Request('./crawler.php'), {
        method: 'POST',
        headers: { 'Content-type': 'application/json' },
        body: JSON.stringify({ action: 'crawl', sp_key: data.sp_key })
      })
      .then((response) => {
        if (response.status === 200) {
          response.text().then((data) => {
            try {
              data = JSON.parse(data);
              console.log(data);
            } catch(e) { 
              console.log('Invalid JSON received: ' + data);
            }
          });

        // Cancel immediately if we get a 500 response
        // 524 = Cloudflare timeout, the crawler is still running
        } else if (response.status >= 500 && response.status != 524) {
          clearInterval(os_crawl_interval);

          os_crawl_cancel.reason = 'The crawler unexpectedly halted with HTTP response code ' + response.status + ': ' + response.statusText;

          alert(
           'Error: ' + os_crawl_cancel.reason + "\n" +
           'The crawl will be cancelled and reset.'
          );

          console.error('Error: ', error);
          os_crawl_cancel.force = true;
          os_crawl_cancel.click();
        }
      });

      // Start an interval progress check
      clearInterval(os_crawl_interval);
      os_crawl_interval = setInterval(os_get_crawl_progress, 1000);

    } else if (data.status == 'Error') {
      os_crawl_log.value = data.message;
      os_crawl_start.innerHTML = 'Couldn\'t Start Crawl';
      os_crawl_navbar.innerHTML = 'Crawler';

      setTimeout(function() {
        os_crawl_start.disabled = '';
        os_crawl_start.innerHTML = 'Start Crawl';
      }, 5000);
    }
  });

  return false;
}, false);


// If start button is disabled on pageload, then the crawler is already running
if (os_crawl_start.disabled) {

  // Start an interval progress check
  os_crawl_start.complete = false;
  os_crawl_interval = setInterval(os_get_crawl_progress, 1000);

// Else ping the server every five seconds to see if a crawl has started
} else os_crawl_interval = setInterval(os_get_crawl_progress, 5000);


os_crawl_cancel.addEventListener('click', function() {
  if (this.force || window.confirm('Are you sure you want to cancel the crawl currently in progress?')) {
    if (this.force || !this.disabled) {
      fetch(new Request('./crawler.php'), {
        method: 'POST',
        headers: { 'Content-type': 'application/json' },
        body: JSON.stringify({
          action: 'cancel',
          force: os_crawl_cancel.force,
          reason: os_crawl_cancel.reason
        })
      })
      .then((response) => response.text())
      .then((data) => {
        try {
          data = JSON.parse(data);
          if (data.status == 'Success')
            os_get_crawl_progress(true);
        } catch(e) { 
          console.log('Invalid JSON received: ' + data);
        }
      });
    }
    this.force = false;
  };
}, false);

os_crawl_log_download.addEventListener('click', function() {
  os_download('crawl-log.txt', {
    action: 'download',
    content: 'crawl_log',
    grep: document.querySelector('input[name="os_crawl_grep"]:checked').value
  });
}, false);

for (let x = 0; x < os_crawl_grep.length; x++) {
  os_crawl_grep[x].addEventListener('input', function() {
    if (os_crawl_start.allow_grep ||
        crawlerModal.classList.contains('crawler-log')) {
      os_get_crawl_progress(true);
    }
  }, false);
}

let crawlerModal = document.getElementById('crawlerModal');
crawlerModal.addEventListener('show.bs.modal', function(e) {
  let btn = e.relatedTarget;
  let label = document.getElementById('crawlerModalLabel');

  os_crawl_log.value = '';

  switch (btn.getAttribute('data-bs-crawl')) {
    case 'run':
      this.classList.remove('crawler-log');
      label.firstChild.nodeValue = 'Run Crawler Manually';

      os_crawl_start.allow_grep = false;

      os_crawl_progress.value = 0;
      os_crawl_progress.max = 1;
      os_crawl_progress.setAttribute('data-progress', '');
      os_crawl_progress.innerHTML = '';
      break;

    case 'log':
    default:
      this.classList.add('crawler-log');
      label.firstChild.nodeValue = 'View Crawler Log';

      os_get_crawl_progress(true);

  }
}, false);
