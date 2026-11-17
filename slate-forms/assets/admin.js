/* Slate Forms dashboard helpers: star an entry without leaving the list. */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sf-star]');
    if (!b) return;
    e.preventDefault();
    var on = b.getAttribute('aria-pressed') !== 'true';
    var fd = new FormData();
    fd.append('ids[]', b.getAttribute('data-sf-star'));
    fd.append('action', on ? 'star' : 'unstar');
    fd.append('_csrf', (window.SLATE || {}).csrf || '');
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    b.classList.toggle('is-on', on);
    var admin = (window.SLATE || {}).admin || '/admin';
    fetch(admin.replace(/\/$/, '') + '/m/submissions/bulk', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw new Error(); })
      .catch(function () {
        b.setAttribute('aria-pressed', on ? 'false' : 'true');
        b.classList.toggle('is-on', !on);
        if (window.Slate && window.Slate.toast) window.Slate.toast('Could not update the star. Reload and try again.', 'error');
      });
  });
})();
