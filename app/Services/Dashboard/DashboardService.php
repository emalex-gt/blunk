<?php

namespace App\Services\Dashboard;

final class DashboardService
{
    /** @param array<string, DashboardWidgetDefinition>|null $registry */
    public function __construct(private readonly ?array $registry = null)
    {
    }

    /** @return array<string, mixed> */
    public function resolve(DashboardContext $context, string $profile = 'owner_simple'): array
    {
        $registry = $this->registry ?? DashboardWidgetRegistry::all();
        $widgets = [];

        foreach (DashboardProfiles::widgetKeys($profile) as $key) {
            $definition = $registry[$key] ?? null;

            if (! $definition || ! $definition->isVisibleTo($context)) {
                continue;
            }

            $widgets[] = [
                'key' => $definition->key,
                'title' => $definition->title,
                'type' => $definition->type,
                'period' => $definition->period,
                'data' => $definition->query->resolve($context, $context->period($definition->period)),
            ];
        }

        return [
            'profile' => $profile,
            'context' => $context->payload(),
            'widgets' => $widgets,
        ];
    }
}
