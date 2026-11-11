(function () {
  let csrf = null;

  class ApiError extends Error {
    constructor(message, status, errors) { super(message); this.status = status; this.errors = errors || {}; }
  }

  async function request(path, opts = {}, retried = false) {
    const { method = 'GET', body, query } = opts;
    const url = new URL(window.APP_CONFIG.apiBase + '/' + path, window.location.href);
    Object.entries(query || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
    });
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') {
      if (!csrf) await request('auth.php', { query: { action: 'csrf' } });
      headers['X-CSRF-Token'] = csrf;
    }

    let res;
    try {
      res = await fetch(url, { method, headers, credentials: 'include', body: body !== undefined ? JSON.stringify(body) : undefined });
    } catch (e) {
      throw new ApiError('Tidak dapat terhubung ke server. Pastikan Apache dan MySQL berjalan.', 0);
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* bukan JSON */ }
    if (json && json.csrf) csrf = json.csrf;
    if (res.status === 419 && !retried) { csrf = null; return request(path, opts, true); }
    if (!res.ok || !json || json.ok === false) {
      throw new ApiError((json && json.message) || 'Terjadi kesalahan pada server (' + res.status + ')', res.status, json && json.errors);
    }
    return json;
  }

  window.ApiError = ApiError;
  window.api = {
    get: (path, query) => request(path, { query }),
    post: (path, body, query) => request(path, { method: 'POST', body: body || {}, query }),
    put: (path, body, query) => request(path, { method: 'PUT', body: body || {}, query }),
    patch: (path, body, query) => request(path, { method: 'PATCH', body: body || {}, query }),
    del: (path, query) => request(path, { method: 'DELETE', query }),
    url: (path, query) => {
      const u = new URL(window.APP_CONFIG.apiBase + '/' + path, window.location.href);
      Object.entries(query || {}).forEach(([k, v]) => { if (v) u.searchParams.set(k, v); });
      return u.toString();
    },
  };
})();
