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

        return (new MailMessage)
            ->subject($title)
            ->line($this->recovered ? 'La condición operativa se recuperó.' : 'Se detectó una condición operativa que requiere revisión.')
            ->line('Tipo: '.$this->incident->type)
            ->line('Severidad: '.$this->incident->severity)
            ->line('Mensaje: '.$this->incident->message)
            ->line('Incidente: '.$this->incident->uuid);
    }
}
