
var images = {{imagesJson}};
var currentView = 'grid';
var dirs = document.querySelectorAll('.item.dir:not(.up)');

// Sidebar tree
var tree = document.getElementById('tree');
if ('{{safePath}}' !== '/') {
  tree.innerHTML += '<a class="tree-item" href="../"><span class="ti">⬆</span> ..</a>';
}
dirs.forEach(function(d) {
  tree.innerHTML += '<a class="tree-item" href="' + d.getAttribute('href') + '"><span class="ti">📁</span> ' + d.querySelector('.name').textContent + '</a>';
});
if (!dirs.length && '{{safePath}}' === '/') {
  document.getElementById('sidebar').style.display = 'none';
}

// Count
document.getElementById('count').textContent = images.length + ' images, ' + (dirs.length) + ' folders';

// Grid view
var gridEl = document.getElementById('grid-view');
var selected = {};
images.forEach(function(img, i) {
  var thumb = img.href + '?w=240';
  if (img.ext === 'svg') thumb = img.href;
  var dim = img.width ? img.width+'×'+img.height : '';
  var div = document.createElement('div');
  div.className = 'grid-item';
  div.dataset.idx = i;
  div.innerHTML = '<div class="check">✓</div>'
    + '<img src="' + thumb + '" loading="lazy" alt="' + img.name + '">'
    + '<div class="info">' + img.name + '<span class="dim">' + dim + '</span></div>';
  div.onclick = function(e) {
    if (e.shiftKey || e.ctrlKey || e.metaKey || Object.keys(selected).length > 0) {
      toggleSelect(i, div);
    } else {
      openLightbox(i);
    }
  };
  div.querySelector('.check').onclick = function(e) {
    e.stopPropagation();
    toggleSelect(i, div);
  };
  gridEl.appendChild(div);
});

function toggleSelect(i, el) {
  if (selected[i]) { delete selected[i]; el.classList.remove('selected'); }
  else { selected[i] = true; el.classList.add('selected'); }
  updateActionBar();
}
function selectAll() {
  document.querySelectorAll('.grid-item').forEach(function(el) {
    var i = parseInt(el.dataset.idx);
    selected[i] = true;
    el.classList.add('selected');
  });
  updateActionBar();
}
function clearSelection() {
  selected = {};
  document.querySelectorAll('.grid-item.selected').forEach(function(el) {
    el.classList.remove('selected');
  });
  updateActionBar();
}
function updateActionBar() {
  var n = Object.keys(selected).length;
  document.getElementById('sel-count').textContent = n;
  document.getElementById('action-bar').className = 'action-bar' + (n > 0 ? ' show' : '');
}

// Bulk download
var bulkSize = 0; // 0 = original
var bulkFormat = ''; // '' = keep original
function setBulkSize(w) {
  bulkSize = w;
  document.querySelectorAll('#bulk-sizes button').forEach(function(b) {
    b.className = (w === 0 && b.textContent === 'Original') || (w && b.textContent === w+'px') ? 'active' : '';
  });
}
function setBulkFormat(f) {
  bulkFormat = f;
  document.querySelectorAll('#bulk-formats button').forEach(function(b) {
    b.className = (f === '' && b.textContent === 'Keep format') || (f && b.textContent.toLowerCase().indexOf(f) >= 0) ? 'active' : '';
  });
}
function openBulkPanel() {
  var keys = Object.keys(selected);
  document.getElementById('bulk-count').textContent = keys.length;
  var preview = document.getElementById('bulk-preview');
  preview.innerHTML = '';
  keys.forEach(function(k) {
    var img = images[k];
    var el = document.createElement('img');
    el.src = img.href + '?w=100';
    el.alt = img.name;
    preview.appendChild(el);
  });
  document.getElementById('bulk-panel').classList.add('open');
}
function closeBulkPanel() {
  document.getElementById('bulk-panel').classList.remove('open');
}
function bulkDownload() {
  var keys = Object.keys(selected);
  var files = keys.map(function(k) {
    var img = images[k];
    var href = img.href;
    if (bulkFormat && bulkFormat !== img.ext) {
      href = href.replace(/\.[^.]+$/, '.' + bulkFormat);
    }
    if (bulkSize) href += (href.indexOf('?') >= 0 ? '&' : '?') + 'w=' + bulkSize;
    return href;
  });
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = '/Q/api/images/zip';
  form.style.display = 'none';
  var input = document.createElement('input');
  input.name = 'files';
  input.value = JSON.stringify(files);
  form.appendChild(input);
  var renameInput = document.createElement('input');
  renameInput.name = 'rename';
  renameInput.value = document.getElementById('bulk-rename').checked ? '1' : '0';
  form.appendChild(renameInput);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
  closeBulkPanel();
}

function setView(v) {
  currentView = v;
  document.getElementById('list-view').style.display = v === 'list' ? '' : 'none';
  document.getElementById('grid-view').style.display = v === 'grid' ? '' : 'none';
  document.getElementById('btn-list').className = v === 'list' ? 'active' : '';
  document.getElementById('btn-grid').className = v === 'grid' ? 'active' : '';
}

function openLightbox(i) {
  var img = images[i];
  var lb = document.getElementById('lightbox');
  document.getElementById('lb-img').src = img.href;
  document.getElementById('lb-name').textContent = img.name;
  document.getElementById('lb-dims').textContent = img.width ? img.width + ' × ' + img.height + ' · ' + img.size : img.size;
  var sizes = document.getElementById('lb-sizes');
  sizes.innerHTML = '';
  // Download size options
  var widths = [320, 640, 1024, 1920];
  widths.forEach(function(w) {
    if (img.width && w >= img.width) return;
    var a = document.createElement('a');
    a.href = img.href + '?w=' + w;
    a.download = img.name.replace(/\.[^.]+$/, '') + '-' + w + 'w.' + img.ext;
    a.textContent = w + 'px';
    sizes.appendChild(a);
  });
  // WebP version
  if (img.ext !== 'webp' && img.ext !== 'svg') {
    var a = document.createElement('a');
    a.href = img.href.replace(/\.[^.]+$/, '.webp');
    a.download = img.name.replace(/\.[^.]+$/, '.webp');
    a.textContent = 'WebP';
    sizes.appendChild(a);
  }
  // Original
  var orig = document.createElement('a');
  orig.href = img.href;
  orig.download = img.name;
  orig.className = 'orig';
  orig.textContent = 'Original' + (img.width ? ' (' + img.width + 'px)' : '');
  sizes.appendChild(orig);
  lb.classList.add('open');
}
function closeLightbox(e) {
  if (e && e.target !== document.getElementById('lightbox') && e.target !== document.querySelector('.close')) return;
  document.getElementById('lightbox').classList.remove('open');
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLightbox();
});

// Default to list if no images
if (!images.length) setView('list');
