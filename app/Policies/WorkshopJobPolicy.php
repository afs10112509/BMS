<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkshopJob;

class WorkshopJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isAdmin();
    }

    public function view(User $user, WorkshopJob $workshopJob): bool
    {
        return $this->owns($user, $workshopJob);
    }

    public function create(User $user): bool
    {
        return $user->isOwner() || ($user->isAdmin() && (bool) $user->branch_id);
    }

    public function update(User $user, WorkshopJob $workshopJob): bool
    {
        return $this->owns($user, $workshopJob);
    }

    public function delete(User $user, WorkshopJob $workshopJob): bool
    {
        return $this->owns($user, $workshopJob);
    }

    protected function owns(User $user, WorkshopJob $workshopJob): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return $user->isAdmin()
            && $user->branch_id
            && (int) $workshopJob->branch_id === (int) $user->branch_id;
    }
}
