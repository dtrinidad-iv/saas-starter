<?php

namespace App\Actions\Admin;

use App\Actions\Admin\Concerns\SearchesText;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SearchUsers
{
    use SearchesText;

    /**
     * Every user (platform admins only), newest first, matching $query in the name
     * or email.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function handle(?string $query): LengthAwarePaginator
    {
        return User::query()
            ->when($query, function (Builder $builder, string $query) {
                $pattern = $this->containsPattern($query);
                $builder->where(fn (Builder $where) => $where
                    ->where('name', 'ilike', $pattern)
                    ->orWhere('email', 'ilike', $pattern));
            })
            ->withCount('organizations')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();
    }
}
