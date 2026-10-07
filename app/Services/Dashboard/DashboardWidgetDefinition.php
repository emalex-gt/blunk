<?php

namespace App\Services\Dashboard;

final class DashboardWidgetDefinition
{
    /**
     * @param array<int, string> $requiredModules
     * @param array<int, string> $requiredPermissions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $type,
        public readonly string $period,
        public readonly DashboardMetricQuery $query,
        public readonly array $requiredModules = [],
        public readonly array $requiredPermissions = [],
    ) {
    }

    public function isVisibleTo(DashboardContext $context): bool
    {
        return collect($this->requiredModules)->every(fn (string $module) => $context->hasModule($module))
            && collect($this->requiredPermissions)->every(fn (string $permission) => $context->hasPermission($permission));
    }
}
