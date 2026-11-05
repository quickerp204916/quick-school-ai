<nav class="toolbar no-print" aria-label="Setup">
    @foreach (['index' => 'Institution and branches', 'sessions' => 'Sessions and '.strtolower($t('term')).'s', 'structure' => $t('programs').', '.strtolower($t('levels')).' and '.strtolower($t('subjects')),
        'grading' => 'Grading', 'calendar' => 'Bell schedule and holidays', 'templates' => 'Certificate templates'] as $k => $label)
        <a class="btn sm {{ request()->routeIs('app.setup.'.$k) ? 'primary' : '' }}" href="{{ route('app.setup.'.$k) }}">{{ $label }}</a>
    @endforeach
</nav>
