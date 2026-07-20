<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->owns($user, $transaction);
    }

    public function create(User $user): bool
    {
        return $user->isOwner() || ($user->isAdmin() && (bool) $user->branch_id);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $this->owns($user, $transaction);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->owns($user, $transaction);
    }

    protected function owns(User $user, Transaction $transaction): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return $user->isAdmin()
            && $user->branch_id
            && (int) $transaction->branch_id === (int) $user->branch_id;
    }
}
