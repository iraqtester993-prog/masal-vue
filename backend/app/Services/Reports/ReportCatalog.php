<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\ManagementAuthority;

class ReportCatalog
{
    public const GROUPS = [
        ['id' => 'sales', 'title' => 'المبيعات والأرباح', 'icon' => 'reports', 'prefixes' => ['sales', 'prices'], 'note' => 'المبيعات والطباعة والأسعار'],
        ['id' => 'wallets', 'title' => 'المحافظ والتمويل', 'icon' => 'wallets', 'prefixes' => ['wallets'], 'note' => 'الأرصدة والتمويل والتحصيل'],
        ['id' => 'inventory', 'title' => 'البطاقات والمخزون', 'icon' => 'inventory', 'prefixes' => ['inventory', 'claims'], 'note' => 'الدفعات والبطاقات والمطالبات'],
        ['id' => 'network', 'title' => 'الوكلاء ونقاط البيع', 'icon' => 'agents', 'prefixes' => ['network'], 'note' => 'شبكة التوزيع وحالة الحسابات'],
        ['id' => 'admin', 'title' => 'المتابعة الإدارية', 'icon' => 'audit', 'prefixes' => ['users', 'audit', 'support', 'operations'], 'note' => 'التغييرات والدعم والإشعارات'],
    ];

    public function schemas(): array
    {
        $schemas = json_decode(file_get_contents(__DIR__.'/schemas.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($schemas as &$schema) {
            foreach ($schema['columns'] as &$column) {
                $context = match ($column['key']) {
                    'status' => 'status',
                    'currency' => 'currency',
                    default => null,
                };
                $context = match ($schema['id'].':'.$column['key']) {
                    'audit:action', 'audit-changes:action', 'operations-security:action' => 'action',
                    'audit:source' => 'portal',
                    'audit-changes:field' => 'field',
                    'audit-changes:before', 'audit-changes:after' => 'change',
                    'network:type', 'network-pos:type', 'network-archive:type' => 'accountType',
                    'inventory-products:kind' => 'productKind',
                    'wallets:service', 'wallets-requests:service', 'wallets-transfers:service', 'wallets-groups:service', 'wallets-ledger:service', 'wallets-ledger:kind' => 'movement',
                    'operations-notifications:target' => 'page',
                    'operations-printing:dailyMode' => 'dailyMode',
                    'operations:key' => 'setting',
                    default => $context,
                };
                if ($context !== null) {
                    $column['label_type'] = $context;
                }
            }
            unset($column);
        }
        unset($schema);

        return $schemas;
    }

    public function allowed(User $actor): array
    {
        $permissions = $actor->membership->permissions();

        return array_values(array_map(function (array $schema) use ($permissions, $actor): array {
            $pos = $actor->membership->account->type->value === 'pos';
            $schema['columns'] = array_values(array_filter($schema['columns'], function (array $column) use ($schema, $permissions, $pos): bool {
                if (isset($column['permission']) && ! in_array($column['permission'], $permissions, true)) {
                    return false;
                }
                if (in_array($column['key'], ['profit', 'margin'], true) && ! $pos && ! in_array('data.cost', $permissions, true)) {
                    return false;
                }

                return ! ($pos && $schema['id'] === 'sales-services' && in_array($column['key'], ['cost', 'profit', 'margin'], true));
            }));

            return $schema;
        }, array_filter($this->schemas(), fn (array $schema): bool => in_array('reports.'.explode('-', $schema['id'])[0], $permissions, true) && $this->canRead($actor, $schema['id'], $permissions))));
    }

    private function canRead(User $actor, string $id, array $permissions): bool
    {
        if (in_array($id, ['users-times', 'operations-security'], true) && $actor->membership->account->type->value !== 'system') {
            return false;
        }
        if ($id === 'operations' && ! app(ManagementAuthority::class)->hasSystemScope($actor)) {
            return false;
        }
        if ($id === 'operations-integrations') {
            return (bool) array_intersect(['digital.view', 'integrations.view'], $permissions);
        }
        $required = match ($id) {
            'sales-services' => ['digital.view'],
            'inventory-reservations' => ['sales.view'],
            'inventory-purchases', 'inventory-orders' => ['inventory.view', 'import.view'],
            'inventory-invoices' => ['inventory.view', 'invoices.view'],
            'inventory-products' => ['inventory.view', 'products.view'],
            'inventory-providers' => ['inventory.view', 'providers.view'],
            'claims-exports', 'claims-requests' => ['claims.view', 'exports.view'],
            'wallets-ledger' => ['wallets.view', 'ledger.view'],
            'wallets-collections' => ['wallets.view', 'invoices.view'],
            'network-archive' => ['account.view', 'agents.archiveView'],
            'network-presence' => ['account.view', 'map.view'],
            'network-categories' => ['account.view', 'posTypes.view'],
            'users-times' => ['security.view', 'security.time'],
            'operations-security' => ['security.view', 'reports.audit'],
            'operations', 'operations-printing' => ['security.view'],
            'operations-notifications' => ['notifications.view'],
            'operations-sessions' => ['staff.view'],
            default => match (explode('-', $id)[0]) {
                'sales' => ['sales.view'], 'inventory' => ['inventory.view'], 'wallets' => ['wallets.view'],
                'claims' => ['claims.view'], 'network' => ['account.view'], 'prices' => ['prices.view'],
                'support' => ['support.view'], 'users' => ['staff.view'], 'audit' => ['reports.audit'],
                default => [],
            },
        };

        return array_diff($required, $permissions) === [];
    }

    public function section(User $actor, string $id): array
    {
        foreach ($this->allowed($actor) as $schema) {
            if ($schema['id'] === $id) {
                return $schema;
            }
        }
        abort(404);
    }
}
