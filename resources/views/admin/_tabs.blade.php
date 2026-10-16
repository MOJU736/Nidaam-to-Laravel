<nav class="subnav" aria-label="Admin sections">
    <a class="{{ request()->routeIs('admin.users*') ? 'on' : '' }}" href="{{ route('admin.users') }}">Users</a>
    <a class="{{ request()->routeIs('admin.groups*') ? 'on' : '' }}" href="{{ route('admin.groups') }}">Groups &amp; permissions</a>
    <a class="{{ request()->routeIs('admin.login-history') ? 'on' : '' }}" href="{{ route('admin.login-history') }}">Login history</a>
</nav>