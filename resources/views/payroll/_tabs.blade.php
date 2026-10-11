{{-- Payroll sub-menu. $active = index | employees | advances | reports --}}
<style>
    .ptabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px }
    .ptabs a { padding:6px 12px; border:1px solid var(--line); border-radius:999px; color:var(--text); text-decoration:none; font-weight:600; font-size:14px }
    .ptabs a.on { border-color:var(--brand); color:var(--brand); box-shadow:0 0 0 1px var(--brand) }
    .pstat { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; border:1px solid var(--line) }
    .pstat.is-draft { color:var(--muted) } .pstat.is-approved, .pstat.is-part { color:var(--brand); border-color:var(--brand) } .pstat.is-paid { color:#1a7f45; border-color:#1a7f45 } .pstat.is-void { color:var(--danger); border-color:var(--danger) }
    .pgrid input { padding:6px 8px; min-width:80px; text-align:right } .pgrid input.pnote { text-align:left; min-width:140px } .pgrid input.pdays { min-width:56px; width:64px }
    .pgrid td { vertical-align:middle } .pbad { color:var(--danger); font-size:12px; display:block }
    .psub { display:block; color:var(--muted); font-size:12px }
    .pform { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap } .pform > div { min-width:140px }
    @media print { .ptabs, .noprint { display:none !important } }
</style>
<div class="ptabs noprint">
    <a href="{{ route('payroll.index') }}" @class(['on' => $active === 'index'])>Pay runs</a>
    <a href="{{ route('payroll.employees') }}" @class(['on' => $active === 'employees'])>Employees</a>
    <a href="{{ route('payroll.advances') }}" @class(['on' => $active === 'advances'])>Advances &amp; loans</a>
    <a href="{{ route('payroll.reports') }}" @class(['on' => $active === 'reports'])>Reports</a>
</div>
