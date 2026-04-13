<?php

namespace App\Http\Controllers;

use App\Models\ConversationLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant  = $request->user()->tenant()->firstOrFail();
        $filters = $this->filters($request);

        // ── Base query for individual messages (used for stats and filtering) ──
        $baseQuery = $tenant->conversationLogs()
            ->when($filters['channel'] !== null, fn ($b) => $b->where('channel', $filters['channel']))
            ->when($filters['reply_status'] === 'replied', fn ($b) => $b->whereNotNull('message_out')->where('message_out', '!=', ''))
            ->when($filters['reply_status'] === 'unreplied', fn ($b) => $b->where(fn ($i) => $i->whereNull('message_out')->orWhere('message_out', '')))
            ->when($filters['date_from'] !== null, fn ($b) => $b->whereDate('created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== null, fn ($b) => $b->whereDate('created_at', '<=', $filters['date_to']))
            ->when($filters['search'] !== null, function ($b) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $b->where(fn ($i) => $i
                    ->where('from_identifier', 'like', $term)
                    ->orWhere('message_in', 'like', $term)
                    ->orWhere('message_out', 'like', $term)
                    ->orWhere('session_id', 'like', $term)
                    ->orWhere('external_message_id', 'like', $term));
            });

        $conversationStats = [
            'matched'  => (clone $baseQuery)->count(),
            'replied'  => (clone $baseQuery)
                ->whereNotNull('message_out')
                ->where('message_out', '!=', '')
                ->selectRaw('COUNT(DISTINCT COALESCE(session_id, CAST(id AS TEXT)))')
                ->value(DB::raw('COUNT(DISTINCT COALESCE(session_id, CAST(id AS TEXT)))')),
            'whatsapp' => (clone $baseQuery)->where('channel', 'whatsapp')->count(),
            'telegram' => (clone $baseQuery)->where('channel', 'telegram')->count(),
        ];

        // ── Paginate by session: one "page item" = one session thread ──
        //
        // 1. Get the distinct session_ids matching the filters, ordered by the
        //    most recent message in each session.
        // 2. For each session, fetch all its messages in chronological order.
        //
        // Records with no session_id are treated as standalone single-message
        // threads (each is its own "session").

        $sessionQuery = (clone $baseQuery)
            ->select(
                DB::raw('COALESCE(session_id, CAST(id AS TEXT)) AS session_key'),
                DB::raw('MAX(created_at) AS last_activity'),
                DB::raw('MIN(created_at) AS first_activity'),
                DB::raw('COUNT(*) AS message_count'),
                DB::raw('MAX(ai_summary) AS ai_summary'),
            )
            ->groupBy(DB::raw('COALESCE(session_id, CAST(id AS TEXT))'))
            ->orderByDesc('last_activity');

        $sessionPage = $sessionQuery->paginate(10)->withQueryString();

        // Fetch the full messages for each session on this page.
        $sessionKeys = $sessionPage->pluck('session_key')->toArray();

        // Split into real session_ids vs standalone message IDs.
        $realSessionIds = array_filter($sessionKeys, fn ($k) => strlen($k) === 36 && str_contains($k, '-'));
        $standaloneIds  = array_filter($sessionKeys, fn ($k) => ! (strlen($k) === 36 && str_contains($k, '-')));

        $allMessages = $tenant->conversationLogs()
            ->where(function ($q) use ($realSessionIds, $standaloneIds): void {
                if ($realSessionIds) {
                    $q->whereIn('session_id', array_values($realSessionIds));
                }
                if ($standaloneIds) {
                    $q->orWhereIn('id', array_values($standaloneIds));
                }
            })
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn ($log) => $log->session_id ?? (string) $log->id);

        // Build ordered session list preserving the pagination order.
        $sessions = collect($sessionKeys)->map(function ($key) use ($sessionPage, $allMessages) {
            $meta     = $sessionPage->firstWhere('session_key', $key);
            $messages = $allMessages->get($key, collect());

            return [
                'session_key'    => $key,
                'session_id'     => $messages->first()?->session_id,
                'ai_summary'     => $meta?->ai_summary,
                'message_count'  => (int) ($meta?->message_count ?? 0),
                'last_activity'  => $meta?->last_activity,
                'first_activity' => $meta?->first_activity,
                'from_identifier' => $messages->first()?->from_identifier,
                'channel'        => $messages->first()?->channel,
                'has_reply'      => $messages->whereNotNull('message_out')->where('message_out', '!=', '')->isNotEmpty(),
                'messages'       => $messages,
            ];
        });

        return view('conversations.index', [
            'tenant'             => $tenant,
            'sessions'           => $sessions,
            'sessionPage'        => $sessionPage,
            'filters'            => $filters,
            'conversationStats'  => $conversationStats,
        ]);
    }

    /**
     * @return array{search:?string,channel:?string,reply_status:?string,date_from:?string,date_to:?string}
     */
    private function filters(Request $request): array
    {
        $channel     = $request->string('channel')->trim()->toString();
        $replyStatus = $request->string('reply_status')->trim()->toString();
        $search      = trim((string) $request->input('search', ''));
        $dateFrom    = trim((string) $request->input('date_from', ''));
        $dateTo      = trim((string) $request->input('date_to', ''));

        return [
            'search'       => $search !== '' ? $search : null,
            'channel'      => in_array($channel, ['whatsapp', 'telegram'], true) ? $channel : null,
            'reply_status' => in_array($replyStatus, ['replied', 'unreplied'], true) ? $replyStatus : null,
            'date_from'    => $dateFrom !== '' ? $dateFrom : null,
            'date_to'      => $dateTo !== '' ? $dateTo : null,
        ];
    }
}
