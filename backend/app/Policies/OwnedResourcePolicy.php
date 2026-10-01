<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OwnedResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->exists;
    }

    public function view(User $user, Model $resource): bool
    {
        return (int) $resource->getAttribute('user_id') === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user, Model $resource): bool
    {
        return $this->view($user, $resource);
    }

    public function delete(User $user, Model $resource): bool
    {
        return $this->view($user, $resource);
    }

    public function restore(User $user, Model $resource): bool
    {
        return $this->view($user, $resource);
    }
}
