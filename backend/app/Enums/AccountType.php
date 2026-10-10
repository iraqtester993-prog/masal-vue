<?php

namespace App\Enums;

enum AccountType: string
{
    case System = 'system';
    case MainAgent = 'main_agent';
    case SubAgent = 'sub_agent';
    case SubBranch = 'sub_branch';
    case Pos = 'pos';

    public function portal(): string
    {
        return match ($this) {
            self::System => 'admin',
            self::Pos => 'pos',
            default => 'agents',
        };
    }

    public function allowsChild(self $child): bool
    {
        return match ($this) {
            self::System => $child === self::MainAgent,
            self::MainAgent => in_array($child, [self::SubAgent, self::Pos], true),
            self::SubAgent => in_array($child, [self::SubBranch, self::Pos], true),
            self::SubBranch => $child === self::Pos,
            self::Pos => false,
        };
    }
}
