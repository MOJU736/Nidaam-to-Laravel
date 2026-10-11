<div class="actions" style="margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:8px">
    <a class="btn sm {{ request()->routeIs('admin.users*') ? '' : 'ghost' }}" href="{{ route('admin.users') }}">Users</a>
    <a class="btn sm {{ request()->routeIs('admin.groups*') ? '' : 'ghost' }}" href="{{ route('admin.groups') }}">Groups &amp; permissions</a>
    <a class="btn sm {{ request()->routeIs('admin.login-history') ? '' : 'ghost' }}" href="{{ route('admin.login-history') }}">Login history</a>
</div>
