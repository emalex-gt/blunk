<?php

namespace App\Services\System;

use App\Models\OperationalIncident;
use App\Notifications\OperationalIncidentNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class OperationalIncidentNotifier
{
    public function notify(OperationalIncident $incident, bool $recovered = false): void
    {
        $telegramSent = false;
        $token = config('operational_monitor.telegram.token');
        $chatId = config('operational_monitor.telegram.chat_id');

        if ($token && $chatId) {
            try {
                Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $this->telegramText($incident, $recovered),
                    'disable_web_page_preview' => true,
                ])->throw();
                $telegramSent = true;
            } catch (\Throwable $exception) {
                Log::channel('stack')->warning('operational_monitor.telegram_failed', ['incident_id' => $incident->id, 'type' => $incident->type]);
            }
        }

        $recipients = config('operational_monitor.mail_to', []);
        if ($recipients !== [] && (! $telegramSent || ! $recovered)) {
            try {
                Notification::route('mail', $recipients)->notify(new OperationalIncidentNotification($incident, $recovered));
            } catch (\Throwable $exception) {
                Log::channel('stack')->warning('operational_monitor.mail_failed', ['incident_id' => $incident->id, 'type' => $incident->type]);
            }
        }
    }

    private function telegramText(OperationalIncident $incident, bool $recovered): string
    {
        return implode("\n", [
            $recovered ? 'Blunk: condición recuperada' : 'Blunk: alerta operativa '.$incident->severity,
            'Tipo: '.$incident->type,
            'Mensaje: '.$incident->message,
            'Incidente: '.$incident->uuid,
        ]);
    }
}
