@php
    $hasChildren = ! empty($node['children']);
    $isRoot = $isRoot ?? false;

    $stateClass = $isRoot
        ? 'qtree-card-root'
        : ($node['is_active'] ? 'qtree-card-active' : 'qtree-card-inactive');

    // The first two levels stay open so the page is useful on load; deeper
    // branches collapse so a large team does not render as a wall.
    $startOpen = $node['depth'] < 2;
@endphp

<li class="qtree-item" data-node-name="{{ Str::lower($node['name']) }}">

    <div class="qtree-row">

        @if($hasChildren)
            <button type="button"
                    class="qtree-toggle {{ $startOpen ? '' : 'is-collapsed' }}"
                    aria-expanded="{{ $startOpen ? 'true' : 'false' }}"
                    aria-label="Toggle {{ $node['name'] }}'s team">&#9662;</button>
        @else
            <span class="qtree-toggle qtree-toggle-leaf"></span>
        @endif

        <div class="qtree-card {{ $stateClass }}">

            <div class="qtree-card-main">
                <span class="qtree-avatar">{{ Str::upper(Str::substr($node['name'], 0, 1)) }}</span>
                <span class="qtree-name">{{ $node['name'] }}</span>

                @if($isRoot)
                    <span class="badge bg-primary qtree-badge">You</span>
                @elseif($node['is_direct'])
                    <span class="badge bg-info qtree-badge">You enrolled</span>
                @endif

                @if($node['is_holding'] ?? false)
                    {{-- Only reachable when an admin opens the tree from a
                         holding spot; partners never see one here. --}}
                    <span class="badge bg-warning text-dark qtree-badge">Unclaimed spot</span>
                @elseif(! $isRoot && ! $node['is_active'])
                    <span class="badge bg-secondary qtree-badge">Inactive</span>
                @endif
            </div>

            <div class="qtree-meta">
                <span class="qtree-stat" title="Partners they personally enrolled">
                    <strong>{{ number_format($node['direct_count']) }}</strong> direct
                </span>
                <span class="qtree-stat" title="Everyone below them, at any depth">
                    <strong>{{ number_format($node['team_count']) }}</strong> team
                </span>
                @if(! $isRoot)
                    <span class="qtree-stat qtree-stat-muted">Level {{ $node['depth'] }}</span>
                @endif
                @if($node['joined_at'])
                    <span class="qtree-stat qtree-stat-muted">Joined {{ $node['joined_at']->format('M j, Y') }}</span>
                @endif
            </div>

            {{-- Contact details only for partners you personally enrolled. --}}
            @if($node['is_direct'] && ($node['email'] || $node['phone']))
                <div class="qtree-contact">
                    @if($node['email'])<a href="mailto:{{ $node['email'] }}">{{ $node['email'] }}</a>@endif
                    @if($node['email'] && $node['phone'])<span class="qtree-sep">&middot;</span>@endif
                    @if($node['phone'])<a href="tel:{{ $node['phone'] }}">{{ $node['phone'] }}</a>@endif
                </div>
            @endif

            @if(! $isRoot)
                <a href="{{ route('member.network', ['view_from' => $node['id']]) }}"
                   class="qtree-focus" title="View the tree from {{ $node['name'] }}">&#8857;</a>
            @endif
        </div>
    </div>

    @if($node['truncated'])
        <ul class="qtree-children">
            <li class="qtree-item">
                <div class="qtree-row">
                    <span class="qtree-toggle qtree-toggle-leaf"></span>
                    <a href="{{ route('member.network', ['view_from' => $node['id']]) }}" class="qtree-more">
                        {{ number_format($node['team_count']) }} more below {{ $node['name'] }}
                        &mdash; view from here &rarr;
                    </a>
                </div>
            </li>
        </ul>
    @elseif($hasChildren)
        <ul class="qtree-children {{ $startOpen ? '' : 'd-none' }}">
            @foreach($node['children'] as $child)
                @include('member._tree-node', ['node' => $child, 'isRoot' => false])
            @endforeach
        </ul>
    @endif

</li>
