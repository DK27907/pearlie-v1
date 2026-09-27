<div data-escalation-queue-content class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="min-w-[960px] w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">ID</th>
                    <th scope="col" class="px-4 py-3">Patient</th>
                    <th scope="col" class="px-4 py-3">Phone</th>
                    <th scope="col" class="px-4 py-3">Question</th>
                    <th scope="col" class="px-4 py-3">Waiting</th>
                    <th scope="col" class="px-4 py-3">Confidence</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($escalations as $escalation)
                    @php
                        $showRoute = $queueRoutePrefix.'.escalations.show';
                        $isAssignedWorker = (int) $escalation->assigned_worker_id === auth()->id();
                        $canResolve = auth()->user()->isAdmin() || $isAssignedWorker;
                        $waitingMinutes = (int) floor(now()->diffInSeconds($escalation->created_at) / 60);
                    @endphp
                    <tr class="align-top hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-900">#{{ $escalation->id }}</td>
                        <td class="max-w-48 px-4 py-4">
                            <p class="truncate font-semibold text-slate-900">{{ $escalation->patient_name }}</p>
                            @if ($escalation->assignedWorker)
                                <p class="mt-1 text-xs text-slate-500">With {{ $escalation->assignedWorker->name }}</p>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ $escalation->patient_phone ?: 'Not provided' }}</td>
                        <td class="max-w-sm px-4 py-4 text-slate-600">{{ \Illuminate\Support\Str::limit($escalation->user_message, 100) }}</td>
                        <td @class([
                            'whitespace-nowrap px-4 py-4 font-semibold',
                            'text-rose-700' => $escalation->status === 'pending' && $waitingMinutes >= (int) config('pearlie.escalation.max_wait_minutes', 30),
                            'text-slate-600' => ! ($escalation->status === 'pending' && $waitingMinutes >= (int) config('pearlie.escalation.max_wait_minutes', 30)),
                        ])>
                            {{ $escalation->created_at->diffForHumans(null, true) }}
                            @if ($escalation->status === 'pending' && $waitingMinutes >= (int) config('pearlie.escalation.max_wait_minutes', 30))
                                <span class="block text-xs">Over wait target</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-4 text-slate-600">
                            {{ $escalation->confidence_score === null ? '—' : number_format($escalation->confidence_score, 2) }}
                        </td>
                        <td class="px-4 py-4">
                            <span @class([
                                'whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold capitalize',
                                'bg-rose-50 text-rose-800' => $escalation->status === 'pending',
                                'bg-amber-50 text-amber-800' => $escalation->status === 'in_progress',
                                'bg-emerald-50 text-emerald-800' => $escalation->status === 'resolved',
                                'bg-slate-100 text-slate-700' => ! in_array($escalation->status, ['pending', 'in_progress', 'resolved'], true),
                            ])>{{ str_replace('_', ' ', $escalation->status) }}</span>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route($showRoute, $escalation->id) }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">View</a>
                                @if ($escalation->status === 'pending')
                                    <form method="POST" action="{{ route($queueRoutePrefix.'.escalations.claim', $escalation->id) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-800">Claim</button>
                                    </form>
                                @elseif ($escalation->status === 'in_progress' && $canResolve)
                                    <form method="POST" action="{{ route($queueRoutePrefix.'.escalations.resolve', $escalation->id) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">Resolve</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-14 text-center">
                            <p class="font-semibold text-slate-800">No escalations match this queue.</p>
                            <p class="mt-1 text-sm text-slate-500">New patient requests will appear here.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($escalations->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $escalations->links() }}</div>
    @endif
</div>
