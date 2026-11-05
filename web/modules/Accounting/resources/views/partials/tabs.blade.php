{{-- Section tabs. $tabs: [route => label] --}}
<nav class="toolbar no-print" aria-label="Section">
    @foreach ($tabs as $route => $label)
        @php([$name, $params] = is_array($label) ? [$route, $label[1]] : [$route, []])
        <a class="btn sm {{ request()->routeIs($name) && (empty($params) || collect($params)->every(fn ($v, $k) => request($k, $k === 'kind' ? 'sales' : ($k === 'side' ? 'receivable' : null)) === $v)) ? 'primary' : '' }}" href="{{ route($name, $params) }}">{{ is_array($label) ? $label[0] : $label }}</a>
    @endforeach
</nav>
