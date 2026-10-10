<?php

namespace App\Services\Reports;

use App\Models\User;

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
                    'wallets:service', 'wallets-requests:service', 'wallets-transfers:service', 'wallets-holds:service', 'wallets-groups:service', 'wallets-ledger:service', 'wallets-ledger:kind' => 'movement',
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
            $schema['columns'] = array_values(array_filter($schema['columns'], fn (array $column): bool => ! isset($column['permission']) || in_array($column['permission'], $permissions, true)));
            foreach ($schema['columns'] as &$column) {
                if (in_array($column['key'], ['profit', 'margin'], true) && $actor->membership->account->type->value !== 'pos' && ! in_array('data.cost', $permissions, true)) {
                    $column['protected'] = true;
                    $column['required_permissions'] = ['data.profit', 'data.cost'];
                    $column['reason'] = 'عرض الربح المشتق يتطلب صلاحية الأرباح وتكلفة التحميل.';
                }
            }
            unset($column);

            return $schema;
        }, array_filter($this->schemas(), fn (array $schema): bool => in_array('reports.'.explode('-', $schema['id'])[0], $permissions, true))));
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
