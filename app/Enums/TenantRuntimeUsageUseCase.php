<?php

namespace App\Enums;

enum TenantRuntimeUsageUseCase: string
{
    case TelegramChat = 'telegram_chat';
    case InboxTriage = 'inbox_triage';
    case OwnerFollowup = 'owner_followup';
    case ManualRuntimeHook = 'manual_runtime_hook';
    case UnknownRuntime = 'unknown_runtime';

    public function label(): string
    {
        return match ($this) {
            self::TelegramChat => 'Telegram Chat',
            self::InboxTriage => 'Inbox Triage',
            self::OwnerFollowup => 'Owner Follow-up',
            self::ManualRuntimeHook => 'Manual Runtime Hook',
            self::UnknownRuntime => 'Unknown Runtime',
        };
    }
}
