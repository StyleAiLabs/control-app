<?php

namespace App\Http\Controllers;

use App\Models\ConversationLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_admin && ! $request->user()->tenant) {
            return redirect()->route('admin.index');
        }

        $tenant = $request->user()->tenant()->firstOrFail();
        $filters = $this->filters($request);

        $query = $tenant->conversationLogs()
            ->when($filters['channel'] !== null, fn ($builder) => $builder->where('channel', $filters['channel']))
            ->when($filters['reply_status'] === 'replied', fn ($builder) => $builder->whereNotNull('message_out')->where('message_out', '!=', ''))
            ->when($filters['reply_status'] === 'unreplied', fn ($builder) => $builder->where(function ($inner): void {
                $inner->whereNull('message_out')->orWhere('message_out', '');
            }))
            ->when($filters['date_from'] !== null, fn ($builder) => $builder->whereDate('created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== null, fn ($builder) => $builder->whereDate('created_at', '<=', $filters['date_to']))
            ->when($filters['search'] !== null, function ($builder) use ($filters): void {
                $term = '%'.$filters['search'].'%';

                $builder->where(function ($inner) use ($term): void {
                    $inner->where('from_identifier', 'like', $term)
                        ->orWhere('message_in', 'like', $term)
                        ->orWhere('message_out', 'like', $term)
                        ->orWhere('external_message_id', 'like', $term);
                });
            });

        $conversationStats = [
            'matched' => (clone $query)->count(),
            'replied' => (clone $query)->whereNotNull('message_out')->where('message_out', '!=', '')->count(),
            'whatsapp' => (clone $query)->where('channel', 'whatsapp')->count(),
            'telegram' => (clone $query)->where('channel', 'telegram')->count(),
        ];

        $conversations = $query
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('conversations.index', [
            'tenant' => $tenant,
            'conversations' => $conversations,
            'filters' => $filters,
            'conversationStats' => $conversationStats,
        ]);
    }

    /**
     * @return array{
     *   search:?string,
     *   channel:?string,
     *   reply_status:?string,
     *   date_from:?string,
     *   date_to:?string
     * }
     */
    private function filters(Request $request): array
    {
        $channel = $request->string('channel')->trim()->toString();
        $replyStatus = $request->string('reply_status')->trim()->toString();
        $search = trim((string) $request->input('search', ''));
        $dateFrom = trim((string) $request->input('date_from', ''));
        $dateTo = trim((string) $request->input('date_to', ''));

        return [
            'search' => $search !== '' ? $search : null,
            'channel' => in_array($channel, ['whatsapp', 'telegram'], true) ? $channel : null,
            'reply_status' => in_array($replyStatus, ['replied', 'unreplied'], true) ? $replyStatus : null,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to' => $dateTo !== '' ? $dateTo : null,
        ];
    }
}
