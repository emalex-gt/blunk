<?php

namespace App\Notifications;

use App\Models\OperationalIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationalIncidentNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly OperationalIncident $incident, public readonly bool $recovered = false) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->recovered ? 'Recuperación de monitor Blunk' : 'Alerta operativa Blunk '.$this->incident->severity;

        $mail = (new MailMessage)
            ->subject($title)
            ->line($this->recovered ? 'La condición operativa se recuperó.' : 'Se detectó una condición operativa que requiere revisión.')
            ->line('Tipo: '.$this->incident->type)
            ->line('Severidad: '.$this->incident->severity)
            ->line('Mensaje: '.$this->incident->message);

        if ($this->isFiscalIncident()) {
            if ($this->incident->business?->name) {
                $mail->line('Empresa: '.$this->incident->business->name);
            }
            $environment = data_get($this->incident->metadata, 'environment');
            if (in_array($environment, ['test', 'production', 'unknown'], true)) {
                $mail->line('Ambiente: '.$environment);
            }
        }

        return $mail->line('Incidente: '.$this->incident->uuid);
    }

    private function isFiscalIncident(): bool
    {
        return in_array($this->incident->type, ['fel_stale_intents', 'fel_critical_incidents'], true);
    }
}
