
var docTitles = {
  "README.md": "Overview",
  "why.md": "Why Not php-fpm?",
  "headers.md": "Server Headers",
  "http.md": "HTTP Mode",
  "websocket.md": "WebSocket & Rooms",
  "routing.md": "Routing",
  "framework.md": "PHP Framework",
  "configuration.md": "Configuration",
  "running.md": "Running & Building",
  "architecture.md": "Architecture",
  "dashboard.md": "Dashboard & Panel",
  "deploy.md": "Deploy & Federation",
  "api-discovery.md": "API Discovery",
  "compatibility.md": "Compatibility",
  "BENCHMARKS.md": "Benchmarks",
  "reset.md": "State Reset",
  "TestResults.md": "Test Results",
  "roadmap.md": "Roadmap",
  "license.md": "License"
};
var order = Object.keys(docTitles);

async function init() {
  var r = await fetch("/Q/docs/index.json").then(function(x){return x.json()});
  var nav = document.getElementById("nav-links");
  var html = "";
  if (r.hasReadme) html += '<a href="#README.md" onclick="load(\'README.md\');return false">Overview</a>';
  var sections = {"Getting Started":["why.md","running.md","configuration.md"],
    "Features":["headers.md","http.md","websocket.md","routing.md","framework.md"],
    "Operations":["architecture.md","dashboard.md","deploy.md","api-discovery.md"],
    "Reference":["compatibility.md","BENCHMARKS.md","reset.md","TestResults.md","roadmap.md","license.md"]};
  for (var sec in sections) {
    html += "<h2>"+sec+"</h2>";
    sections[sec].forEach(function(f) {
      if (r.files.indexOf(f) !== -1 || f === "README.md") {
        html += '<a href="#'+f+'" onclick="load(\''+f+'\');return false">' + (docTitles[f]||f) + "</a>";
      }
    });
  }
  nav.innerHTML = html;
  var hash = location.hash.slice(1);
  load(hash && (r.files.indexOf(hash)!==-1 || hash==="README.md") ? hash : "README.md");
}

async function load(file) {
  var url = file === "README.md" ? "/Q/docs/raw/README.md" : "/Q/docs/raw/" + file;
  var md = await fetch(url).then(function(r){return r.ok ? r.text() : "# Not found\n\nFile `"+file+"` not found."});
  // Fix relative links: [text](docs/foo.md) -> onclick load
  md = md.replace(/\]\(docs\/([^)]+\.md)\)/g, "](#$1)");
  md = md.replace(/\]\(\.\.\/README\.md\)/g, "](#README.md)");
  document.getElementById("content").innerHTML = marked.parse(md);
  // Fix anchor clicks
  document.querySelectorAll("#content a").forEach(function(a) {
    var href = a.getAttribute("href") || "";
    if (href.charAt(0)==="#" && href.endsWith(".md")) {
      a.onclick = function(e){e.preventDefault();load(href.slice(1));};
    }
  });
  location.hash = file;
  // Highlight nav
  document.querySelectorAll("nav a").forEach(function(a){a.classList.remove("active")});
  var active = document.querySelector('nav a[href="#'+file+'"]');
  if (active) active.classList.add("active");
  window.scrollTo(0,0);
}
window.addEventListener("hashchange", function(){
  var h = location.hash.slice(1);
  if (h && h.endsWith(".md")) load(h);
});
init();
