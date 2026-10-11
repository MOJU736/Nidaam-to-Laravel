<style>
    :root { --ds-good:#1e8e57; --ds-warn:#b27716; --ds-bad:#b42318; --ds-mut:#98a4b0; --ds-pri:var(--brand); --ds-line:var(--line); }
    .ds a { color:var(--brand); }
    .ds-ph { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .ds-ph h1 { margin:0; } .ds-ph p { margin:2px 0 0; color:var(--muted); }
    .ds-tools { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .ds-btn { display:inline-block; padding:8px 12px; border:1px solid var(--line); border-radius:8px; background:var(--card); color:var(--text); font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
    .ds-btn--sm { padding:4px 9px; font-size:13px; } .ds a.ds-btn { color:var(--text); }
    .ds-btn--pri, .ds a.ds-btn--pri { background:var(--brand); border-color:var(--brand); color:#fff; }
    .ds-btn--good, .ds a.ds-btn--good { background:var(--ds-good); border-color:var(--ds-good); color:#fff; }
    .ds-btn--bad, .ds a.ds-btn--bad { background:var(--ds-bad); border-color:var(--ds-bad); color:#fff; }
    .ds-btn:disabled { opacity:.5; cursor:not-allowed; }
    .ds-card { background:var(--card); border:1px solid var(--line); border-radius:10px; margin-bottom:14px; overflow:hidden; }
    .ds-card__h { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; padding:12px 16px; border-bottom:1px solid var(--line); }
    .ds-card__h h2 { margin:0; font-size:16px; }
    .ds-hint { color:var(--muted); font-size:13px; } p.ds-hint { margin:6px 0; }
    .ds-empty { color:var(--muted); padding:16px; text-align:center; }
    .ds-n { display:inline-block; min-width:22px; padding:0 7px; border-radius:999px; background:var(--line); font-size:12px; text-align:center; } .ds-n.bad { background:var(--ds-bad); color:#fff; } .ds-n.warn { background:var(--ds-warn); color:#fff; }
    .ds-cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:14px; align-items:start; } .ds-cols--2 { grid-template-columns:repeat(auto-fit,minmax(380px,1fr)); }
    .ds-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:10px; margin-bottom:14px; }
    .ds-kpi { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:12px 14px; } .ds-kpi small, .ds-kpi strong, .ds-kpi span { display:block; }
    .ds-kpi small { color:var(--muted); font-size:12px; } .ds-kpi strong { font-size:22px; margin:2px 0; } .ds-kpi span { color:var(--muted); font-size:12px; }
    .ds-kpi.good strong { color:var(--ds-good); } .ds-kpi.bad strong { color:var(--ds-bad); } .ds-kpi.warn strong { color:var(--ds-warn); }
    .ds-pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); white-space:nowrap; }
    .ds-pill.good { color:var(--ds-good); border-color:var(--ds-good); } .ds-pill.bad { color:var(--ds-bad); border-color:var(--ds-bad); } .ds-pill.warn { color:var(--ds-warn); border-color:var(--ds-warn); } .ds-pill.below { color:var(--ds-bad); border-color:var(--ds-bad); }
    .ds-rc { display:inline-block; margin-left:5px; padding:0 6px; border-radius:999px; background:rgba(29,111,184,.14); color:var(--brand); font-size:12px; font-weight:700; }
    .ds-star { color:#d99a00; margin-left:4px; }
    .ds-li { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:10px 16px; border-top:1px solid var(--line); flex-wrap:wrap; } .ds-li:first-of-type { border-top:0; }
    .ds-li small { display:block; color:var(--muted); } .ds-li .amt { text-align:right; font-weight:700; } .ds-li .acts { display:flex; gap:6px; flex-wrap:wrap; }
    .ds-mcby { color:var(--muted); font-size:12px; }
    .ds-cyc { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:12px; padding:8px 12px; background:var(--card); border:1px solid var(--line); border-radius:10px; }
    .ds-cyc .meter { flex:1 1 120px; max-width:220px; height:8px; background:var(--line); border-radius:4px; overflow:hidden; } .ds-cyc .meter i { display:block; height:8px; background:var(--brand); }
    .ds-alert { padding:10px 14px; border-radius:8px; margin-bottom:12px; background:rgba(180,35,24,.1); border:1px solid rgba(180,35,24,.4); color:var(--ds-bad); }
    .ds-note { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 12px; margin-bottom:8px; border-radius:8px; background:rgba(178,119,22,.12); border:1px solid rgba(178,119,22,.4); }
    .ds-seg { display:inline-flex; flex-wrap:wrap; gap:0; margin-bottom:10px; border:1px solid var(--line); border-radius:8px; overflow:hidden; background:var(--card); }
    .ds-seg a { padding:6px 12px; text-decoration:none; color:var(--text); border-right:1px solid var(--line); } .ds-seg a:last-child { border-right:0; } .ds-seg a.on { background:var(--brand); color:#fff; } .ds-seg small { margin-left:5px; opacity:.75; }
    .ds-filters { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:10px 12px; margin-bottom:12px; background:var(--card); border:1px solid var(--line); border-radius:10px; }
    .ds-filters label { margin:0; font-weight:400; font-size:12px; color:var(--muted); } .ds-filters .ds-field { padding:6px 8px; min-width:120px; width:auto; } .ds-filters .ds-filters__q .ds-field { min-width:200px; }
    .ds-filters label[hidden] { display:none; }
    .ds-table { width:100%; border-collapse:collapse; } .ds-table th, .ds-table td { padding:8px 10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; } .ds-table th { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); white-space:nowrap; }
    .ds-table .r { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; } .ds-table tfoot td { font-weight:700; border-top:2px solid var(--line); }
    .ds-table tr.ds-group td { background:rgba(127,127,127,.1); font-weight:700; } .ds-table tr.ds-free td { color:var(--muted); font-style:italic; }
    .ds-table td.bad, .bad { color:var(--ds-bad); } .ds-table td.good, .good { color:var(--ds-good); }
    .ds-tw { overflow-x:auto; }
    .ds-legend { display:flex; gap:14px; flex-wrap:wrap; color:var(--muted); font-size:13px; margin-bottom:10px; } .ds-legend i { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:4px; }
    .ds-sims { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:14px; align-items:start; }
    .ds-sim { padding:14px; } .ds-sim__h { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; } .ds-sim__h small { display:block; color:var(--muted); }
    .ds-bar { height:6px; background:var(--line); border-radius:3px; margin:8px 0; overflow:hidden; } .ds-bar i { display:block; height:6px; background:var(--brand); }
    .ds-pkg { font-size:13px; color:var(--muted); margin-bottom:6px; } .ds-pkg.soon { color:var(--ds-warn); } .ds-pkg.gone { color:var(--ds-bad); }
    .ds-mc { display:flex; justify-content:space-between; gap:8px; align-items:center; flex-wrap:wrap; font-size:13px; margin-bottom:8px; } .ds-mc--none { color:var(--muted); }
    .ds-slots { display:grid; gap:6px; margin:8px 0; }
    .ds-slot { position:relative; display:flex; justify-content:space-between; gap:8px; align-items:center; padding:7px 10px 9px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); text-decoration:none; font:inherit; font-weight:400; text-align:left; width:100%; overflow:hidden; }
    .ds-slot .d { width:9px; height:9px; border-radius:50%; background:var(--ds-mut); flex:none; } .ds-slot > span { flex:1; font-weight:600; } .ds-slot em { color:var(--muted); font-style:normal; font-size:12px; }
    .ds-slot.paid .d { background:var(--ds-good); } .ds-slot.soon .d { background:var(--ds-warn); } .ds-slot.overdue .d { background:var(--ds-bad); } .ds-slot.free { border-style:dashed; color:var(--muted); background:transparent; cursor:pointer; }
    .ds-slot .u { position:absolute; left:0; bottom:0; height:3px; background:var(--brand); }
    .ds-sim__f { display:flex; justify-content:space-between; align-items:baseline; padding-top:8px; border-top:1px solid var(--line); color:var(--muted); } .ds-sim__f b.neg { color:var(--ds-bad); } .ds-sim__a { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
    .ds-info { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-bottom:14px; } .ds-info > div { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 12px; } .ds-info small, .ds-info b { display:block; } .ds-info small { color:var(--muted); font-size:12px; }
    .ds-form { padding:14px 16px; display:grid; gap:10px; } .ds-form label { margin:0; font-weight:600; display:block; } .ds-form label small { font-weight:400; color:var(--muted); display:block; }
    .ds-row2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; } @media (max-width:640px) { .ds-row2 { grid-template-columns:1fr; } }
    .ds-field { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); font:inherit; }
    .ds-check { display:flex !important; gap:8px; align-items:center; font-weight:500 !important; } .ds-check input { width:auto; }
    .ds-opts { border:1px solid var(--line); border-radius:8px; padding:8px 10px; display:grid; gap:6px; } .ds-opts legend { padding:0 6px; font-weight:600; font-size:13px; }
    .ds-opts label { display:flex; gap:8px; align-items:flex-start; padding:6px 8px; border-radius:8px; cursor:pointer; } .ds-opts label.is-on { background:rgba(29,111,184,.12); } .ds-opts label.off { opacity:.5; } .ds-opts input { width:auto; margin-top:4px; }
    .ds-checks { display:grid; gap:4px; } .ds-checks label { display:flex; gap:8px; align-items:center; font-weight:500; } .ds-checks input { width:auto; } .ds-checks label span { color:var(--muted); margin-left:auto; }
    .ds-post { padding:8px 10px; border-radius:8px; background:rgba(29,111,184,.1); } .ds-post[hidden] { display:none; } .ds-post > div { display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; }
    .ds-wa { white-space:pre-wrap; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:var(--bg); }
    .ds-use__bar { height:8px; background:var(--line); border-radius:4px; margin-top:6px; overflow:hidden; } .ds-use__bar i { display:block; height:8px; background:var(--brand); }
    .ds-usage-rows .r { display:grid; grid-template-columns:1fr 110px; gap:8px; align-items:center; margin-bottom:6px; } .ds-usage-rows small { display:block; color:var(--muted); }
    dialog.ds-modal { border:1px solid var(--line); border-radius:12px; padding:0; width:min(560px,94vw); background:var(--card); color:var(--text); } dialog.ds-modal::backdrop { background:rgba(0,0,0,.45); }
    dialog.ds-modal h3 { margin:0; padding:14px 16px; border-bottom:1px solid var(--line); font-size:17px; } dialog.ds-modal .ds-foot { display:flex; gap:8px; justify-content:flex-end; padding:12px 16px; border-top:1px solid var(--line); flex-wrap:wrap; }
    [hidden] { display:none !important; }
    .ds-deck .ds-slide { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:28px 32px; min-height:420px; }
    .ds-slide .eyebrow { font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); font-weight:700; } .ds-slide h2 { margin:4px 0; font-size:30px; } .ds-slide .sub { color:var(--muted); margin-bottom:16px; }
    .ds-deck__nav { display:flex; gap:12px; align-items:center; justify-content:center; margin-top:12px; flex-wrap:wrap; } .ds-dots { display:flex; gap:6px; } .ds-dots button { width:10px; height:10px; padding:0; border-radius:50%; background:var(--line); border:0; } .ds-dots button.on { background:var(--brand); }
    .ds-hb h3 { margin:0 0 8px; font-size:13px; color:var(--muted); } .ds-hb .r { display:grid; grid-template-columns:140px 1fr 110px; gap:8px; align-items:center; margin-bottom:8px; } .ds-hb .r b { text-align:right; } .ds-hb .tr { height:12px; background:var(--line); border-radius:6px; overflow:hidden; } .ds-hb .tr i { display:block; height:12px; background:var(--brand); } .ds-hb.alt .tr i { background:var(--ds-good); }
    @media print { nav.top, .ds-tools, .ds-filters, .ds-btn, .ds-seg { display:none !important; } }
</style>
