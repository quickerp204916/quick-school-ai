{{--
  Supporting documents and photo, one row per document type.
  Needs: $files (keyed by type), $uploadUrl, $canChange, $required (list of types), $received (type => bool, applications only), $open (uploads allowed)
--}}
@php($cfg = config('academic.uploads'))
<div class="table-wrap"><table><thead><tr><th>Document</th><th>File</th><th>Status</th>@if ($canChange && $open)<th>Upload</th>@endif</tr></thead><tbody>
@foreach (config('academic.admission.documents') as $type => $label)
    @php($f = $files[$type] ?? null)
    <tr>
        <td><b>{{ $label }}</b> @if (in_array($type, $required, true))<span class="chip warning">required</span>@endif
            <div class="small muted">{{ $type === 'photo' ? 'JPG, PNG or WebP, up to 2 MB, at least '.$cfg['photo_min_px'].' px' : 'PDF, JPG, PNG or WebP, up to 5 MB' }}</div></td>
        <td>@if ($f)
                <div class="toolbar" style="align-items:flex-start">
                    @if ($f->isImage())<a href="{{ route('app.documents.show', $f) }}" target="_blank" rel="noopener"><img src="{{ route('app.documents.show', $f) }}" alt="{{ $label }}" style="width:56px;height:{{ $type === 'photo' ? 70 : 56 }}px;object-fit:cover;border-radius:6px;border:1px solid var(--border)"></a>@endif
                    <div><a href="{{ route('app.documents.show', $f) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($f->original_name, 40) }}</a>
                        <div class="small muted">{{ $f->sizeLabel() }} · {{ $f->created_at->format('j M Y') }} · <a href="{{ route('app.documents.show', [$f, 'download' => 1]) }}">Download</a></div></div>
                </div>
            @elseif (! empty($received[$type]))<span class="small muted">Paper copy received</span>
            @else<span class="muted">—</span>@endif</td>
        <td>@if ($f && $f->verified_at)<span class="chip present">verified</span>
            @elseif ($f)<span class="chip applied">uploaded</span>
                @if ($canChange)<form class="inline" method="post" action="{{ route('app.documents.verify', $f) }}">@csrf<button class="btn sm" type="submit" title="You have seen the original">Mark verified</button></form>@endif
            @elseif (! empty($received[$type]))<span class="chip applied">received</span>
            @elseif (in_array($type, $required, true))<span class="chip warning">missing</span>
            @else<span class="small muted">optional</span>@endif
            @if ($f && $canChange && $open)<form class="inline" method="post" action="{{ route('app.documents.destroy', $f) }}">@csrf @method('delete')<button class="btn sm danger" type="submit" aria-label="Remove {{ $label }}">Remove</button></form>@endif</td>
        @if ($canChange && $open)
        <td><form method="post" action="{{ $uploadUrl }}" enctype="multipart/form-data" class="toolbar">@csrf
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="file" name="file" required accept="{{ $type === 'photo' ? 'image/jpeg,image/png,image/webp' : 'application/pdf,image/jpeg,image/png,image/webp' }}" aria-label="Choose {{ $label }}" style="height:auto;padding:5px;max-width:230px">
            <button class="btn sm" type="submit">{{ $f ? 'Replace' : 'Upload' }}</button></form></td>
        @endif
    </tr>
@endforeach
</tbody></table></div>
