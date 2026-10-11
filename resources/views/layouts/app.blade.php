<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Nidaam')</title>
<style>
    :root { --bg:#f4f6f9; --card:#fff; --text:#1f2933; --muted:#6b7785; --line:#d9e0e7; --brand:#1d6fb8; --danger:#b42318; }
    @media (prefers-color-scheme: dark) { :root { --bg:#14181d; --card:#1d232a; --text:#e6ebf0; --muted:#98a4b0; --line:#2e3741; --brand:#4a9be0; --danger:#f97066; } }
    * { box-sizing: border-box; }
    body { margin:0; font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif; background:var(--bg); color:var(--text); }
    .wrap { max-width:960px; margin:0 auto; padding:24px 16px; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:24px; }
    .center { min-height:100vh; display:grid; place-items:center; padding:16px; }
    .center .card { width:100%; max-width:380px; }
    h1 { font-size:22px; margin:0 0 16px; }
    label { display:block; font-weight:600; margin:12px 0 4px; }
    input { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); font:inherit; }
    button, .btn { display:inline-block; padding:10px 16px; border:0; border-radius:8px; background:var(--brand); color:#fff; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
    button.link { background:none; color:var(--muted); padding:0; font-weight:400; text-decoration:underline; }
    .full { width:100%; margin-top:18px; }
    .error { color:var(--danger); margin:8px 0 0; font-size:14px; }
    .bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; }
    .stat { border:1px solid var(--line); border-radius:10px; padding:16px; background:var(--card); }
    .stat b { display:block; font-size:26px; }
    .muted { color:var(--muted); }
    ul.list { list-style:none; margin:0; padding:0; }
    ul.list li { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 0; border-top:1px solid var(--line); }
    ul.list li:first-child { border-top:0; }
    form.inline { display:inline; margin:0; }
    nav.top { background:var(--card); border-bottom:1px solid var(--line); }
    nav.top .in { max-width:1100px; margin:0 auto; padding:0 16px; display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
    nav.top a { padding:14px 12px; color:var(--text); text-decoration:none; font-weight:600; border-bottom:3px solid transparent; }
    nav.top a.on { border-color:var(--brand); color:var(--brand); }
    nav.top .sp { flex:1; }
    nav.top .co { color:var(--muted); font-size:13px; padding:0 8px; }
    .wide { max-width:1100px; margin:0 auto; padding:24px 16px; }
    .flash { padding:10px 14px; border-radius:8px; margin-bottom:16px; }
    .flash.ok { background:rgba(29,150,80,.12); border:1px solid rgba(29,150,80,.4); }
    .flash.bad { background:rgba(180,35,24,.1); border:1px solid rgba(180,35,24,.4); color:var(--danger); }
    table { width:100%; border-collapse:collapse; }
    th, td { text-align:left; padding:10px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
    th { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
    td.num, th.num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .tbl { overflow-x:auto; }
    .stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
    .stats button { flex:1 1 140px; text-align:left; background:var(--card); color:var(--text); border:1px solid var(--line); border-radius:10px; padding:12px 14px; font-weight:400; }
    .stats button.on { border-color:var(--brand); box-shadow:0 0 0 1px var(--brand); }
    .stats b { display:block; font-size:20px; }
    .stats span { color:var(--muted); font-size:13px; }
    .pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); }
    .pill.overdue { color:var(--danger); border-color:var(--danger); } .pill.owes { color:var(--brand); border-color:var(--brand); }
    .row2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; } @media (max-width:640px){ .row2 { grid-template-columns:1fr; } }
    .btn.ghost, button.ghost { background:none; color:var(--text); border:1px solid var(--line); }
    .btn.danger, button.danger { background:var(--danger); }
    .sm { padding:6px 10px; font-size:13px; }
    .actions { display:flex; gap:6px; flex-wrap:wrap; }
    textarea, select { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); font:inherit; }
    input[type=checkbox] { width:auto; }
    .field-error { color:var(--danger); font-size:13px; margin:4px 0 0; }
    h2 { font-size:17px; margin:24px 0 8px; }
    dl.kv { display:grid; grid-template-columns:140px 1fr; gap:6px 12px; margin:0; } dl.kv dt { color:var(--muted); } dl.kv dd { margin:0; }
</style>
</head>
<body>
@yield('body')
</body>
</html>
