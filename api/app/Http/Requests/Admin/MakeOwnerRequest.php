<?php

namespace App\Http\Requests\Admin;

/**
 * Make an existing member of the route's organization Owner. The member is looked up
 * within that organization (MakeMemberOwner), so another organization's user is a 404.
 */
class MakeOwnerRequest extends AdminActionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'user_id' => ['required', 'integer'],
        ];
    }
}
