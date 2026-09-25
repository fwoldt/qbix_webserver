(function () {
  // Prometheus text exposition, grouped by family: # HELP, # TYPE, samples.
  var fams = {}, order = [];
  function fam(n) { if (!fams[n]) { fams[n] = { name: n, help: '', type: '', rows: [] }; order.push(n); } return fams[n]; }
  function base(n) { return n.replace(/_(bucket|sum|count|total|created)$/, ''); }
  (METRICS_TEXT || '').split('\n').forEach(function (line) {
    var m;
    if ((m = line.match(/^# HELP (\S+) (.*)$/))) { fam(m[1]).help = m[2]; return; }
    if ((m = line.match(/^# TYPE (\S+) (\S+)/))) { fam(m[1]).type = m[2]; return; }
    if (!line || line.charAt(0) === '#') return;
    if ((m = line.match(/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{[^}]*\})?\s+(\S+)/))) {
      var n = fams[m[1]] ? m[1] : (fams[base(m[1])] ? base(m[1]) : m[1]);
      fam(n).rows.push({ metric: m[1], labels: m[2] ? m[2].slice(1, -1) : '', value: m[3] });
    }
  });
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function bytes(x) {
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    while (Math.abs(x) >= 1024 && i < u.length - 1) { x /= 1024; i++; }
    return (i ? x.toFixed(1) : x) + ' ' + u[i];
  }
  function num(v, name) {
    var x = Number(v);
    if (isFinite(x) && /_bytes$/.test(name || '')) return bytes(x);
    if (!isFinite(x)) return v;
    if (Math.abs(x) >= 1e6 || (Math.abs(x) < 1e-3 && x !== 0)) return x.toPrecision(4);
    return Math.round(x * 1000) / 1000 === x ? x.toLocaleString() : x.toLocaleString(undefined, { maximumFractionDigits: 3 });
  }
  var grid = document.getElementById('mgrid'), box = document.getElementById('mfilter'), count = document.getElementById('mcount');
  function draw() {
    var q = (box.value || '').toLowerCase(), html = '', shown = 0;
    order.forEach(function (n) {
      var f = fams[n];
      var hay = (f.name + ' ' + f.help + ' ' + f.rows.map(function (r) { return r.metric + ' ' + r.labels; }).join(' ')).toLowerCase();
      if (q && hay.indexOf(q) === -1) return;
      shown++;
      html += '<section class="card mfam"><h2>' + esc(f.name) + (f.type ? '<span class="mtype">' + esc(f.type) + '</span>' : '') + '</h2>'
        + (f.help ? '<p>' + esc(f.help) + '</p>' : '') + '<table>'
        + f.rows.map(function (r) {
            var label = (r.metric !== f.name ? r.metric.slice(f.name.length).replace(/^_/, '') + ' ' : '') + r.labels;
            return '<tr><td class="l">' + esc(label || '—') + '</td><td class="v">' + esc(num(r.value, r.metric)) + '</td></tr>';
          }).join('') + '</table></section>';
    });
    grid.innerHTML = html || '<p class="mempty">No metrics match.</p>';
    count.textContent = shown + ' of ' + order.length + ' families';
  }
  box.addEventListener('input', draw);
  draw();
})();
