{{-- Payroll sub-menu. $active = index | employees | advances | reports --}}
<style>
    .pstat { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; border:1px solid var(--line-strong); background:var(--card) }
    .pstat.is-draft { color:var(--muted) } .pstat.is-approved, .pstat.is-part { color:var(--brand-dark); background:var(--brand-soft); border-color:transparent } .pstat.is-paid { color:var(--good); background:var(--good-soft); border-color:transparent } .pstat.is-void { color:var(--danger); background:var(--danger-soft); border-color:transparent }
    .pgrid input { padding:6px 8px; min-width:80px; text-align:right } .pgrid input.pnote { text-align:left; min-width:140px } .pgrid input.pdays { min-width:56px; width:64px }
    .pgrid td { vertical-align:middle } .pbad { color:var(--danger); font-size:12px; display:block }
    .psub { display:block; color:var(--muted); font-size:12px }
    .pform { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap } .pform > div { min-width:140px }
    @media print { .subnav, .noprint { display:none !important } }
</style>
<nav class="subnav noprint" aria-label="Payroll sections">
    <a href="{{ route('payroll.index') }}" @class(['on' => $active === 'index'])>Pay runs</a>
    <a href="{{ route('payroll.employees') }}" @class(['on' => $active === 'employees'])>Employees</a>
    <a href="{{ route('payroll.advances') }}" @class(['on' => $active === 'advances'])>Advances &amp; loans</a>
    <a href="{{ route('payroll.reports') }}" @class(['on' => $active === 'reports'])>Reports</a>
</nav>
