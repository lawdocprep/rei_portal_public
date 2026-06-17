<?php
namespace App\Auth;

final class ScopeGuard
{
    public function hasAll(array $granted, array $required): bool
    {
        $set = array_fill_keys($granted, true);
        foreach ($required as $r) if (!isset($set[$r])) return false;
        return true;
    }

    public function hasAny(array $granted, array $anyOf): bool
    {
        $set = array_fill_keys($granted, true);
        foreach ($anyOf as $r) if (isset($set[$r])) return true;
        return false;
    }
}
