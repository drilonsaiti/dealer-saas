<strong>{{ $p['name'] ?: '–' }}</strong>
@if ($p['contact'])<br>{{ $p['contact'] }}@endif
@if ($p['street'])<br>{{ $p['street'] }}@endif
@if ($p['place'])<br>{{ $p['place'] }}@if ($p['country']), {{ $p['country'] }}@endif @endif
@if ($p['phone'] || $p['email'])<br><span class="muted">{{ collect([$p['phone'], $p['email']])->filter()->implode(' · ') }}</span>@endif
@if ($p['uid'])<br><span class="muted">{{ $p['uid'] }}</span>@endif
