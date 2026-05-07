(() => {
  if (typeof document !== 'undefined' && document.body) {
    if (localStorage.getItem('theme') === 'dark') {
      document.body.classList.add('dark-theme');
    }
  }

  const state = {
    queue: [],
    showing: null,
    expanded: false,
    theme: 'auto',
    maxVisible: 3,
  };

  const css = `
  :root {
    --di-bg: #0f172a;
    --di-fg: #e2e8f0;
    --di-border: #1e293b;
    --di-success: #10b981;
    --di-info: #3b82f6;
    --di-warn: #f59e0b;
    --di-error: #ef4444;
  }
  @media (prefers-color-scheme: light) {
    :root {
      --di-bg: #ffffff;
      --di-fg: #0f172a;
      --di-border: #e2e8f0;
    }
  }
  body.dark-theme {
    --di-bg: #0f172a;
    --di-fg: #e2e8f0;
    --di-border: #1e293b;
  }
  .di-root {
    position: fixed;
    top: 12px;
    right: 12px;
    left: auto;
    transform: none;
    z-index: 99999;
    pointer-events: none;
  }
  .di-island {
    pointer-events: auto;
    min-width: 180px;
    max-width: 420px;
    background: var(--di-bg);
    color: var(--di-fg);
    border: 1px solid var(--di-border);
    border-radius: 28px;
    box-shadow: 0 12px 36px rgba(0,0,0,0.18), 0 1px 0 rgba(255,255,255,0.05) inset;
    background-image: radial-gradient(120% 200% at 10% 0%, rgba(255,255,255,0.06) 0%, transparent 60%), linear-gradient(180deg, rgba(255,255,255,0.02), transparent);
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    margin-left: auto;
    margin-right: 0;
    opacity: 0;
    transform: translateY(-8px) scale(0.92);
    transform-origin: top right;
    will-change: transform, opacity, height;
    overflow: hidden;
  }
  .di-icon {
    width: 28px;
    height: 28px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex: 0 0 auto;
    position: relative;
    background: radial-gradient(circle at 50% 45%, rgba(255,255,255,0.12), transparent 60%);
  }
  .di-icon.success { background: color-mix(in srgb, var(--di-success) 20%, transparent); color: var(--di-success); }
  .di-icon.info    { background: color-mix(in srgb, var(--di-info) 20%, transparent);    color: var(--di-info); }
  .di-icon.warn    { background: color-mix(in srgb, var(--di-warn) 20%, transparent);    color: var(--di-warn); }
  .di-icon.error   { background: color-mix(in srgb, var(--di-error) 20%, transparent);   color: var(--di-error); }
  .di-icon::before, .di-icon::after {
    content: "";
    position: absolute;
    inset: -6px;
    border-radius: 999px;
    border: 2px solid currentColor;
    opacity: .18;
    transform: scale(.85);
    animation: diRing 2s ease-out infinite;
  }
  .di-icon::after {
    inset: -11px;
    opacity: .12;
    animation-delay: .4s;
  }
  @keyframes diRing {
    0% { transform: scale(.75); opacity: .24; }
    70% { transform: scale(1.2); opacity: 0; }
    100% { transform: scale(1.25); opacity: 0; }
  }
  .di-icon.spin::before, .di-icon.spin::after { display: none; }
  .di-icon.spin {
    background: transparent;
    color: var(--di-info);
  }
  .di-icon.spin svg {
    width: 16px; height: 16px;
    animation: diSpin 1s linear infinite;
  }
  @keyframes diSpin { to { transform: rotate(360deg); } }
  .di-body {
    flex: 1 1 auto;
    min-width: 0;
  }
  .di-title {
    font-weight: 800;
    letter-spacing: .2px;
    font-size: 13.5px;
    line-height: 1.1;
    margin: 0;
    font-family: ui-sans-serif, system-ui, -apple-system, "Inter", "Plus Jakarta Sans", "Segoe UI", Arial, sans-serif;
  }
  .di-message {
    font-size: 12.5px;
    opacity: .9;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .di-details {
    overflow: hidden;
    height: 0;
    margin: 0;
  }
  .di-details-content {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    padding-top: 10px;
    width: 100%;
  }
  .di-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 8px;
    width: 100%;
  }
  .di-kpi {
    border: 1px solid var(--di-border);
    border-radius: 12px;
    padding: 8px 10px;
    background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent);
  }
  .di-kpi .n {
    font-weight: 800;
    font-size: 16px;
  }
  .di-kpi .l {
    font-size: 10px;
    opacity: .75;
    margin-top: 2px;
    text-transform: uppercase;
    letter-spacing: .4px;
  }
  .di-actions {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-left: 8px;
  }
  .di-btn {
    background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02));
    border: 1px solid var(--di-border);
    color: var(--di-fg);
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 11.5px;
    cursor: pointer;
    transition: transform .15s ease, box-shadow .2s ease;
  }
  .di-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.18);
  }
  .di-dismiss {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: transparent;
    border: 1px solid var(--di-border);
    color: var(--di-fg);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background .15s ease, transform .15s ease;
  }
  .di-dismiss:hover {
    background: rgba(255,255,255,0.04);
    transform: translateY(-1px);
  }
  .di-list {
    margin-top: 8px;
    border-top: 1px dashed var(--di-border);
    padding-top: 6px;
    max-height: 180px;
    overflow: auto;
  }
  .di-list-item {
    display: grid;
    grid-template-columns: 24px 1fr auto;
    gap: 8px;
    align-items: center;
    padding: 6px 2px;
  }
  .di-sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
    border: 0;
  }
  .di-cc {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 8px;
    align-items: center;
    padding: 8px;
    border: 1px solid var(--di-border);
    border-radius: 14px;
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(6px);
    width: 100%;
  }
  .di-cc input {
    background: transparent;
    border: 0;
    outline: 0;
    color: var(--di-fg);
    font-size: 12.5px;
  }
  .di-cc .cc-pill {
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid var(--di-border);
    background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02));
    font-size: 11.5px;
  }
  @media (max-width: 640px) {
    .di-root { top: 10px; right: 8px; }
    .di-island { max-width: min(92vw, 360px); }
  }
  @media (min-width: 1280px) {
    .di-root { top: 14px; right: 16px; }
  }
  `;

  const icons = {
    success: '✓',
    info: 'i',
    warn: '!',
    error: '×',
  };

  function vibrate(pattern = [8, 16, 8]) {
    if ('vibrate' in navigator) {
      try { navigator.vibrate(pattern); } catch {}
    }
  }

  function byPriority(a, b) {
    const pa = Number.isFinite(a.priority) ? a.priority : 100;
    const pb = Number.isFinite(b.priority) ? b.priority : 100;
    return pa - pb;
  }

  // Simple spring integrator (Hooke's law) for physics-like motion
  function springAnimate({ from, to, stiffness = 170, damping = 26, mass = 1, onUpdate, onComplete, threshold = 0.001, maxDuration = 1200 }) {
    let x = from;          // position
    let v = 0;             // velocity
    const t0 = performance.now();
    const step = () => {
      const now = performance.now();
      const dt = Math.min(1/60, (now - (springAnimate._last || now)) / 1000 || 0); // seconds/frame
      springAnimate._last = now;
      const k = stiffness; // spring constant
      const c = damping;   // damping coefficient
      const Fspring = -k * (x - to);
      const Fdamp  = -c * v;
      const a = (Fspring + Fdamp) / mass;
      v += a * dt;
      x += v * dt;
      onUpdate(x);
      const done = Math.abs(v) < threshold && Math.abs(x - to) < threshold;
      if (done || now - t0 > maxDuration) {
        onUpdate(to);
        if (onComplete) onComplete(now - t0);
        return;
      }
      requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }

  function ensureRoot() {
    if (document.getElementById('di-style')) return;
    const st = document.createElement('style');
    st.id = 'di-style';
    st.textContent = css;
    document.head.appendChild(st);
    const root = document.createElement('div');
    root.className = 'di-root';
    root.setAttribute('role', 'region');
    root.setAttribute('aria-live', 'polite');
    root.setAttribute('aria-atomic', 'true');
    root.id = 'dynamic-island-root';
    document.body.appendChild(root);
  }

  function render() {
    ensureRoot();
    const root = document.getElementById('dynamic-island-root');
    root.innerHTML = '';
    if (!state.showing) return;

    const wrap = document.createElement('div');
    wrap.className = 'di-island';
    // Spring-in animation: translateY(-8px)->0, scale .92->1, opacity 0->1
    wrap.style.opacity = '0';
    wrap.style.transform = 'translateY(-8px) scale(0.92)';

    const icon = document.createElement('div');
    icon.className = `di-icon ${state.showing.type || 'info'}`;
    if (state.showing.status === 'processing') {
      icon.classList.add('spin');
      icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" opacity=".3"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    } else {
      icon.textContent = icons[state.showing.type] || '•';
    }

    const body = document.createElement('div');
    body.className = 'di-body';
    const title = document.createElement('p');
    title.className = 'di-title';
    title.textContent = state.showing.title || (state.showing.type || 'info').toUpperCase();
    const msg = document.createElement('div');
    msg.className = 'di-message';
    msg.textContent = state.showing.message || '';

    const dismiss = document.createElement('button');
    dismiss.className = 'di-dismiss';
    dismiss.type = 'button';
    dismiss.setAttribute('aria-label', 'Dismiss notification');
    dismiss.textContent = '×';
    dismiss.addEventListener('click', (e) => {
      e.stopPropagation();
      hideCurrent();
    });

    body.appendChild(title);
    body.appendChild(msg);
    wrap.appendChild(icon);
    wrap.appendChild(body);
    wrap.appendChild(dismiss);
    // No expansion/interactions beyond dismiss; keep capsule simple

    root.appendChild(wrap);
    // Perform spring entrance
    const enterStart = performance.now();
    springAnimate({
      from: 0,
      to: 1,
      stiffness: 180,
      damping: 22,
      onUpdate: (val) => {
        const s = 0.92 + (1 - 0.92) * val;
        const ty = -8 + (0 - (-8)) * val;
        wrap.style.transform = `translateY(${ty.toFixed(3)}px) scale(${s.toFixed(3)})`;
        wrap.style.opacity = String(val);
      },
      onComplete: (elapsed) => {
        // expose for tests
        state._lastEnterDuration = elapsed;
      },
      maxDuration: 800
    });
    if (state.showing.autoClose !== false) {
      const t = Number.isFinite(state.showing.timeout) ? state.showing.timeout : 4000;
      state._autoTimer = setTimeout(() => !state.expanded && hideCurrent(), t);
    }
  }

  function expand(el) {
    state.expanded = true;
    clearTimeout(state._autoTimer);
    const details = el.querySelector('.di-details');
    const content = el.querySelector('.di-details-content');
    if (!details || !content) return;
    const targetH = content.scrollHeight;
    springAnimate({
      from: 0,
      to: targetH,
      stiffness: 200,
      damping: 22,
      onUpdate: (val) => { details.style.height = `${val}px`; },
      maxDuration: 600
    });
  }

  function collapse(el) {
    state.expanded = false;
    const details = el.querySelector('.di-details');
    if (details) {
      const current = details.getBoundingClientRect().height;
      springAnimate({
        from: current,
        to: 0,
        stiffness: 200,
        damping: 22,
        onUpdate: (val) => { details.style.height = `${Math.max(0,val)}px`; },
        maxDuration: 500
      });
    }
    clearTimeout(state._autoTimer);
    state._autoTimer = setTimeout(() => hideCurrent(), 1800);
  }

  function hideCurrent() {
    const root = document.getElementById('dynamic-island-root');
    if (!root || !root.firstChild) { next(); return; }
    const el = root.firstChild;
    // Spring-out: scale 1->0.92, translateY 0->-8, opacity 1->0
    const start = performance.now();
    springAnimate({
      from: 1,
      to: 0,
      stiffness: 210,
      damping: 26,
      onUpdate: (val) => {
        const s = 0.92 + (1 - 0.92) * val;
        const ty = -8 + (0 - (-8)) * val;
        el.style.transform = `translateY(${ty.toFixed(3)}px) scale(${s.toFixed(3)})`;
        el.style.opacity = String(val);
      },
      onComplete: (elapsed) => {
        state._lastExitDuration = elapsed;
        if (el.parentNode) el.parentNode.removeChild(el);
        state.showing = null;
        state.expanded = false;
        next();
      },
      maxDuration: 700
    });
  }

  function next() {
    if (state.showing) return;
    if (!state.queue.length) return;
    state.queue.sort(byPriority);
    state.showing = state.queue.shift();
    vibrate();
    render();
  }

  function enqueue(n) {
    state.queue.push({
      id: crypto.randomUUID ? crypto.randomUUID() : String(Date.now() + Math.random()),
      title: n.title || '',
      message: n.message || '',
      type: n.type || 'info',
      actions: Array.isArray(n.actions) ? n.actions : [],
      priority: Number.isFinite(n.priority) ? n.priority : 50,
      timeout: n.timeout,
      autoClose: n.autoClose !== false,
    });
    next();
  }

  function intercept() {
    const oldAlert = window.alert;
    window.alert = function(msg) {
      enqueue({ title: 'Alert', message: String(msg), type: 'info', priority: 10 });
      try { oldAlert.apply(window, arguments); } catch {}
    };
    window.addEventListener('error', (e) => {
      enqueue({ title: 'Error', message: e.message || 'Runtime error', type: 'error', priority: 0, timeout: 6000 });
    });
    window.addEventListener('unhandledrejection', (e) => {
      const m = (e && e.reason && e.reason.message) ? e.reason.message : 'Unhandled promise rejection';
      enqueue({ title: 'Promise Rejection', message: m, type: 'warn', priority: 5, timeout: 6000 });
    });
  }

  function tests() {
    enqueue({ title: 'Welcome', message: 'Dynamic Island initialized', type: 'success', priority: 30 });
    enqueue({ title: 'Sync', message: 'Fetching latest data…', type: 'info', priority: 40, actions: [{label:'View', onClick:()=>console.log('Open sync')} ]});
    enqueue({ title: 'Low disk', message: 'Storage running low', type: 'warn', priority: 20, timeout: 6000 });
    enqueue({ title: 'Failure', message: 'Something went wrong', type: 'error', priority: 10, actions: [{label:'Retry', onClick:()=>console.log('Retry')}]});
  }

  function init() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        ensureRoot();
        intercept();
      });
    } else {
      ensureRoot();
      intercept();
    }
  }

  init();

  window.DynamicIsland = {
    notify: enqueue,
    success: (m, t='Success') => enqueue({title:t, message:m, type:'success', priority:40}),
    info:    (m, t='Info')    => enqueue({title:t, message:m, type:'info', priority:50}),
    warn:    (m, t='Warning') => enqueue({title:t, message:m, type:'warn', priority:20}),
    error:   (m, t='Error')   => enqueue({title:t, message:m, type:'error', priority:10}),
    runTests: tests,
    _test: {
      spring: springAnimate,
      lastEnterDuration: () => state._lastEnterDuration || 0,
      lastExitDuration: () => state._lastExitDuration || 0,
      rootOffset: () => {
        const r = document.getElementById('dynamic-island-root');
        return r ? { top: parseFloat(getComputedStyle(r).top) || 0, right: parseFloat(getComputedStyle(r).right) || 0 } : {top:0,right:0};
      }
    }
  };
})();
