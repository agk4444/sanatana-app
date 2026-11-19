(function () {
  var form = document.getElementById('ask-form');
  var input = document.getElementById('question');
  var btn = document.getElementById('ask-btn');
  var statusEl = document.getElementById('status');
  var errorEl = document.getElementById('error');
  var resultEl = document.getElementById('result');
  var answerEl = document.getElementById('answer');
  var passagesEl = document.getElementById('passages');

  function esc(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // DocumentCloud copy of the same 304-page PDF: page refs in answers and
  // passage badges link to the exact page in the viewer (opens in a new tab).
  var DC_URL = 'https://www.documentcloud.org/documents/28922146-sanatana-dharma-book/';
  function pageUrl(page) { return DC_URL + '#document/p' + page; }

  // Link page references in rendered answer HTML: "p. 128", "PDF p.128",
  // "pp. 122-128", "page 128", "pages 122–128". One pass, so a range is
  // never re-linked as a single page; the link goes to the first page.
  function linkPageRefs(html) {
    return html.replace(/(?:PDF\s+)?p{1,2}\.\s*\d+(?:\s*[–—-]\s*\d+)?|\bpages?\s+\d+(?:\s*[–—-]\s*\d+)?/g, function (m) {
      var first = m.match(/\d+/)[0];
      return '<a href="' + pageUrl(first) + '" target="_blank" rel="noopener">' + m + '</a>';
    });
  }

  // Minimal markdown: **bold**, *italic*, - bullets, 1. numbered, paragraphs.
  // Inline formatting runs per line, so a bullet marker can never pair with
  // the next line's marker as an italic span.
  function renderMarkdown(text) {
    function inline(s) {
      var html = esc(s);
      html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
      return linkPageRefs(html);
    }
    var lines = text.split('\n');
    var out = '';
    var list = null;
    function closeList() {
      if (list) { out += '</' + list + '>'; list = null; }
    }
    lines.forEach(function (line) {
      var t = line.trim();
      if (/^[-*]\s+/.test(t)) {
        if (list !== 'ul') { closeList(); out += '<ul>'; list = 'ul'; }
        out += '<li>' + inline(t.replace(/^[-*]\s+/, '')) + '</li>';
      } else if (/^\d+[.)]\s+/.test(t)) {
        if (list !== 'ol') { closeList(); out += '<ol>'; list = 'ol'; }
        out += '<li>' + inline(t.replace(/^\d+[.)]\s+/, '')) + '</li>';
      } else if (t === '') {
        closeList();
      } else {
        closeList();
        out += '<p>' + inline(t) + '</p>';
      }
    });
    closeList();
    return out;
  }

  function showError(msg) {
    errorEl.textContent = msg;
    errorEl.hidden = false;
    resultEl.hidden = true;
  }

  function renderPassages(passages) {
    passagesEl.innerHTML = '';
    passages.forEach(function (p) {
      var card = document.createElement('div');
      card.className = 'passage';
      var meta = document.createElement('div');
      meta.className = 'meta';
      var badge = document.createElement('a');
      badge.className = 'page-badge';
      badge.href = pageUrl(p.page);
      badge.target = '_blank';
      badge.rel = 'noopener';
      badge.textContent = 'PDF page ' + p.page;
      var score = document.createElement('span');
      score.className = 'score';
      score.textContent = 'match ' + p.score.toFixed(2);
      meta.appendChild(badge);
      meta.appendChild(score);
      var quote = document.createElement('blockquote');
      quote.className = 'clamp';
      quote.textContent = p.text;
      card.appendChild(meta);
      card.appendChild(quote);
      if (p.text.length > 420) {
        var more = document.createElement('button');
        more.type = 'button';
        more.className = 'more';
        more.textContent = 'Read the full passage';
        more.addEventListener('click', function () {
          var expanded = quote.classList.toggle('clamp');
          more.textContent = expanded ? 'Read the full passage' : 'Show less';
        });
        card.appendChild(more);
      }
      passagesEl.appendChild(card);
    });
  }

  function ask(question) {
    input.value = question;
    errorEl.hidden = true;
    resultEl.hidden = true;
    statusEl.hidden = false;
    btn.disabled = true;
    btn.textContent = 'Reading…';
    fetch('ask.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ question: question })
    })
      .then(function (resp) { return resp.json().then(function (data) { return { ok: resp.ok, data: data }; }); })
      .then(function (res) {
        statusEl.hidden = true;
        btn.disabled = false;
        btn.textContent = 'Ask the book';
        if (!res.ok || res.data.error) {
          showError((res.data && res.data.error) || 'Something went wrong. Please try again.');
          return;
        }
        answerEl.innerHTML = renderMarkdown(res.data.answer || '');
        renderPassages(res.data.passages || []);
        resultEl.hidden = false;
        resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function () {
        statusEl.hidden = true;
        btn.disabled = false;
        btn.textContent = 'Ask the book';
        showError('Could not reach the book. Please check your connection and try again.');
      });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var q = input.value.trim();
    if (!q) { input.focus(); return; }
    ask(q);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  document.getElementById('chips').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-q]');
    if (b) { ask(b.getAttribute('data-q')); }
  });
})();
