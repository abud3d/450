/* Lightweight Socket.IO-compatible facade backed only by PHP HTTP endpoints.
 * Polling is adaptive and no persistent Node process or port is required.
 */
(() => {
  class BalootHttpClient {
    constructor() {
      this.listeners = new Map();
      this.code = '';
      this.version = -1;
      this.timer = null;
      this.busy = false;
      this.stopped = false;
      this.delay = 1800;
      queueMicrotask(() => this.dispatch('connect'));
    }

    on(event, callback) {
      if (!this.listeners.has(event)) this.listeners.set(event, new Set());
      this.listeners.get(event).add(callback);
      return this;
    }

    dispatch(event, value) {
      for (const callback of this.listeners.get(event) || []) {
        try { callback(value); } catch (error) { console.error(error); }
      }
    }

    async request(url, options) {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
      let data;
      try { data = await response.json(); } catch { throw new Error('استجابة الخادم غير مفهومة'); }
      if (!response.ok || !data.ok) throw new Error(data.error || 'تعذر تنفيذ الطلب');
      return data;
    }

    async emit(event, payload = {}, ack = () => {}) {
      const data = payload || {};
      try {
        let result;
        if (event === 'room:resume') {
          this.code = String(data.code || '').toUpperCase();
          result = await this.request('/api/room.php?action=resume&code=' + encodeURIComponent(this.code));
        } else if (event === 'room:create' || event === 'room:quick-join') {
          result = await this.post('create', { name: data.name, qaidEnabled: data.qaidEnabled === true, guestId: data.guestId });
          result.created = true;
        } else if (event === 'room:join') {
          result = await this.post('join', { code: data.code, name: data.name, guestId: data.guestId });
        } else {
          const actions = {
            'room:start': 'start',
            'room:move-seat': 'move-seat',
            'room:bid': 'bid',
            'room:post-purchase': 'post-purchase',
            'room:double': 'double',
            'room:project': 'project',
            'room:play': 'play',
            'room:qaid-open': 'qaid-open',
            'room:qaid-cancel': 'qaid-cancel',
            'room:claim': 'claim',
            'room:replay': 'replay',
            'room:result-continue': 'result-continue',
            'room:next': 'next',
            'room:timeout-return': 'timeout-return',
            'room:timeout-bot': 'timeout-bot'
          };
          const action = actions[event];
          if (!action) throw new Error('هذه الحركة لم تُربط بعد بواجهة PHP');
          this.code = String(data.code || this.code).toUpperCase();
          result = await this.post(action, { ...data, code: this.code });
        }

        if (result.code) this.code = result.code;
        if (result.guestId) localStorage.setItem('baloot400Guest', result.guestId);
        if (result.snapshot) this.acceptSnapshot(result.snapshot);
        if (result.seat !== undefined) result.seat = Number(result.seat);
        ack({ ...result, ok: true });
        if (this.code) this.schedule(400);
      } catch (error) {
        ack({ ok: false, error: error.message || 'تعذر تنفيذ الطلب' });
      }
    }

    async post(action, payload) {
      const body = {
        ...payload,
        actionId: crypto.randomUUID(),
        version: payload.version ?? this.version
      };
      return this.request('/api/room.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
    }

    acceptSnapshot(snapshot) {
      if (!snapshot || typeof snapshot !== 'object') return;
      this.code = snapshot.code || this.code;
      this.version = Number.isInteger(snapshot.version) ? snapshot.version : this.version;
      this.delay = 1800;
      this.dispatch('room:update', snapshot);
    }

    schedule(delay) {
      if (this.stopped || !this.code) return;
      if (this.timer) clearTimeout(this.timer);
      this.timer = setTimeout(() => this.poll(), delay);
    }

    async poll() {
      if (this.stopped || this.busy || !this.code) return;
      this.busy = true;
      try {
        const result = await this.request('/api/room.php?action=state&code=' + encodeURIComponent(this.code));
        if (result.snapshot) this.acceptSnapshot(result.snapshot);
        this.delay = document.hidden ? 5000 : (result.snapshot?.phase === 'lobby' ? 2800 : 1400);
      } catch (error) {
        this.delay = Math.min(12000, Math.max(2500, this.delay * 1.7));
        if (/الغرفة غير موجودة|لا يوجد مقعد محفوظ/.test(error.message)) this.dispatch('disconnect');
      } finally {
        this.busy = false;
        this.schedule(this.delay);
      }
    }

    close() {
      this.stopped = true;
      if (this.timer) clearTimeout(this.timer);
      this.dispatch('disconnect');
    }
  }

  window.BalootHttpClient = BalootHttpClient;
})();
